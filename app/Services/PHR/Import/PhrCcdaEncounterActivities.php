<?php

namespace App\Services\PHR\Import;

use App\Support\PHR\PhrEncounterVocabulary;
use DOMElement;

/**
 * Encounters section: one visit per Encounter Activity. Only encounters that
 * happened are records; a planned one (any mood but EVN) or a negated one is
 * skipped.
 *
 * The document header's encompassingEncounter, when there is one, is the encounter a
 * single-encounter document (a discharge summary, a visit note) describes. It is read
 * by header(): its location, its discharge disposition and its class. An Encounter
 * Activity with the same id is the same visit, and the importer reads the two as one.
 */
final class PhrCcdaEncounterActivities extends PhrCcdaSectionReader
{
    private const array ACTIVITY = ['2.16.840.1.113883.10.20.22.4.49'];

    private const array INDICATION = ['2.16.840.1.113883.10.20.22.4.19'];

    private const array DIAGNOSIS = ['2.16.840.1.113883.10.20.22.4.80'];

    private const array PROBLEM = ['2.16.840.1.113883.10.20.22.4.4'];

    private const string ICD10_CM = '2.16.840.1.113883.6.90';

    /**
     * Fields read from the referenced narrative row when the entry states none, by the
     * column headers (lower case) that name them, in order of preference.
     */
    private const array NARRATIVE_COLUMNS = [
        'chief_complaint' => ['reason', 'reason for visit', 'chief complaint'],
        'assessment' => ['assessment'],
    ];

    /** Diagnosis code systems by OID, as the code system URIs a visit stores. */
    private const array DIAGNOSIS_SYSTEMS = [
        '2.16.840.1.113883.6.90' => 'http://hl7.org/fhir/sid/icd-10-cm',
        '2.16.840.1.113883.6.96' => 'http://snomed.info/sct',
    ];

    /** An Encounter Activity's status, as the FHIR state it says. */
    private const array STATES = [
        'active' => 'in-progress',
        'completed' => 'finished',
        'cancelled' => 'cancelled',
    ];

    public function sectionTemplates(): array
    {
        return ['2.16.840.1.113883.10.20.22.2.22', '2.16.840.1.113883.10.20.22.2.22.1'];
    }

    public function sectionCode(): string
    {
        return '46240-8';
    }

    public function matchesTitle(string $title): bool
    {
        return str_contains($title, 'encounter') || str_contains($title, 'visit');
    }

    public function jobType(): string
    {
        return 'phr_office_visit';
    }

    public function idPrefix(): string
    {
        return 'ccda-visit-';
    }

    public function legacyKind(): string
    {
        return 'encounter';
    }

    public function entries(DOMElement $section): array
    {
        $entries = [];
        foreach ($this->xml->entryElements($section, 'encounter', self::ACTIVITY) as $encounter) {
            if ($this->negated($encounter) || ! in_array($this->xml->attribute($encounter, 'moodCode'), [null, 'EVN'], true)) {
                $entries[] = $this->skipped();

                continue;
            }

            $references = $this->references($encounter);
            $entries[] = $this->entry($encounter, $this->withNarrative($this->record($encounter), $this->xml->narrativeColumns($references)), $references);
        }

        return $entries;
    }

    /**
     * Visit fields an Encounter Activity often leaves to its narrative: no C-CDA
     * template holds an encounter's assessment, and the Indication naming its reason
     * is optional. Where the entry states one, its own words are used.
     *
     * Where it states none, an import never clears what the visit already has (see
     * PhrCcdaImporter::importEntries()): a row the narrative-table reader made, or an
     * earlier reading of the same table, keeps its complaint and assessment.
     */
    public function keptWhenUnstated(): array
    {
        return array_keys(self::NARRATIVE_COLUMNS);
    }

    /**
     * The fields the entry leaves unsaid, from the cells of its narrative row under a
     * header that names them, as PHR's own export (and many vendors') heads them.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $columns  the referenced row's cells by header
     * @return array<string, mixed>
     */
    private function withNarrative(array $record, array $columns): array
    {
        foreach (self::NARRATIVE_COLUMNS as $field => $headers) {
            if (($record[$field] ?? null) !== null) {
                continue;
            }
            foreach ($headers as $header) {
                if (isset($columns[$header])) {
                    $record[$field] = $columns[$header];
                    break;
                }
            }
        }

        return $record;
    }

    public function narrativeRecord(array $cells): array
    {
        return [
            'visit_date' => $cells[0] ?? null,
            'visit_type' => $cells[1] ?? null,
            'provider_name' => $cells[2] ?? null,
            'chief_complaint' => $cells[3] ?? null,
            'assessment' => $cells[4] ?? null,
            'external_id' => $this->narrativeId($cells),
        ];
    }

    /**
     * The document's encompassingEncounter as a visit, or null when it has none.
     *
     * @return array<string, mixed>|null
     */
    public function header(): ?array
    {
        $encounter = $this->xml->first('/*[local-name()="ClinicalDocument"]/*[local-name()="componentOf"]/*[local-name()="encompassingEncounter"]');
        if ($encounter === null) {
            return null;
        }

        $code = $this->xml->child($encounter, 'code');
        $type = $this->xml->codeName($code) ?? $this->xml->codeLabel($code);
        [$started, $ended] = $this->xml->period($this->xml->child($encounter, 'effectiveTime'));
        $responsible = $this->xml->path($encounter, 'responsibleParty', 'assignedEntity');
        if ($responsible === null) {
            foreach ($this->xml->children($encounter, 'encounterParticipant') as $participant) {
                if (in_array($this->xml->attribute($participant, 'typeCode'), ['ATND', 'ADM'], true)) {
                    $responsible = $this->xml->child($participant, 'assignedEntity');
                    break;
                }
            }
        }
        $facility = $this->xml->path($encounter, 'location', 'healthCareFacility');

        return [
            'visit_date' => $started,
            'visit_started_at' => $this->xml->hasTime($started) ? $started : null,
            'visit_ended_at' => $this->xml->hasTime($ended) ? $ended : null,
            'visit_type' => $type,
            'provider_name' => $this->xml->nameText($this->xml->path($responsible, 'assignedPerson', 'name')),
            'facility_name' => $this->xml->nameText($this->xml->path($facility, 'serviceProviderOrganization', 'name')),
            'location' => $this->xml->nameText($this->xml->path($facility, 'location', 'name'))
                ?? $this->xml->codeName($this->xml->child($facility, 'code')),
            'encounter_class' => PhrEncounterVocabulary::classCode($this->xml->codeIn($code, PhrEncounterVocabulary::ACT_CODE_OID)),
            'admit_source_text' => $this->conceptText($this->xml->child($encounter, 'admissionReferralSourceCode')),
            ...$this->dischargeDisposition($this->xml->child($encounter, 'dischargeDispositionCode')),
            'external_id' => $this->externalId($encounter, [$started, $type]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function record(DOMElement $encounter): array
    {
        $code = $this->xml->child($encounter, 'code');
        $type = $this->xml->codeName($code) ?? $this->xml->codeLabel($code);
        [$started, $ended] = $this->xml->period($this->xml->child($encounter, 'effectiveTime'));
        $performer = $this->xml->path($encounter, 'performer', 'assignedEntity');
        $location = null;
        foreach ($this->xml->children($encounter, 'participant') as $participant) {
            if ($this->xml->attribute($participant, 'typeCode') === 'LOC') {
                $location = $this->xml->path($participant, 'participantRole', 'playingEntity');
                break;
            }
        }
        $place = $this->xml->nameText($this->xml->child($location, 'name'));
        $organization = $this->xml->nameText($this->xml->path($performer, 'representedOrganization', 'name'));
        $indication = $this->xml->related($encounter, 'observation', self::INDICATION)[0] ?? null;

        return [
            'visit_date' => $started,
            'visit_started_at' => $this->xml->hasTime($started) ? $started : null,
            'visit_ended_at' => $this->xml->hasTime($ended) ? $ended : null,
            'visit_type' => $type,
            'provider_name' => $this->xml->nameText($this->xml->path($performer, 'assignedPerson', 'name')),
            'provider_specialty' => $this->xml->codeName($this->xml->child($performer, 'code')),
            // The service location, else the performing organization. With both, the
            // location is the unit within that organization.
            'facility_name' => $place !== null && $organization !== null ? $organization : ($place ?? $organization),
            'location' => $place !== null && $organization !== null ? $place : null,
            'chief_complaint' => $this->xml->codeName($this->xml->child($indication, 'value')),
            'icd10_codes' => $this->diagnoses($encounter),
            'encounter_diagnoses' => $this->encounterDiagnoses($encounter),
            'encounter_class' => PhrEncounterVocabulary::classCode($this->xml->codeIn($code, PhrEncounterVocabulary::ACT_CODE_OID)),
            'encounter_state' => self::STATES[(string) $this->xml->attribute($this->xml->child($encounter, 'statusCode'), 'code')] ?? null,
            ...$this->dischargeDisposition($this->xml->child($encounter, 'dischargeDispositionCode')),
            'external_id' => $this->externalId($encounter, [$started, $type]),
        ];
    }

    /**
     * A NUBC discharge status as the FHIR disposition that means the same, with the
     * source's own words. A code with no such disposition keeps only its words.
     *
     * @return array{discharge_disposition: string|null, discharge_disposition_text: string|null}
     */
    private function dischargeDisposition(?DOMElement $code): array
    {
        $disposition = $this->xml->attribute($code, 'codeSystem') === PhrEncounterVocabulary::NUBC_DISCHARGE_OID
            ? PhrEncounterVocabulary::dispositionForNubc($this->xml->attribute($code, 'code'))
            : null;
        $words = $this->xml->edText($this->xml->child($code, 'originalText'));

        return [
            'discharge_disposition' => $disposition,
            'discharge_disposition_text' => $words ?? ($disposition === null ? $this->xml->attribute($code, 'displayName') : null),
        ];
    }

    private function conceptText(?DOMElement $code): ?string
    {
        return $this->xml->edText($this->xml->child($code, 'originalText')) ?? $this->xml->attribute($code, 'displayName');
    }

    /**
     * Every diagnosis on the encounter, by name, with its code when it has one from a
     * system the visit can name, in document order.
     *
     * @return list<array<string, string>>|null
     */
    private function encounterDiagnoses(DOMElement $encounter): ?array
    {
        $diagnoses = [];
        foreach ($this->xml->related($encounter, 'act', self::DIAGNOSIS) as $diagnosis) {
            foreach ($this->xml->related($diagnosis, 'observation', self::PROBLEM) as $problem) {
                $value = $this->xml->child($problem, 'value');
                $name = $this->xml->codeName($value);
                if ($name === null) {
                    continue;
                }
                $system = self::DIAGNOSIS_SYSTEMS[(string) $this->xml->attribute($value, 'codeSystem')] ?? null;
                $code = $system === null ? null : $this->xml->attribute($value, 'code');
                $diagnoses[] = array_filter(['name' => $name, 'code' => $code, 'code_system' => $code === null ? null : $system], static fn (?string $part): bool => $part !== null);
            }
        }

        return $diagnoses === [] ? null : $diagnoses;
    }

    /**
     * The ICD-10-CM codes of the encounter's diagnoses, each with its name.
     *
     * @return list<array{code: string, description: string}>|null
     */
    private function diagnoses(DOMElement $encounter): ?array
    {
        $codes = [];
        foreach ($this->xml->related($encounter, 'act', self::DIAGNOSIS) as $diagnosis) {
            foreach ($this->xml->related($diagnosis, 'observation', self::PROBLEM) as $problem) {
                $value = $this->xml->child($problem, 'value');
                $code = $this->xml->codeIn($value, self::ICD10_CM);
                if ($code !== null) {
                    $codes[] = ['code' => $code, 'description' => $this->xml->codeName($value) ?? ''];
                }
            }
        }

        return $codes === [] ? null : $codes;
    }
}

<?php

namespace App\Services\PHR\Export;

use App\Models\PhrOfficeVisit;
use App\Models\PhrOfficeVisitParticipant;
use App\Support\PHR\PhrEncounterVocabulary;
use App\Support\PHR\PhrVisitParticipants;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use XMLWriter;

/**
 * The Encounters section's structured entries: one Encounter Activity
 * (2.16.840.1.113883.10.20.22.4.49:2015-08-01) per visit, written beside the narrative
 * table it renders. `encompassingEncounter` names a document's single encounter, so a
 * CCD holding many visits carries them here.
 *
 * - `code` is the visit type as original text, null-flavored: PHR keeps no CPT
 *   encounter code. The class is its ActCode translation, with no display name, so a
 *   reader that names an encounter by its code's words never reads the class as a type.
 * - `statusCode` follows the coded state where there is one.
 * - `sdtc:dischargeDispositionCode` is the NUBC patient status that means the stored
 *   FHIR disposition, else the source's words with `nullFlavor="OTH"`.
 * - The performers are the care team (#412), one per member in the visit's order: the
 *   role as `sdtc:functionCode` (a v3 ParticipationType code, PPRF as its translation
 *   for a member primary in another role, the source's label as original text), the
 *   member's period as `time`, the specialty as `sdtc:specialty` and the credentials as
 *   the name's suffix. A visit with no team has one performer, its provider. The
 *   facility is the first performer's represented organization when the visit also
 *   names a unit; the Service Delivery Location (.4.32) is the unit, else the facility.
 *   Its HSLOC code is not stored, so it is null-flavored.
 * - Each encounter diagnosis, and each ICD-10 code the diagnoses do not already name,
 *   is an Encounter Diagnosis (.4.80:2024-05-01) holding a Problem Observation.
 * - The chief complaint is an Indication (.4.19:2023-05-01), SNOMED CT Complaint, whose
 *   null-flavored value's original text references the table's Reason cell.
 * - No C-CDA entry holds an encounter's assessment. It is the table's Assessment cell,
 *   which the importer reads under that header (PhrCcdaEncounterActivities).
 *
 * Ids are version 5 UUIDs keyed to the deployment, like the result entries', so a
 * re-import of the export updates the same visits. Each activity references its table
 * row; its diagnoses reference the visit's item in the list of coded facts that follows
 * the table (narrative()), since the five-column table has no column for them.
 */
final class PhrCcdaEncounterEntries
{
    private const string ID_NAMESPACE = 'a6f4d0c2-5b3e-4e71-9f08-2d7c4b1e6a93';

    private const string ACTIVITY_TEMPLATE = '2.16.840.1.113883.10.20.22.4.49';

    private const string ACTIVITY_VERSION = '2015-08-01';

    private const string DIAGNOSIS_TEMPLATE = '2.16.840.1.113883.10.20.22.4.80';

    private const string PROBLEM_TEMPLATE = '2.16.840.1.113883.10.20.22.4.4';

    private const string DIAGNOSIS_VERSION = '2024-05-01';

    private const string LOCATION_TEMPLATE = '2.16.840.1.113883.10.20.22.4.32';

    private const string INDICATION_TEMPLATE = '2.16.840.1.113883.10.20.22.4.19';

    private const string INDICATION_VERSION = '2023-05-01';

    private const string SDTC = 'urn:hl7-org:sdtc';

    /** Code system URIs a diagnosis may carry, by the OID C-CDA names them with. */
    public const array DIAGNOSIS_SYSTEMS = [
        'http://hl7.org/fhir/sid/icd-10-cm' => '2.16.840.1.113883.6.90',
        'http://snomed.info/sct' => '2.16.840.1.113883.6.96',
    ];

    /** The C-CDA status of an encounter in each FHIR state that has one. */
    private const array STATUS_CODES = [
        'arrived' => 'active',
        'triaged' => 'active',
        'in-progress' => 'active',
        'onleave' => 'active',
        'finished' => 'completed',
        'cancelled' => 'cancelled',
    ];

    private string $idNamespace;

    public function __construct(string $deploymentKey = '')
    {
        $this->idNamespace = $deploymentKey === ''
            ? self::ID_NAMESPACE
            : Uuid::uuid5(self::ID_NAMESPACE, hash_hmac('sha256', 'phr-ccda-encounter-ids', $deploymentKey))->toString();
    }

    /** The `ID` of a visit's narrative row, in visit order. */
    public static function rowId(int $index): string
    {
        return 'encounter-'.($index + 1);
    }

    /** The `ID` of a visit's Reason cell, its chief complaint, in visit order. */
    public static function reasonId(int $index): string
    {
        return self::rowId($index).'-reason';
    }

    /** The `ID` of a visit's narrative list item of coded facts. */
    public static function detailId(int $index): string
    {
        return self::rowId($index).'-detail';
    }

    /**
     * After the table: one list item per visit that states coded facts the table has no
     * column for (class, state, unit, facility, disposition, diagnoses), so the narrative
     * renders what its entries say. The table itself is unchanged.
     *
     * @param  list<object>  $visits  in narrative-row order
     */
    public function narrative(XMLWriter $xml, array $visits): void
    {
        $items = [];
        foreach ($visits as $index => $visit) {
            $facts = $this->facts($visit);
            if ($facts !== []) {
                $items[$index] = ($this->text($visit->visit_type ?? null) ?? 'Visit '.($index + 1)).': '.implode('; ', $facts);
            }
        }
        if ($items === []) {
            return;
        }

        $xml->startElement('list');
        foreach ($items as $index => $item) {
            $xml->startElement('item');
            $xml->writeAttribute('ID', self::detailId($index));
            $xml->text($item);
            $xml->endElement();
        }
        $xml->endElement();
    }

    /** @return list<string> */
    private function facts(object $visit): array
    {
        $class = PhrEncounterVocabulary::classCode($visit->encounter_class ?? null);
        $state = PhrEncounterVocabulary::stateCode($visit->encounter_state ?? null);
        $disposition = PhrEncounterVocabulary::dischargeDispositionCode($visit->discharge_disposition ?? null);
        $dispositionText = $this->text($visit->discharge_disposition_text ?? null) ?? ($disposition === null ? null : PhrEncounterVocabulary::DISCHARGE_DISPOSITIONS[$disposition]);
        $location = $this->text($visit->location ?? null);
        $names = array_map(static fn (array $diagnosis): string => $diagnosis['name'], self::diagnoses($visit));

        return array_values(array_filter([
            $class === null ? null : 'class '.PhrEncounterVocabulary::CLASSES[$class],
            $state === null ? null : 'status '.$state,
            $location === null ? null : 'location '.$location,
            $location === null || ($facility = $this->text($visit->facility_name ?? null)) === null ? null : 'facility '.$facility,
            $dispositionText === null ? null : 'discharged to '.$dispositionText,
            $names === [] ? null : 'diagnoses '.implode(', ', $names),
            ($team = $this->team($visit)) === '' ? null : 'care team '.$team,
        ]));
    }

    /** The care team as the narrative names it: each member, with their role and status. */
    private function team(object $visit): string
    {
        return implode(', ', array_map(static function (array $member): string {
            $notes = array_filter([
                $member['role_label'] ?? ($member['role_code'] === PhrVisitParticipants::OTHER ? null : PhrVisitParticipants::ROLES[$member['role_code']]),
                $member['specialty'],
                $member['is_primary'] ? 'primary' : null,
                $member['period_end'] instanceof CarbonInterface ? 'until '.$member['period_end']->toDateString() : null,
            ]);

            return PhrVisitParticipants::display($member).($notes === [] ? '' : ' ('.implode(', ', $notes).')');
        }, self::members($visit)));
    }

    /**
     * The visit's care team, each member as PhrVisitParticipants lists one with its
     * period as moments.
     *
     * @return list<array<string, mixed>>
     */
    private static function members(object $visit): array
    {
        $members = $visit instanceof PhrOfficeVisit
            ? $visit->participants->map(static fn (PhrOfficeVisitParticipant $participant): array => $participant->toMember())->all()
            : PhrVisitParticipants::normalize($visit->participants ?? []);

        return array_values(array_map(static fn (array $member): array => [
            ...$member,
            'period_start' => is_string($member['period_start']) ? Carbon::parse($member['period_start']) : null,
            'period_end' => is_string($member['period_end']) ? Carbon::parse($member['period_end']) : null,
        ], $members));
    }

    /**
     * @param  list<object>  $visits  in narrative-row order
     */
    public function write(XMLWriter $xml, array $visits): void
    {
        foreach ($visits as $index => $visit) {
            $this->activity($xml, $index, $visit);
        }
    }

    private function activity(XMLWriter $xml, int $index, object $visit): void
    {
        $xml->startElement('entry');
        $xml->writeAttribute('typeCode', 'DRIV');
        $xml->startElement('encounter');
        $xml->writeAttribute('classCode', 'ENC');
        $xml->writeAttribute('moodCode', 'EVN');
        $this->template($xml, self::ACTIVITY_TEMPLATE, self::ACTIVITY_VERSION);
        $this->id($xml, 'encounter', $this->key($visit));
        $this->code($xml, $visit);
        $this->reference($xml, '#'.self::rowId($index));
        $status = self::STATUS_CODES[$this->text($visit->encounter_state ?? null) ?? ''] ?? null;
        if ($status !== null) {
            $xml->startElement('statusCode');
            $xml->writeAttribute('code', $status);
            $xml->endElement();
        }
        $this->effectiveTime($xml, $visit);
        $this->dischargeDisposition($xml, $visit);
        $this->performer($xml, $visit);
        $this->location($xml, $visit);
        $this->indication($xml, $index, $visit);
        foreach (self::diagnoses($visit) as $position => $diagnosis) {
            $this->diagnosis($xml, $index, $position, $visit, $diagnosis);
        }
        $xml->endElement();
        $xml->endElement();
    }

    private function code(XMLWriter $xml, object $visit): void
    {
        $type = $this->text($visit->visit_type ?? null);
        $class = PhrEncounterVocabulary::classCode($visit->encounter_class ?? null);
        $xml->startElement('code');
        $xml->writeAttribute('nullFlavor', $type === null ? 'UNK' : 'OTH');
        if ($type !== null) {
            $xml->writeElement('originalText', $type);
        }
        if ($class !== null) {
            $xml->startElement('translation');
            $xml->writeAttribute('code', $class);
            $xml->writeAttribute('codeSystem', PhrEncounterVocabulary::ACT_CODE_OID);
            $xml->writeAttribute('codeSystemName', 'ActCode');
            $xml->endElement();
        }
        $xml->endElement();
    }

    private function effectiveTime(XMLWriter $xml, object $visit): void
    {
        // An offset would state a zone the source did not record; its date is all that is known.
        $unzonedStart = $visit instanceof PhrOfficeVisit && $visit->startZoneIsUnrecorded();
        $unzonedEnd = $visit instanceof PhrOfficeVisit && $visit->endZoneIsUnrecorded();
        $start = ($unzonedStart ? $this->day($visit->visit_started_at ?? null) : $this->moment($visit->visit_started_at ?? null))
            ?? $this->day($visit->visit_date ?? null);
        $end = $unzonedEnd ? $this->day($visit->visit_ended_at ?? null) : $this->moment($visit->visit_ended_at ?? null);
        $xml->startElement('effectiveTime');
        if ($start === null && $end === null) {
            $xml->writeAttribute('nullFlavor', 'UNK');
        } elseif ($end === null) {
            $xml->writeAttribute('value', (string) $start);
        } else {
            $xml->startElement('low');
            $start === null ? $xml->writeAttribute('nullFlavor', 'UNK') : $xml->writeAttribute('value', $start);
            $xml->endElement();
            $xml->startElement('high');
            $xml->writeAttribute('value', $end);
            $xml->endElement();
        }
        $xml->endElement();
    }

    private function dischargeDisposition(XMLWriter $xml, object $visit): void
    {
        $code = PhrEncounterVocabulary::dischargeDispositionCode($visit->discharge_disposition ?? null);
        $text = $this->text($visit->discharge_disposition_text ?? null);
        $nubc = PhrEncounterVocabulary::nubcForDisposition($code);
        if ($nubc === null && $text === null && $code === null) {
            return;
        }

        $xml->startElementNs('sdtc', 'dischargeDispositionCode', self::SDTC);
        if ($nubc !== null) {
            $xml->writeAttribute('code', $nubc['code']);
            $xml->writeAttribute('codeSystem', PhrEncounterVocabulary::NUBC_DISCHARGE_OID);
            $xml->writeAttribute('codeSystemName', 'NUBC Patient Discharge Status');
            $xml->writeAttribute('displayName', $nubc['display']);
        } else {
            $xml->writeAttribute('nullFlavor', 'OTH');
        }
        $words = $text ?? ($nubc === null && $code !== null ? PhrEncounterVocabulary::DISCHARGE_DISPOSITIONS[$code] : null);
        if ($words !== null) {
            // CE's own parts are in the HL7 namespace, not the extension's.
            $xml->writeElement('originalText', $words);
        }
        $xml->endElement();
    }

    private function performer(XMLWriter $xml, object $visit): void
    {
        $provider = $this->text($visit->provider_name ?? null);
        $facility = $this->text($visit->facility_name ?? null);
        $location = $this->text($visit->location ?? null);
        // The facility is the performer's organization only when the location names the unit.
        $organization = $location !== null ? $facility : null;
        $members = self::members($visit);
        if ($members !== []) {
            foreach ($members as $position => $member) {
                $this->teamMember($xml, $member, $position === 0 ? $organization : null);
            }

            return;
        }
        if ($provider === null && $organization === null) {
            return;
        }

        $xml->startElement('performer');
        $xml->startElement('assignedEntity');
        $xml->startElement('id');
        $xml->writeAttribute('nullFlavor', 'NI');
        $xml->endElement();
        if ($provider !== null) {
            $xml->startElement('assignedPerson');
            $xml->startElement('name');
            $xml->text($provider);
            $xml->endElement();
            $xml->endElement();
        }
        if ($organization !== null) {
            $xml->startElement('representedOrganization');
            $xml->writeElement('name', $organization);
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
    }

    /**
     * One care team member as a performer.
     *
     * @param  array<string, mixed>  $member
     */
    private function teamMember(XMLWriter $xml, array $member, ?string $organization): void
    {
        $xml->startElement('performer');
        $xml->startElementNs('sdtc', 'functionCode', self::SDTC);
        $this->participationCode($xml, $member['role_code']);
        if ($member['role_label'] !== null) {
            $xml->writeElement('originalText', $member['role_label']);
        }
        if ($member['is_primary'] && $member['role_code'] !== PhrVisitParticipants::PRIMARY) {
            $xml->startElement('translation');
            $this->participationCode($xml, PhrVisitParticipants::PRIMARY);
            $xml->endElement();
        }
        $xml->endElement();
        $start = $this->moment($member['period_start']);
        $end = $this->moment($member['period_end']);
        if ($start !== null || $end !== null) {
            $xml->startElement('time');
            $xml->startElement('low');
            $start === null ? $xml->writeAttribute('nullFlavor', 'UNK') : $xml->writeAttribute('value', $start);
            $xml->endElement();
            if ($end !== null) {
                $xml->startElement('high');
                $xml->writeAttribute('value', $end);
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->startElement('assignedEntity');
        $xml->startElement('id');
        $xml->writeAttribute('nullFlavor', 'NI');
        $xml->endElement();
        if ($member['specialty'] !== null) {
            $xml->startElementNs('sdtc', 'specialty', self::SDTC);
            $xml->writeAttribute('nullFlavor', 'OTH');
            $xml->writeElement('originalText', $member['specialty']);
            $xml->endElement();
        }
        $xml->startElement('assignedPerson');
        $xml->startElement('name');
        $xml->text($member['name']);
        if ($member['credentials'] !== null) {
            $xml->writeElement('suffix', $member['credentials']);
        }
        $xml->endElement();
        $xml->endElement();
        if ($organization !== null) {
            $xml->startElement('representedOrganization');
            $xml->writeElement('name', $organization);
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
    }

    private function participationCode(XMLWriter $xml, string $code): void
    {
        $xml->writeAttribute('code', $code);
        $xml->writeAttribute('codeSystem', PhrVisitParticipants::PARTICIPATION_TYPE_OID);
        $xml->writeAttribute('codeSystemName', 'HL7ParticipationType');
        $xml->writeAttribute('displayName', PhrVisitParticipants::ROLES[$code] ?? PhrVisitParticipants::ROLES[PhrVisitParticipants::OTHER]);
    }

    private function location(XMLWriter $xml, object $visit): void
    {
        $place = $this->text($visit->location ?? null) ?? $this->text($visit->facility_name ?? null);
        if ($place === null) {
            return;
        }

        $xml->startElement('participant');
        $xml->writeAttribute('typeCode', 'LOC');
        $xml->startElement('participantRole');
        $xml->writeAttribute('classCode', 'SDLOC');
        $xml->startElement('templateId');
        $xml->writeAttribute('root', self::LOCATION_TEMPLATE);
        $xml->endElement();
        $xml->startElement('code');
        $xml->writeAttribute('nullFlavor', 'UNK');
        $xml->endElement();
        $xml->startElement('playingEntity');
        $xml->writeAttribute('classCode', 'PLC');
        $xml->writeElement('name', $place);
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
    }

    /**
     * The chief complaint as the reason for the visit: an Indication whose value is the
     * complaint's words, held by reference to the table's Reason cell. PHR stores no code
     * for it, so the value is null-flavored, never a guessed code.
     */
    private function indication(XMLWriter $xml, int $index, object $visit): void
    {
        if ($this->text($visit->chief_complaint ?? null) === null) {
            return;
        }

        $xml->startElement('entryRelationship');
        $xml->writeAttribute('typeCode', 'RSON');
        $xml->startElement('observation');
        $xml->writeAttribute('classCode', 'OBS');
        $xml->writeAttribute('moodCode', 'EVN');
        $this->template($xml, self::INDICATION_TEMPLATE, self::INDICATION_VERSION);
        $this->id($xml, 'indication', $this->key($visit));
        $xml->startElement('code');
        $xml->writeAttribute('code', '409586006');
        $xml->writeAttribute('codeSystem', '2.16.840.1.113883.6.96');
        $xml->writeAttribute('codeSystemName', 'SNOMED CT');
        $xml->writeAttribute('displayName', 'Complaint');
        $xml->endElement();
        $this->reference($xml, '#'.self::reasonId($index));
        $this->completed($xml);
        $xml->startElement('effectiveTime');
        $xml->startElement('low');
        $start = $this->day($visit->visit_started_at ?? null) ?? $this->day($visit->visit_date ?? null);
        $start === null ? $xml->writeAttribute('nullFlavor', 'UNK') : $xml->writeAttribute('value', $start);
        $xml->endElement();
        $xml->endElement();
        $xml->startElement('value');
        $xml->writeAttribute('xsi:type', 'CD');
        $xml->writeAttribute('nullFlavor', 'OTH');
        $xml->startElement('originalText');
        $xml->startElement('reference');
        $xml->writeAttribute('value', '#'.self::reasonId($index));
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
    }

    /**
     * @param  array{name: string, code: string|null, code_system: string|null}  $diagnosis
     */
    private function diagnosis(XMLWriter $xml, int $index, int $position, object $visit, array $diagnosis): void
    {
        $key = $this->key($visit).':'.$position;
        $xml->startElement('entryRelationship');
        $xml->writeAttribute('typeCode', 'COMP');
        $xml->startElement('act');
        $xml->writeAttribute('classCode', 'ACT');
        $xml->writeAttribute('moodCode', 'EVN');
        $this->template($xml, self::DIAGNOSIS_TEMPLATE, self::DIAGNOSIS_VERSION);
        $xml->startElement('code');
        $xml->writeAttribute('code', '29308-4');
        $xml->writeAttribute('codeSystem', '2.16.840.1.113883.6.1');
        $xml->writeAttribute('displayName', 'Diagnosis');
        $xml->endElement();
        $this->reference($xml, '#'.self::detailId($index));
        $this->completed($xml);
        $xml->startElement('entryRelationship');
        $xml->writeAttribute('typeCode', 'SUBJ');
        $xml->startElement('observation');
        $xml->writeAttribute('classCode', 'OBS');
        $xml->writeAttribute('moodCode', 'EVN');
        $this->template($xml, self::PROBLEM_TEMPLATE, self::DIAGNOSIS_VERSION);
        $this->id($xml, 'diagnosis', $key);
        $xml->startElement('code');
        $xml->writeAttribute('code', '282291009');
        $xml->writeAttribute('codeSystem', '2.16.840.1.113883.6.96');
        $xml->writeAttribute('displayName', 'Diagnosis');
        $xml->endElement();
        $this->reference($xml, '#'.self::detailId($index));
        $this->completed($xml);
        $xml->startElement('effectiveTime');
        $xml->startElement('low');
        $start = $this->day($visit->visit_started_at ?? null) ?? $this->day($visit->visit_date ?? null);
        $start === null ? $xml->writeAttribute('nullFlavor', 'UNK') : $xml->writeAttribute('value', $start);
        $xml->endElement();
        $xml->endElement();
        $xml->startElement('value');
        $xml->writeAttribute('xsi:type', 'CD');
        $system = $diagnosis['code_system'] === null ? null : (self::DIAGNOSIS_SYSTEMS[$diagnosis['code_system']] ?? null);
        if ($diagnosis['code'] !== null && $system !== null) {
            $xml->writeAttribute('code', $diagnosis['code']);
            $xml->writeAttribute('codeSystem', $system);
            $xml->writeAttribute('displayName', $diagnosis['name']);
        } else {
            // A name with no code from a system C-CDA names: the words, never a guessed code.
            $xml->writeAttribute('nullFlavor', 'OTH');
            $xml->writeElement('originalText', $diagnosis['name']);
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
    }

    /**
     * The visit's encounter diagnoses, then each ICD-10 code none of them names.
     *
     * @return list<array{name: string, code: string|null, code_system: string|null}>
     */
    private static function diagnoses(object $visit): array
    {
        $diagnoses = [];
        $codes = [];
        $text = static fn (mixed $value): ?string => is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
        foreach (is_array($visit->encounter_diagnoses ?? null) ? $visit->encounter_diagnoses : [] as $entry) {
            $name = is_array($entry) ? $text($entry['name'] ?? null) : null;
            if ($name === null) {
                continue;
            }
            $code = $text($entry['code'] ?? null);
            $system = $code === null ? null : $text($entry['code_system'] ?? null);
            $diagnoses[] = ['name' => $name, 'code' => $code, 'code_system' => $system];
            if ($code !== null && $system === 'http://hl7.org/fhir/sid/icd-10-cm') {
                $codes[$code] = true;
            }
        }
        foreach (is_array($visit->icd10_codes ?? null) ? $visit->icd10_codes : [] as $entry) {
            $code = is_array($entry) ? $text($entry['code'] ?? null) : null;
            if ($code === null || isset($codes[$code])) {
                continue;
            }
            $codes[$code] = true;
            $diagnoses[] = ['name' => $text($entry['description'] ?? null) ?? $code, 'code' => $code, 'code_system' => 'http://hl7.org/fhir/sid/icd-10-cm'];
        }

        return $diagnoses;
    }

    /** What identifies the visit: its row, else what it says. Never the database key itself. */
    private function key(object $visit): string
    {
        if (isset($visit->id, $visit->patient_id)) {
            return "{$visit->patient_id}:{$visit->id}";
        }

        return sha1(implode('|', [
            $this->text($visit->visit_type ?? null),
            $this->moment($visit->visit_started_at ?? null) ?? $this->day($visit->visit_date ?? null),
            $this->text($visit->provider_name ?? null),
            $this->text($visit->facility_name ?? null),
        ]));
    }

    private function id(XMLWriter $xml, string $kind, string $key): void
    {
        $xml->startElement('id');
        $xml->writeAttribute('root', Uuid::uuid5($this->idNamespace, "{$kind}:{$key}")->toString());
        $xml->endElement();
    }

    private function reference(XMLWriter $xml, string $target): void
    {
        $xml->startElement('text');
        $xml->startElement('reference');
        $xml->writeAttribute('value', $target);
        $xml->endElement();
        $xml->endElement();
    }

    private function completed(XMLWriter $xml): void
    {
        $xml->startElement('statusCode');
        $xml->writeAttribute('code', 'completed');
        $xml->endElement();
    }

    private function template(XMLWriter $xml, string $root, string $extension): void
    {
        $xml->startElement('templateId');
        $xml->writeAttribute('root', $root);
        $xml->writeAttribute('extension', $extension);
        $xml->endElement();
    }

    /** A time with its offset; a moment at midnight is a day. */
    private function moment(mixed $moment): ?string
    {
        if (! $moment instanceof CarbonInterface) {
            return null;
        }

        return $moment->equalTo($moment->copy()->startOfDay()) ? $moment->format('Ymd') : $moment->format('YmdHisO');
    }

    private function day(mixed $moment): ?string
    {
        return $moment instanceof CarbonInterface ? $moment->format('Ymd') : null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}

<?php

namespace App\Services\PHR\Export;

use App\Models\PhrAllergy;
use App\Models\PhrCondition;
use App\Models\PhrDicomStudy;
use App\Models\PhrDocument;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Models\PhrNegativeAssertion;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientVital;
use App\Models\PhrPortalMessage;
use App\Models\PhrProcedure;
use App\Support\PHR\PhrEncounterVocabulary;
use App\Support\PHR\PhrLabReferenceRangeNotation;
use App\Support\PHR\PhrVisitParticipants;
use Illuminate\Support\Carbon;

class PhrFhirExporter
{
    private const string UCUM_SYSTEM = 'http://unitsofmeasure.org';

    /** Classes of a stay, which may still be under way while it has no end. */
    private const array STAY_CLASSES = ['IMP', 'ACUTE', 'NONAC', 'OBSENC', 'SS', 'HH'];

    /**
     * Stored dose units that are unambiguous UCUM units, mapped to their UCUM code.
     *
     * Deliberately small and exact-match only: a unit missing from this map is still
     * exported as Quantity.unit text, just without a UCUM system/code. Never guess.
     *
     * @var array<string, string>
     */
    private const array UCUM_DOSE_UNITS = [
        'mL' => 'mL',
        'mg' => 'mg',
        'mcg' => 'ug',
        'ug' => 'ug',
    ];

    /** @var array<string, array<int, int>> link type => record id => the exported visit it was made in */
    private array $encounterLinks = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function bundleJson(array $data): string
    {
        /** @var PhrPatient $patient */
        $patient = $data['patient'];
        $this->encounterLinks = is_array($data['encounter_links'] ?? null) ? $data['encounter_links'] : [];
        $entries = [$this->entry($this->patientResource($patient))];

        foreach ($data['lab_results'] as $lab) {
            $entries[] = $this->entry($this->labObservationResource($lab));
        }
        foreach ($data['vitals'] as $vital) {
            $entries[] = $this->entry($this->vitalObservationResource($vital));
        }
        foreach ($data['conditions'] as $condition) {
            $entries[] = $this->entry($this->conditionResource($condition));
        }
        foreach ($data['medications'] as $medication) {
            $entries[] = $this->entry($this->medicationResource($medication));
        }
        foreach ($data['medication_fills'] ?? [] as $fill) {
            $entries[] = $this->entry($this->medicationDispenseResource($fill));
        }
        foreach ($data['procedures'] as $procedure) {
            $entries[] = $this->entry($this->procedureResource($procedure));
        }
        foreach ($data['immunizations'] as $immunization) {
            $entries[] = $this->entry($this->immunizationResource($immunization));
        }
        foreach ($data['allergies'] as $allergy) {
            $entries[] = $this->entry($this->allergyResource($allergy));
        }
        foreach ($data['office_visits'] as $visit) {
            $entries[] = $this->entry($this->encounterResource($visit));
            $entries[] = $this->entry($this->clinicalImpressionResource($visit));
        }
        foreach ($data['portal_messages'] as $message) {
            $entries[] = $this->entry($this->portalMessageResource($message));
        }
        foreach ($data['negative_assertions'] as $assertion) {
            $entries[] = $this->entry($this->negativeAssertionResource($assertion));
        }
        foreach ($data['dicom_studies'] as $study) {
            $entries[] = $this->entry($this->imagingStudyResource($study));
        }
        foreach ($data['documents'] as $document) {
            $entries[] = $this->entry($this->documentReferenceResource($document));
        }

        return json_encode([
            'resourceType' => 'Bundle',
            'type' => 'collection',
            'timestamp' => now()->toIso8601String(),
            'entry' => $entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array{fullUrl: string, resource: array<string, mixed>}
     */
    private function entry(array $resource): array
    {
        return [
            'fullUrl' => 'urn:uuid:'.$resource['resourceType'].'-'.$resource['id'],
            'resource' => $this->stripNulls($resource),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function patientResource(PhrPatient $patient): array
    {
        return [
            'resourceType' => 'Patient',
            'id' => 'phr-patient-'.$patient->id,
            'name' => [['text' => $patient->display_name ?? 'Patient '.$patient->id]],
            'birthDate' => $patient->birth_date?->toDateString(),
            'gender' => $this->fhirGender($patient->sex_at_birth),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function labObservationResource(PhrLabResult $lab): array
    {
        return [
            'resourceType' => 'Observation',
            'id' => 'phr-lab-'.$lab->id,
            'encounter' => $this->encounterReference('lab-result', (int) $lab->id),
            'status' => $lab->result_status ?: 'final',
            'category' => [$this->coding('http://terminology.hl7.org/CodeSystem/observation-category', 'laboratory', 'Laboratory')],
            'code' => $this->textCode($lab->analyte ?? $lab->test_name ?? 'Lab result'),
            'subject' => $this->patientReference($lab->patient_id),
            // FHIR requires a zone on a time. One the source did not record goes out as its date.
            'effectiveDateTime' => $lab->timeZoneIsUnrecorded()
                ? ($lab->result_datetime ?? $lab->collection_datetime)?->toDateString()
                : $this->fhirDateTime($lab->result_datetime ?? $lab->collection_datetime),
            'valueQuantity' => $lab->value_numeric !== null ? [
                'value' => (float) $lab->value_numeric,
                'unit' => $lab->unit,
            ] : null,
            'valueString' => $lab->value_numeric === null ? $lab->valueWithComparator() : null,
            'interpretation' => $lab->abnormal_flag ? [$this->textCode($lab->abnormal_flag)] : null,
            'referenceRange' => ($lab->range_min !== null || $lab->range_max !== null || $lab->reference_range_text !== null) ? [[
                'low' => $lab->range_min !== null ? ['value' => (float) $lab->range_min, 'unit' => $lab->range_unit ?? $lab->unit] : null,
                'high' => $lab->range_max !== null ? ['value' => (float) $lab->range_max, 'unit' => $lab->range_unit ?? $lab->unit] : null,
                'text' => $lab->reference_range_text ?? $this->singleBoundText($lab),
            ]] : null,
        ];
    }

    /**
     * FHIR defines a reference range's low and high as inclusive, and its
     * SimpleQuantity bounds carry no comparator. A single bound whose source
     * said whether it is inclusive therefore also goes out as text ("<5 mg/dL",
     * "<=5 mg/dL"), so an exclusive one is not read as inclusive and a reader
     * keeps the comparator. A bound that said nothing is exported as before.
     */
    private function singleBoundText(PhrLabResult $lab): ?string
    {
        $stated = match (true) {
            $lab->range_min !== null && $lab->range_max !== null => false,
            $lab->range_max !== null => $lab->range_max_inclusive !== null,
            $lab->range_min !== null => $lab->range_min_inclusive !== null,
            default => false,
        };

        return $stated ? PhrLabReferenceRangeNotation::fromBounds($lab) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function vitalObservationResource(PhrPatientVital $vital): array
    {
        return [
            'resourceType' => 'Observation',
            'id' => 'phr-vital-'.$vital->id,
            'encounter' => $this->encounterReference('vital', (int) $vital->id),
            'status' => 'final',
            'category' => [$this->coding('http://terminology.hl7.org/CodeSystem/observation-category', 'vital-signs', 'Vital Signs')],
            'code' => $this->textCode($vital->vital_name ?? 'Vital sign'),
            'subject' => $this->patientReference($vital->patient_id),
            'effectiveDateTime' => $this->fhirDateTime($vital->observed_at ?? $vital->vital_date),
            'valueQuantity' => $vital->value_numeric !== null ? [
                'value' => (float) $vital->value_numeric,
                'unit' => $vital->unit,
            ] : null,
            'valueString' => $vital->value_numeric === null ? $vital->vital_value : null,
            'component' => $vital->value_numeric_secondary !== null ? [[
                'code' => $this->textCode(($vital->vital_name ?? 'Vital sign').' secondary'),
                'valueQuantity' => [
                    'value' => (float) $vital->value_numeric_secondary,
                    'unit' => $vital->secondary_unit,
                ],
            ]] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conditionResource(PhrCondition $condition): array
    {
        return [
            'resourceType' => 'Condition',
            'id' => 'phr-condition-'.$condition->id,
            'subject' => $this->patientReference($condition->patient_id),
            'code' => $this->textCode($condition->name, $condition->icd10_code, 'http://hl7.org/fhir/sid/icd-10-cm'),
            'clinicalStatus' => $this->textCode($condition->clinical_status),
            'verificationStatus' => $this->textCode($condition->verification_status),
            'onsetDateTime' => $this->fhirDate($condition->onset_date),
            'abatementDateTime' => $this->fhirDate($condition->abated_date),
            'note' => $condition->notes ? [['text' => $condition->notes]] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function medicationResource(PhrMedication $medication): array
    {
        return [
            'resourceType' => 'MedicationStatement',
            'id' => 'phr-medication-'.$medication->id,
            'context' => $this->encounterReference('medication', (int) $medication->id),
            'subject' => $this->patientReference($medication->patient_id),
            'status' => $medication->status,
            'medicationCodeableConcept' => $this->textCode($medication->name, $medication->rxnorm_code, 'http://www.nlm.nih.gov/research/umls/rxnorm'),
            'effectivePeriod' => [
                'start' => $this->fhirDate($medication->started_on),
                'end' => $this->fhirDate($medication->ended_on),
            ],
            'dosage' => $medication->dose || $medication->frequency ? [[
                'text' => trim(implode(' ', array_filter([$medication->dose, $medication->dose_unit, $medication->route, $medication->frequency]))),
            ]] : null,
            'reasonCode' => $medication->reason_for_use ? [$this->textCode($medication->reason_for_use)] : null,
        ];
    }

    /**
     * A prescription fill as a MedicationDispense. The drug is coded by NDC (the
     * 11-digit form) and RxNorm; price, prescriber and pharmacy location have no
     * element of their own, so they travel as extensions.
     *
     * @return array<string, mixed>
     */
    private function medicationDispenseResource(PhrMedicationFill $fill): array
    {
        $substances = implode('; ', (array) $fill->substance_names);
        $text = $fill->brand_name ?: ($substances !== '' ? $substances : (string) $fill->drug_as_printed);
        $coding = array_values(array_filter([
            $fill->ndc_11 !== null ? ['system' => 'http://hl7.org/fhir/sid/ndc', 'code' => $fill->ndc_11, 'display' => $text] : null,
            $fill->rxcui ? ['system' => 'http://www.nlm.nih.gov/research/umls/rxnorm', 'code' => $fill->rxcui, 'display' => $text] : null,
        ]));
        $strength = implode('; ', (array) $fill->strengths);
        $quantity = (float) $fill->quantity;
        $money = static fn (?int $cents): ?array => $cents === null ? null : ['value' => $cents / 100, 'currency' => 'USD'];

        return [
            'resourceType' => 'MedicationDispense',
            'id' => 'phr-medication-fill-'.$fill->id,
            'identifier' => $fill->rx_number ? [['system' => 'urn:phr:rx-number', 'value' => $fill->rx_number]] : null,
            'status' => 'completed',
            'subject' => $this->patientReference($fill->patient_id),
            'medicationCodeableConcept' => [
                'text' => trim($text.($strength !== '' ? ' '.$strength : '').($fill->dosage_form ? ' '.$fill->dosage_form : '')),
                ...($coding === [] ? [] : ['coding' => $coding]),
            ],
            'quantity' => ['value' => $quantity, 'unit' => $fill->quantity_unit],
            'daysSupply' => $fill->days_supply !== null
                ? ['value' => $fill->days_supply, 'unit' => 'day', 'system' => self::UCUM_SYSTEM, 'code' => 'd']
                : null,
            'whenHandedOver' => $this->fhirDate($fill->filled_on),
            'location' => $fill->pharmacy_name ? ['display' => trim($fill->pharmacy_name.($fill->pharmacy_location ? ', '.$fill->pharmacy_location : ''))] : null,
            'dosageInstruction' => $fill->directions ? [['text' => $fill->directions]] : null,
            'extension' => array_values(array_filter([
                $fill->paid_cents !== null ? ['url' => 'urn:phr:fhir:paid-amount', 'valueMoney' => $money($fill->paid_cents)] : null,
                $fill->retail_cents !== null ? ['url' => 'urn:phr:fhir:retail-amount', 'valueMoney' => $money($fill->retail_cents)] : null,
                $fill->prescriber_name ? ['url' => 'urn:phr:fhir:prescriber', 'valueString' => $fill->prescriber_name] : null,
                $fill->refills_remaining !== null ? ['url' => 'urn:phr:fhir:refills-remaining', 'valueInteger' => $fill->refills_remaining] : null,
            ])) ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function procedureResource(PhrProcedure $procedure): array
    {
        return [
            'resourceType' => 'Procedure',
            'id' => 'phr-procedure-'.$procedure->id,
            'encounter' => $this->encounterReference('procedure', (int) $procedure->id),
            'subject' => $this->patientReference($procedure->patient_id),
            'status' => $procedure->status,
            'code' => $this->textCode($procedure->name, $procedure->cpt_code, 'http://www.ama-assn.org/go/cpt'),
            'performedDateTime' => $this->fhirDateTime($procedure->performed_at ?? $procedure->performed_on),
            'performer' => $procedure->performer_name ? [['actor' => ['display' => $procedure->performer_name]]] : null,
            'location' => $procedure->facility_name ? ['display' => $procedure->facility_name] : null,
            'reasonCode' => $procedure->reason ? [$this->textCode($procedure->reason)] : null,
            'outcome' => $procedure->outcome ? $this->textCode($procedure->outcome) : null,
            'note' => $procedure->notes ? [['text' => $procedure->notes]] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function immunizationResource(PhrImmunization $immunization): array
    {
        return [
            'resourceType' => 'Immunization',
            'id' => 'phr-immunization-'.$immunization->id,
            'status' => 'completed',
            'patient' => $this->patientReference($immunization->patient_id),
            'vaccineCode' => $this->textCode($immunization->vaccine_name, $immunization->cvx_code, 'http://hl7.org/fhir/sid/cvx'),
            'occurrenceDateTime' => $this->fhirDate($immunization->administered_on),
            'manufacturer' => $immunization->manufacturer ? ['display' => $immunization->manufacturer] : null,
            'lotNumber' => $immunization->lot_number,
            'isSubpotent' => $immunization->administration_status === 'partial' ? true : null,
            'subpotentReason' => $immunization->administration_status === 'partial'
                ? [$this->coding('http://terminology.hl7.org/CodeSystem/immunization-subpotent-reason', 'partial', 'Partial Dose')]
                : null,
            'site' => $immunization->site ? $this->textCode($immunization->site) : null,
            'route' => $immunization->route ? $this->textCode($immunization->route) : null,
            'doseQuantity' => $this->doseQuantity($immunization->dose_amount, $immunization->dose_unit),
            'performer' => $immunization->administered_by ? [['actor' => ['display' => $immunization->administered_by]]] : null,
            'note' => $immunization->notes ? [['text' => $immunization->notes]] : null,
        ];
    }

    /**
     * Immunization.doseQuantity (administered volume/amount), distinct from the series
     * position in protocolApplied.doseNumber. Omitted unless the amount is a plain
     * non-negative decimal; emitted as a JSON number like the exporter's other
     * quantities (the exact text stays on the record).
     *
     * @return array{value: float, unit?: string, system?: string, code?: string}|null
     */
    private function doseQuantity(?string $amount, ?string $unit): ?array
    {
        $amount = trim((string) $amount);
        if (preg_match('/^(?:\d+(?:\.\d+)?|\.\d+)$/', $amount) !== 1) {
            return null;
        }

        $quantity = ['value' => (float) $amount];
        $unit = trim((string) $unit);
        if ($unit !== '') {
            $quantity['unit'] = $unit;
            $ucum = self::UCUM_DOSE_UNITS[$unit] ?? null;
            if ($ucum !== null) {
                $quantity['system'] = self::UCUM_SYSTEM;
                $quantity['code'] = $ucum;
            }
        }

        return $quantity;
    }

    /**
     * @return array<string, mixed>
     */
    private function allergyResource(PhrAllergy $allergy): array
    {
        return [
            'resourceType' => 'AllergyIntolerance',
            'id' => 'phr-allergy-'.$allergy->id,
            'patient' => $this->patientReference($allergy->patient_id),
            'code' => $this->textCode($allergy->substance, $allergy->rxnorm_code, 'http://www.nlm.nih.gov/research/umls/rxnorm'),
            'category' => $allergy->category ? [$allergy->category] : null,
            'criticality' => $allergy->criticality,
            'clinicalStatus' => $this->textCode($allergy->clinical_status),
            'verificationStatus' => $this->textCode($allergy->verification_status),
            'reaction' => $allergy->reaction ? [[
                'manifestation' => [$this->textCode($allergy->reaction)],
                'severity' => $allergy->severity,
            ]] : null,
            'note' => $allergy->notes ? [['text' => $allergy->notes]] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function encounterResource(PhrOfficeVisit $visit): array
    {
        $diagnoses = $this->encounterDiagnoses($visit);
        $participants = $this->encounterParticipants($visit);
        $contained = [...array_column($diagnoses, 'condition'), ...array_merge(...array_column($participants, 'contained'))];
        $hospitalization = array_filter([
            'admitSource' => $this->vocabularyConcept(PhrEncounterVocabulary::ADMIT_SOURCE_SYSTEM, PhrEncounterVocabulary::ADMIT_SOURCES, $visit->admit_source, $visit->admit_source_text),
            'dischargeDisposition' => $this->vocabularyConcept(PhrEncounterVocabulary::DISCHARGE_DISPOSITION_SYSTEM, PhrEncounterVocabulary::DISCHARGE_DISPOSITIONS, $visit->discharge_disposition, $visit->discharge_disposition_text),
        ]);

        return [
            'resourceType' => 'Encounter',
            'id' => 'phr-visit-'.$visit->id,
            'contained' => $contained === [] ? null : $contained,
            'status' => $this->encounterState($visit),
            'class' => $this->encounterClass($visit->encounter_class),
            'subject' => $this->patientReference($visit->patient_id),
            'type' => $visit->visit_type ? [$this->textCode($visit->visit_type)] : null,
            'period' => [
                // FHIR requires a zone on a time. One the source did not record goes out as its date.
                'start' => $this->visitMoment($visit->startZoneIsUnrecorded(), $visit->visit_started_at ?? $visit->visit_date),
                'end' => $this->visitMoment($visit->endZoneIsUnrecorded(), $visit->visit_ended_at),
            ],
            'participant' => $participants !== []
                ? array_column($participants, 'participant')
                : ($visit->provider_name ? [['individual' => ['display' => $visit->provider_name]]] : null),
            'reasonCode' => $visit->chief_complaint ? [$this->textCode($visit->chief_complaint)] : null,
            'diagnosis' => $diagnoses === [] ? null : array_column($diagnoses, 'diagnosis'),
            'hospitalization' => $hospitalization === [] ? null : $hospitalization,
            'location' => $visit->location ? [['location' => ['display' => $visit->location]]] : null,
            'serviceProvider' => $visit->facility_name ? ['display' => $visit->facility_name] : null,
        ];
    }

    /**
     * The visit's state, else what its dates say: a visit that ended, or a visit
     * other than a stay on a day already past, is finished. A stay with no end may
     * still be under way, and a visit with no date says nothing, so those are unknown.
     */
    private function encounterState(PhrOfficeVisit $visit): string
    {
        if (PhrEncounterVocabulary::isState($visit->encounter_state)) {
            return (string) $visit->encounter_state;
        }
        if ($visit->visit_ended_at !== null) {
            return 'finished';
        }
        $began = $visit->visit_started_at ?? $visit->visit_date;
        if ($began === null || in_array($visit->encounter_class, self::STAY_CLASSES, true)) {
            return 'unknown';
        }

        return $began->copy()->startOfDay()->lte(now()) ? 'finished' : 'unknown';
    }

    /**
     * R4 requires a class. A visit that states none says so with a null flavor
     * rather than a guessed ActCode.
     *
     * @return array{system: string, code: string, display: string}
     */
    private function encounterClass(?string $class): array
    {
        if (PhrEncounterVocabulary::isClass($class)) {
            return ['system' => PhrEncounterVocabulary::ACT_CODE_SYSTEM, 'code' => (string) $class, 'display' => PhrEncounterVocabulary::CLASSES[$class]];
        }

        return ['system' => 'http://terminology.hl7.org/CodeSystem/v3-NullFlavor', 'code' => 'UNK', 'display' => 'unknown'];
    }

    /**
     * Each encounter diagnosis as a contained Condition and the diagnosis entry that
     * refers to it, in the visit's order (rank 1 first).
     *
     * @return list<array{condition: array<string, mixed>, diagnosis: array<string, mixed>}>
     */
    private function encounterDiagnoses(PhrOfficeVisit $visit): array
    {
        $diagnoses = [];
        foreach ($visit->encounter_diagnoses ?? [] as $entry) {
            $name = is_scalar($entry['name'] ?? null) ? trim((string) $entry['name']) : '';
            if ($name === '') {
                continue;
            }
            $id = 'diagnosis-'.(count($diagnoses) + 1);
            $code = is_scalar($entry['code'] ?? null) ? (string) $entry['code'] : null;
            $system = is_scalar($entry['code_system'] ?? null) ? (string) $entry['code_system'] : null;
            $use = is_scalar($entry['use'] ?? null) ? (string) $entry['use'] : null;
            $diagnoses[] = [
                'condition' => [
                    'resourceType' => 'Condition',
                    'id' => $id,
                    'category' => [$this->coding('http://terminology.hl7.org/CodeSystem/condition-category', 'encounter-diagnosis', 'Encounter Diagnosis')],
                    'code' => $this->textCode($name, $code, $system),
                    'subject' => $this->patientReference($visit->patient_id),
                ],
                'diagnosis' => [
                    'condition' => ['reference' => '#'.$id, 'display' => $name],
                    'use' => $use === null ? null : ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/diagnosis-role', 'code' => $use]]],
                    'rank' => count($diagnoses) + 1,
                ],
            ];
        }

        return $diagnoses;
    }

    /**
     * Each member of the visit's care team (#412) as an Encounter.participant, in the
     * visit's order, and the contained resources that name them: a Practitioner with the
     * member's name and credentials (as a name suffix), and, for a member with a
     * specialty, a PractitionerRole holding it. The participant's type is the member's
     * ParticipationType code with the source's label as its text, and PPRF beside it for
     * a member who is primary in another role.
     *
     * @return list<array{participant: array<string, mixed>, contained: list<array<string, mixed>>}>
     */
    private function encounterParticipants(PhrOfficeVisit $visit): array
    {
        $participants = [];
        foreach ($visit->participants as $position => $participant) {
            $member = $participant->toMember();
            $id = 'participant-'.($position + 1);
            $display = PhrVisitParticipants::display($member);
            $contained = [[
                'resourceType' => 'Practitioner',
                'id' => $id,
                'name' => [[
                    'text' => $member['name'],
                    'suffix' => $member['credentials'] === null ? null : [$member['credentials']],
                ]],
            ]];
            $individual = '#'.$id;
            if ($member['specialty'] !== null) {
                $contained[] = [
                    'resourceType' => 'PractitionerRole',
                    'id' => $id.'-role',
                    'practitioner' => ['reference' => '#'.$id, 'display' => $display],
                    'specialty' => [['text' => $member['specialty']]],
                ];
                $individual = '#'.$id.'-role';
            }
            $codings = [$this->participationCoding($member['role_code'])];
            if ($member['is_primary'] && $member['role_code'] !== PhrVisitParticipants::PRIMARY) {
                $codings[] = $this->participationCoding(PhrVisitParticipants::PRIMARY);
            }
            $participants[] = [
                'participant' => [
                    'type' => [['coding' => $codings, 'text' => $member['role_label']]],
                    'period' => [
                        'start' => $this->participantMoment($participant->period_start),
                        'end' => $this->participantMoment($participant->period_end),
                    ],
                    'individual' => ['reference' => $individual, 'display' => $display],
                ],
                'contained' => $contained,
            ];
        }

        return $participants;
    }

    /** @return array{system: string, code: string, display: string} */
    private function participationCoding(string $code): array
    {
        return ['system' => PhrVisitParticipants::PARTICIPATION_TYPE_SYSTEM, 'code' => $code, 'display' => PhrVisitParticipants::ROLES[$code] ?? PhrVisitParticipants::ROLES[PhrVisitParticipants::OTHER]];
    }

    /** A member's start or end: a date when it was stated as one (midnight), else a time. */
    private function participantMoment(?Carbon $moment): ?string
    {
        if ($moment === null) {
            return null;
        }

        return $moment->equalTo($moment->copy()->startOfDay()) ? $moment->toDateString() : $this->fhirDateTime($moment);
    }

    /**
     * A code from one of the visit's FHIR vocabularies with the source's words beside
     * it; the words alone when the code is not one of them.
     *
     * @param  array<string, string>  $displays
     * @return array<string, mixed>|null
     */
    private function vocabularyConcept(string $system, array $displays, ?string $code, ?string $text): ?array
    {
        $known = $code !== null && isset($displays[$code]);
        if (! $known && ($text === null || trim($text) === '')) {
            return null;
        }

        return [
            'coding' => $known ? [['system' => $system, 'code' => $code, 'display' => $displays[$code]]] : null,
            'text' => $text !== null && trim($text) !== '' ? $text : null,
        ];
    }

    private function visitMoment(bool $zoneIsUnrecorded, ?Carbon $moment): ?string
    {
        return $zoneIsUnrecorded ? $moment?->toDateString() : $this->fhirDateTime($moment);
    }

    /**
     * @return array<string, mixed>
     */
    private function clinicalImpressionResource(PhrOfficeVisit $visit): array
    {
        return [
            'resourceType' => 'ClinicalImpression',
            'id' => 'phr-visit-impression-'.$visit->id,
            'status' => 'completed',
            'subject' => $this->patientReference($visit->patient_id),
            'encounter' => ['reference' => 'Encounter/phr-visit-'.$visit->id],
            'date' => $this->visitMoment($visit->startZoneIsUnrecorded(), $visit->visit_started_at ?? $visit->visit_date),
            'description' => $visit->chief_complaint,
            'summary' => trim(implode("\n\n", array_filter([$visit->assessment, $visit->plan]))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function portalMessageResource(PhrPortalMessage $message): array
    {
        return [
            'resourceType' => 'Communication',
            'id' => 'phr-portal-message-'.$message->id,
            'status' => 'completed',
            'category' => [$this->textCode('Patient portal message')],
            'subject' => $this->patientReference($message->patient_id),
            'sent' => $this->fhirDateTime($message->message_at),
            'sender' => $message->sender_name ? ['display' => $message->sender_name] : null,
            'recipient' => $message->recipient_name ? [['display' => $message->recipient_name]] : null,
            'topic' => $message->subject ? $this->textCode($message->subject) : null,
            'payload' => [[
                'contentString' => $message->summary ?? $message->raw_text ?? $message->subject ?? 'Portal message',
            ]],
            'note' => $this->notes([$message->clinical_relevance, $message->raw_text]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function negativeAssertionResource(PhrNegativeAssertion $assertion): array
    {
        if ($assertion->assertion_type === 'allergy_absence') {
            return [
                'resourceType' => 'AllergyIntolerance',
                'id' => 'phr-negative-assertion-'.$assertion->id,
                'patient' => $this->patientReference($assertion->patient_id),
                'code' => $this->textCode($assertion->statement, '716186003', 'http://snomed.info/sct'),
                'clinicalStatus' => $this->coding('http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical', 'inactive', 'Inactive'),
                'verificationStatus' => $this->coding('http://terminology.hl7.org/CodeSystem/allergyintolerance-verification', 'refuted', 'Refuted'),
                'recordedDate' => $this->fhirDate($assertion->observed_on),
                'note' => $this->notes([$assertion->scope, $assertion->notes, $assertion->raw_text]),
            ];
        }

        return [
            'resourceType' => 'Observation',
            'id' => 'phr-negative-assertion-'.$assertion->id,
            'status' => 'final',
            'category' => [$this->coding('http://terminology.hl7.org/CodeSystem/observation-category', 'survey', 'Survey')],
            'code' => $this->textCode($assertion->scope ?? $assertion->assertion_type),
            'subject' => $this->patientReference($assertion->patient_id),
            'effectiveDateTime' => $this->fhirDate($assertion->observed_on),
            'valueCodeableConcept' => $this->textCode($assertion->statement),
            'interpretation' => [$this->coding('http://terminology.hl7.org/CodeSystem/v3-ObservationInterpretation', 'NEG', 'Negative')],
            'note' => $this->notes([$assertion->assertion_type, $assertion->notes, $assertion->raw_text]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function imagingStudyResource(PhrDicomStudy $study): array
    {
        return [
            'resourceType' => 'ImagingStudy',
            'id' => 'phr-imaging-'.$study->id,
            'status' => 'available',
            'subject' => $this->patientReference($study->patient_id),
            'started' => $this->fhirDate($study->study_date),
            'identifier' => [['system' => 'urn:dicom:uid', 'value' => $study->study_instance_uid]],
            'description' => $study->description,
            'numberOfSeries' => $study->series_count ?? null,
            'numberOfInstances' => $study->instances_count ?? null,
            'modality' => $study->modalities ? [['code' => $study->modalities]] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentReferenceResource(PhrDocument $document): array
    {
        $encounter = $this->encounterReference('document', (int) $document->id);

        return [
            'resourceType' => 'DocumentReference',
            'id' => 'phr-document-'.$document->id,
            'status' => 'current',
            'subject' => $this->patientReference($document->patient_id),
            'context' => $encounter === null ? null : ['encounter' => [$encounter]],
            'type' => $this->textCode($document->document_type),
            'description' => $document->summary ?? $document->title,
            'content' => [[
                'attachment' => [
                    'contentType' => $document->mime_type,
                    'title' => $document->original_filename ?? $document->title,
                    'url' => $document->storage_path ? 'documents/'.$document->id.'-'.$this->safeFilename($document->original_filename ?? ('document-'.$document->id)) : null,
                    'size' => $document->byte_size,
                    'hash' => $document->file_hash,
                ],
            ]],
        ];
    }

    /**
     * The visit a record was made in, when this export carries that visit.
     *
     * @return array{reference: string}|null
     */
    private function encounterReference(string $type, int $recordId): ?array
    {
        $visitId = $this->encounterLinks[$type][$recordId] ?? null;

        return $visitId === null ? null : ['reference' => 'Encounter/phr-visit-'.$visitId];
    }

    /**
     * @return array{reference: string}
     */
    private function patientReference(int $patientId): array
    {
        return ['reference' => 'Patient/phr-patient-'.$patientId];
    }

    /**
     * @return array{text: string, coding?: array<int, array<string, string>>}
     */
    private function textCode(string $text, ?string $code = null, ?string $system = null): array
    {
        $concept = ['text' => $text];
        if ($code !== null && $code !== '') {
            $concept['coding'] = [[
                'system' => $system ?? 'urn:phr:code',
                'code' => $code,
                'display' => $text,
            ]];
        }

        return $concept;
    }

    /**
     * @return array{coding: array<int, array{system: string, code: string, display: string}>}
     */
    private function coding(string $system, string $code, string $display): array
    {
        return ['coding' => [['system' => $system, 'code' => $code, 'display' => $display]]];
    }

    private function fhirGender(?string $sexAtBirth): ?string
    {
        return match (strtolower((string) $sexAtBirth)) {
            'male', 'm' => 'male',
            'female', 'f' => 'female',
            default => $sexAtBirth ? 'unknown' : null,
        };
    }

    private function fhirDate(mixed $date): ?string
    {
        if ($date instanceof Carbon) {
            return $date->toDateString();
        }

        return $date ? (string) $date : null;
    }

    private function fhirDateTime(mixed $date): ?string
    {
        if ($date instanceof Carbon) {
            return $date->toIso8601String();
        }

        return $date ? (string) $date : null;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, array{text: string}>|null
     */
    private function notes(array $values): ?array
    {
        $notes = [];
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $text = trim((string) $value);
            if ($text !== '') {
                $notes[] = ['text' => $text];
            }
        }

        return $notes === [] ? null : $notes;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function stripNulls(array $value): array
    {
        $clean = [];
        foreach ($value as $key => $item) {
            if ($item === null || $item === [] || $item === '') {
                continue;
            }

            $clean[$key] = is_array($item) ? $this->stripNulls($item) : $item;
        }

        return $clean;
    }

    private function safeFilename(string $filename): string
    {
        $safe = preg_replace('/[^\w.\-]+/', '_', $filename) ?: 'document';

        return trim($safe, '_') ?: 'document';
    }
}

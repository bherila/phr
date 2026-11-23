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
use App\Services\PHR\Documents\PhrDocumentTextReader;
use App\Services\PHR\Import\PhrFhirDocumentReferences;
use App\Support\PHR\PhrClinicalNote;
use App\Support\PHR\PhrDocumentUploadLimits;
use App\Support\PHR\PhrEncounterVocabulary;
use App\Support\PHR\PhrLabReferenceRangeNotation;
use App\Support\PHR\PhrMedicationCategory;
use App\Support\PHR\PhrVisitParticipants;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PhrFhirExporter
{
    private const string UCUM_SYSTEM = 'http://unitsofmeasure.org';

    /** Classes of a stay, which may still be under way while it has no end. */
    private const array STAY_CLASSES = ['IMP', 'ACUTE', 'NONAC', 'OBSENC', 'SS', 'HH'];

    /** A markdown document larger than this carries its text/plain rendition by URL, not inline. */
    private const int MAX_INLINE_TEXT_BYTES = 1024 * 1024;

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
        $exportedDocuments = [];
        foreach ($data['documents'] as $document) {
            $exportedDocuments[(int) $document->id] = true;
        }
        foreach ($data['documents'] as $document) {
            $entries[] = $this->entry($this->documentReferenceResource($document, $exportedDocuments));
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
     * A medication, in its setting (#413). An order for a hospital stay, a clinic or
     * the list the patient went home with is a MedicationRequest (intent order) with
     * its medicationrequest-category; a home medication, or one whose setting is
     * unknown, stays a MedicationStatement, a home one with the statement category
     * system's community. Statuses use each resource's codes. In R4 a statement's
     * category is one CodeableConcept (0..1) and a request's a list (0..*) (#432).
     *
     * @return array<string, mixed>
     */
    private function medicationResource(PhrMedication $medication): array
    {
        $category = $medication->category;
        $dosage = $this->dosage($medication);
        $concept = $this->textCode($medication->name, $medication->rxnorm_code, 'http://www.nlm.nih.gov/research/umls/rxnorm');
        $reason = $medication->reason_for_use ? [$this->textCode($medication->reason_for_use)] : null;
        $encounter = $this->encounterReference('medication', (int) $medication->id);

        if (PhrMedicationCategory::isHomeList($category)) {
            return [
                'resourceType' => 'MedicationStatement',
                'id' => 'phr-medication-'.$medication->id,
                'category' => $category === null ? null : $this->category(PhrMedicationCategory::STATEMENT_SYSTEM, $category),
                'context' => $encounter,
                'subject' => $this->patientReference($medication->patient_id),
                'status' => match ($medication->status) {
                    'on_hold' => 'on-hold',
                    'discontinued' => 'stopped',
                    'completed' => 'completed',
                    default => 'active',
                },
                'medicationCodeableConcept' => $concept,
                'effectivePeriod' => [
                    'start' => $this->fhirDateTime($medication->started_at) ?? $this->fhirDate($medication->started_on),
                    'end' => $this->fhirDateTime($medication->ended_at) ?? $this->fhirDate($medication->ended_on),
                ],
                'dosage' => $dosage === null ? null : [$dosage],
                'reasonCode' => $reason,
            ];
        }

        $start = $this->fhirDateTime($medication->started_at) ?? $this->fhirDate($medication->started_on);
        $end = $this->fhirDateTime($medication->ended_at) ?? $this->fhirDate($medication->ended_on);
        if ($start !== null || $end !== null) {
            $dosage = [...($dosage ?? []), 'timing' => ['repeat' => ['boundsPeriod' => array_filter(['start' => $start, 'end' => $end])]]];
        }

        return [
            'resourceType' => 'MedicationRequest',
            'id' => 'phr-medication-'.$medication->id,
            'status' => match ($medication->status) {
                'on_hold' => 'on-hold',
                'discontinued' => 'stopped',
                'completed' => 'completed',
                default => 'active',
            },
            'intent' => 'order',
            'category' => [$this->category(PhrMedicationCategory::REQUEST_SYSTEM, (string) $category)],
            'medicationCodeableConcept' => $concept,
            'encounter' => $encounter,
            'subject' => $this->patientReference($medication->patient_id),
            'authoredOn' => $start,
            'requester' => $medication->prescriber_name ? ['display' => $medication->prescriber_name] : null,
            'reasonCode' => $reason,
            'dosageInstruction' => $dosage === null ? null : [$dosage],
        ];
    }

    /**
     * One category CodeableConcept.
     *
     * @return array{coding: list<array{system: string, code: string, display: string}>}
     */
    private function category(string $system, string $code): array
    {
        return ['coding' => [['system' => $system, 'code' => $code, 'display' => $code]]];
    }

    /**
     * A medication's dosage: its sig as text, how it is taken, as needed or not, and
     * its dose and rate as quantities where they are plain numbers with a unit.
     *
     * @return array<string, mixed>|null
     */
    private function dosage(PhrMedication $medication): ?array
    {
        $text = trim(implode(' ', array_filter([$medication->dose, $medication->dose_unit, $medication->route, $medication->frequency, $medication->rate ? 'at '.$medication->rate : null])));
        $doseAndRate = array_filter([
            'doseQuantity' => is_numeric($medication->dose) && $medication->dose_unit ? $this->quantity((float) $medication->dose, $medication->dose_unit) : null,
            'rateQuantity' => $this->rateQuantity($medication->rate),
        ]);
        $dosage = array_filter([
            'text' => $text === '' ? null : $text,
            'route' => $medication->route ? ['text' => $medication->route] : null,
            'asNeededCodeableConcept' => $medication->as_needed && $medication->as_needed_reason ? ['text' => $medication->as_needed_reason] : null,
            'asNeededBoolean' => $medication->as_needed !== null && ! ($medication->as_needed && $medication->as_needed_reason) ? $medication->as_needed : null,
            'doseAndRate' => $doseAndRate === [] ? null : [$doseAndRate],
        ], static fn (mixed $value): bool => $value !== null);

        return $dosage === [] ? null : $dosage;
    }

    /**
     * A rate stored as "75 mL/h" as a Quantity; a rate in any other form stays only
     * in the dosage text.
     *
     * @return array<string, mixed>|null
     */
    private function rateQuantity(?string $rate): ?array
    {
        if ($rate === null || preg_match('#^\s*(\d+(?:\.\d+)?)\s*([A-Za-z]+/[A-Za-z]+)\s*$#', $rate, $m) !== 1) {
            return null;
        }

        return ['value' => (float) $m[1], 'unit' => $m[2], 'system' => self::UCUM_SYSTEM, 'code' => $m[2]];
    }

    /**
     * @return array<string, mixed>
     */
    private function quantity(float $value, string $unit): array
    {
        $code = self::UCUM_DOSE_UNITS[$unit] ?? null;

        return ['value' => $value, 'unit' => $unit, ...($code === null ? [] : ['system' => self::UCUM_SYSTEM, 'code' => $code])];
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
     * A document as a US Core DocumentReference (phr#409).
     *
     * - type: the document's own LOINC code, else its type's export default (never
     *   a guessed one), with PHR's type as a second coding and the source's label
     *   as text; a clinical note carries the US Core `clinical-note` category.
     * - author and authenticator are the names the document records. A document
     *   an agent or an AI read wrote carries no authenticator: a person approving
     *   it admitted it to the export, nobody authenticated it as its author.
     * - date is when it was signed (else written); attachment.creation when written.
     * - relatesTo names only a document this export also carries.
     * - Attachment.hash is base64 SHA-1, read from the stored file; with no
     *   readable file it is left out rather than guessed. A markdown document
     *   also carries its text as a text/plain attachment.
     *
     * @param  array<int, true>  $exportedDocuments
     * @return array<string, mixed>
     */
    private function documentReferenceResource(PhrDocument $document, array $exportedDocuments): array
    {
        $encounter = $this->encounterReference('document', (int) $document->id);
        $loinc = PhrClinicalNote::exportLoinc($document);
        $coding = [];
        if ($loinc !== null) {
            $coding[] = ['system' => PhrClinicalNote::LOINC_SYSTEM, 'code' => $loinc];
        }
        $coding[] = ['system' => PhrFhirDocumentReferences::PHR_TYPE_SYSTEM, 'code' => $document->document_type];
        $url = $document->storage_path ? 'documents/'.$document->id.'-'.$this->safeFilename($document->original_filename ?? ('document-'.$document->id)) : null;
        $file = $this->storedFile($document);
        $authored = $this->fhirDateTime($document->authored_at);
        $content = [[
            'attachment' => [
                'contentType' => $document->mime_type,
                'title' => $document->original_filename ?? $document->title,
                'url' => $url,
                'size' => $document->byte_size,
                'hash' => $file['sha1'],
                'creation' => $authored,
            ],
        ]];
        if ($url !== null && PhrDocumentTextReader::normalizedType($document->mime_type) === PhrDocumentUploadLimits::MARKDOWN) {
            $content[] = ['attachment' => $file['text'] !== null ? [
                'contentType' => 'text/plain; charset=utf-8',
                'data' => base64_encode($file['text']),
                'size' => strlen($file['text']),
                'hash' => base64_encode(sha1($file['text'], true)),
                'creation' => $authored,
            ] : [
                'contentType' => 'text/plain; charset=utf-8',
                'url' => $url,
                'size' => $document->byte_size,
                'hash' => $file['sha1'],
                'creation' => $authored,
            ]];
        }
        $relation = $document->relatesTo;
        $relatesTo = $relation !== null && $relation->related_document_id !== null && isset($exportedDocuments[$relation->related_document_id])
            ? [['code' => $relation->relation, 'target' => ['reference' => 'DocumentReference/phr-document-'.$relation->related_document_id]]]
            : null;
        $author = null;
        $contained = null;
        if ($document->author_name !== null) {
            if ($document->author_role !== null) {
                // A contained PractitionerRole carries the role a bare Reference cannot.
                $contained = [['resourceType' => 'PractitionerRole', 'id' => 'author', 'practitioner' => ['display' => $document->author_name], 'code' => [['text' => $document->author_role]]]];
                $author = [['reference' => '#author', 'display' => $document->author_name]];
            } else {
                $author = [['display' => $document->author_name]];
            }
        }

        return [
            'resourceType' => 'DocumentReference',
            'id' => 'phr-document-'.$document->id,
            'contained' => $contained,
            'status' => 'current',
            'docStatus' => $document->doc_status,
            'type' => ['coding' => $coding, 'text' => $document->source_type_label],
            'category' => PhrClinicalNote::isNote($document)
                ? [['coding' => [['system' => PhrFhirDocumentReferences::US_CORE_CATEGORY_SYSTEM, 'code' => 'clinical-note', 'display' => 'Clinical Note']]]]
                : null,
            'subject' => $this->patientReference($document->patient_id),
            'date' => $this->fhirDateTime($document->signed_at ?? $document->authored_at),
            'author' => $author,
            'authenticator' => $document->cosigner_name !== null && ! PhrClinicalNote::isAgentAuthored($document)
                ? ['display' => $document->cosigner_name]
                : null,
            'relatesTo' => $relatesTo,
            'description' => $document->summary ?? $document->title,
            'content' => $content,
            // The visit it was written in (#411) and the moment it describes.
            'context' => $encounter === null && $document->observed_at === null ? null : array_filter([
                'encounter' => $encounter === null ? null : [$encounter],
                'period' => $document->observed_at === null ? null : ['start' => $this->fhirDateTime($document->observed_at)],
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * The stored file's base64 SHA-1 and, for a markdown document no larger than
     * MAX_INLINE_TEXT_BYTES, its text, read through the documents disk's stream.
     * Both are null when there is no readable file.
     *
     * @return array{sha1: string|null, text: string|null}
     */
    private function storedFile(PhrDocument $document): array
    {
        $none = ['sha1' => null, 'text' => null];
        if ($document->storage_disk !== PhrDocument::STORAGE_DISK || ! is_string($document->storage_path)) {
            return $none;
        }
        try {
            $disk = Storage::disk(PhrDocument::STORAGE_DISK);
            if (! $disk->exists($document->storage_path)) {
                return $none;
            }
            $stream = $disk->readStream($document->storage_path);
        } catch (Throwable) {
            return $none;
        }
        if (! is_resource($stream)) {
            return $none;
        }
        $keepText = PhrDocumentTextReader::normalizedType($document->mime_type) === PhrDocumentUploadLimits::MARKDOWN;
        $context = hash_init('sha1');
        $text = '';
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1_048_576);
                if ($chunk === false) {
                    return $none;
                }
                hash_update($context, $chunk);
                if ($keepText && strlen($text) <= self::MAX_INLINE_TEXT_BYTES) {
                    $text .= $chunk;
                }
            }
        } finally {
            fclose($stream);
        }

        return [
            'sha1' => base64_encode(hash_final($context, true)),
            'text' => $keepText && strlen($text) <= self::MAX_INLINE_TEXT_BYTES ? $text : null,
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

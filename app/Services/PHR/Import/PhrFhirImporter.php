<?php

namespace App\Services\PHR\Import;

use App\Models\PhrPatient;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class PhrFhirImporter
{
    public function __construct(private PhrStructuredDataImporter $structuredImporter) {}

    public function importFile(string $path, PhrPatient $patient, int $actorUserId): PhrImportResult
    {
        if (! File::isReadable($path)) {
            throw new InvalidArgumentException("FHIR file is not readable: {$path}");
        }

        $contents = File::get($path);
        $bundle = json_decode($contents, true);
        if (! is_array($bundle)) {
            throw new InvalidArgumentException('FHIR file must be valid JSON.');
        }

        return $this->importBundle($bundle, $patient, $actorUserId);
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    public function importBundle(array $bundle, PhrPatient $patient, int $actorUserId): PhrImportResult
    {
        if (($bundle['resourceType'] ?? null) !== 'Bundle') {
            throw new InvalidArgumentException('FHIR import expects an R4 Bundle resource.');
        }

        $result = new PhrImportResult;
        foreach (($bundle['entry'] ?? []) as $entry) {
            if (! is_array($entry) || ! is_array($entry['resource'] ?? null)) {
                $result->addSkipped();

                continue;
            }

            $result->merge($this->importResource($entry['resource'], $patient, $actorUserId));
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function importResource(array $resource, PhrPatient $patient, int $actorUserId): PhrImportResult
    {
        $resourceType = (string) ($resource['resourceType'] ?? '');
        $externalId = $this->externalId($resource);

        return match ($resourceType) {
            'Observation' => $this->importObservation($resource, $patient, $actorUserId, $externalId),
            'Condition' => $this->structuredImporter->importPayload($patient, $actorUserId, 'phr_problem_list', [[
                'external_id' => $externalId,
                'name' => $this->codeableText($resource['code'] ?? null),
                'icd10_code' => $this->codeForSystem($resource['code'] ?? null, 'icd-10'),
                'snomed_code' => $this->codeForSystem($resource['code'] ?? null, 'snomed'),
                'onset_date' => $resource['onsetDateTime'] ?? $resource['onsetDate'] ?? null,
                'abated_date' => $resource['abatementDateTime'] ?? $resource['abatementDate'] ?? null,
                'clinical_status' => $this->codeableText($resource['clinicalStatus'] ?? null),
                'verification_status' => $this->codeableText($resource['verificationStatus'] ?? null),
                'raw_text' => json_encode($resource),
            ]], ['import_source' => 'fhir']),
            'MedicationStatement' => $this->structuredImporter->importPayload($patient, $actorUserId, 'phr_medication', [[
                'external_id' => $externalId,
                'name' => $this->codeableText($resource['medicationCodeableConcept'] ?? null),
                'status' => $resource['status'] ?? null,
                'started_on' => data_get($resource, 'effectivePeriod.start') ?? $resource['effectiveDateTime'] ?? null,
                'ended_on' => data_get($resource, 'effectivePeriod.end'),
                'dose' => data_get($resource, 'dosage.0.text'),
                'raw_text' => json_encode($resource),
            ]], ['import_source' => 'fhir']),
            'Procedure' => $this->structuredImporter->importPayload($patient, $actorUserId, 'phr_procedure', [[
                'external_id' => $externalId,
                'name' => $this->codeableText($resource['code'] ?? null),
                'cpt_code' => $this->codeForSystem($resource['code'] ?? null, 'cpt'),
                'snomed_code' => $this->codeForSystem($resource['code'] ?? null, 'snomed'),
                'performed_at' => $resource['performedDateTime'] ?? null,
                'performed_on' => $resource['performedDateTime'] ?? $resource['performedDate'] ?? null,
                'status' => $resource['status'] ?? 'completed',
                'raw_text' => json_encode($resource),
            ]], ['import_source' => 'fhir']),
            'Immunization' => $this->structuredImporter->importPayload($patient, $actorUserId, 'phr_immunization', [[
                'external_id' => $externalId,
                'vaccine_name' => $this->codeableText($resource['vaccineCode'] ?? null),
                'cvx_code' => $this->codeForSystem($resource['vaccineCode'] ?? null, 'cvx'),
                'administered_on' => $resource['occurrenceDateTime'] ?? $resource['occurrenceString'] ?? null,
                'manufacturer' => data_get($resource, 'manufacturer.display'),
                'lot_number' => $resource['lotNumber'] ?? null,
                'raw_text' => json_encode($resource),
            ]], ['import_source' => 'fhir']),
            'AllergyIntolerance' => $this->structuredImporter->importPayload($patient, $actorUserId, 'phr_allergy', [[
                'external_id' => $externalId,
                'substance' => $this->codeableText($resource['code'] ?? null),
                'category' => is_array($resource['category'] ?? null) ? implode(',', $resource['category']) : ($resource['category'] ?? null),
                'criticality' => $resource['criticality'] ?? null,
                'clinical_status' => $this->codeableText($resource['clinicalStatus'] ?? null),
                'verification_status' => $this->codeableText($resource['verificationStatus'] ?? null),
                'reaction' => $this->codeableText(data_get($resource, 'reaction.0.manifestation.0')),
                'raw_text' => json_encode($resource),
            ]], ['import_source' => 'fhir']),
            'Encounter', 'ClinicalImpression' => $this->structuredImporter->importPayload($patient, $actorUserId, 'phr_office_visit', [[
                'external_id' => $externalId,
                'visit_date' => data_get($resource, 'period.start') ?? $resource['date'] ?? null,
                'visit_started_at' => data_get($resource, 'period.start'),
                'visit_ended_at' => data_get($resource, 'period.end'),
                'visit_type' => $this->codeableText(data_get($resource, 'type.0')) ?? $this->codeableText($resource['code'] ?? null),
                'provider_name' => data_get($resource, 'participant.0.individual.display') ?? data_get($resource, 'assessor.display'),
                'chief_complaint' => $resource['description'] ?? null,
                'assessment' => data_get($resource, 'summary.text'),
                'raw_text' => json_encode($resource),
            ]], ['import_source' => 'fhir']),
            'DocumentReference' => $this->structuredImporter->importPayload($patient, $actorUserId, 'phr_document', [
                'external_id' => $externalId,
                'title' => $resource['description'] ?? data_get($resource, 'content.0.attachment.title'),
                'document_type' => $this->codeableText($resource['type'] ?? null) ?? 'document_reference',
                'original_filename' => data_get($resource, 'content.0.attachment.title'),
                'mime_type' => data_get($resource, 'content.0.attachment.contentType'),
                'summary' => $resource['description'] ?? null,
                'source' => 'fhir',
            ], ['import_source' => 'fhir']),
            default => new PhrImportResult(skipped: 1),
        };
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function importObservation(array $resource, PhrPatient $patient, int $actorUserId, ?string $externalId): PhrImportResult
    {
        $quantity = is_array($resource['valueQuantity'] ?? null) ? $resource['valueQuantity'] : [];
        $value = $quantity !== []
            ? trim((string) ($quantity['value'] ?? '').' '.(string) ($quantity['unit'] ?? $quantity['code'] ?? ''))
            : ($resource['valueString'] ?? $resource['valueCodeableConcept']['text'] ?? null);

        if ($this->hasCategory($resource, 'vital-signs')) {
            return $this->structuredImporter->importPayload($patient, $actorUserId, 'phr_vital', [[
                'external_id' => $externalId,
                'vital_name' => $this->codeableText($resource['code'] ?? null),
                'observed_at' => $resource['effectiveDateTime'] ?? null,
                'vital_date' => $resource['effectiveDateTime'] ?? null,
                'vital_value' => $value,
                'value_numeric' => $quantity['value'] ?? null,
                'unit' => $quantity['unit'] ?? $quantity['code'] ?? null,
                'raw_text' => json_encode($resource),
            ]], ['import_source' => 'fhir']);
        }

        return $this->structuredImporter->importPayload($patient, $actorUserId, 'phr_lab_result', [[
            'external_id' => $externalId,
            'test_name' => data_get($resource, 'code.text'),
            'analyte' => $this->codeableText($resource['code'] ?? null),
            'observed_at' => $resource['effectiveDateTime'] ?? null,
            'result_datetime' => $resource['issued'] ?? $resource['effectiveDateTime'] ?? null,
            'value' => $value,
            'value_numeric' => $quantity['value'] ?? null,
            'unit' => $quantity['unit'] ?? $quantity['code'] ?? null,
            'range_min' => data_get($resource, 'referenceRange.0.low.value'),
            'range_max' => data_get($resource, 'referenceRange.0.high.value'),
            'reference_range_text' => data_get($resource, 'referenceRange.0.text'),
            'abnormal_flag' => $this->codeableText(data_get($resource, 'interpretation.0')),
            'raw_text' => json_encode($resource),
        ]], ['import_source' => 'fhir']);
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function externalId(array $resource): ?string
    {
        $id = $resource['id'] ?? null;

        return is_string($id) && trim($id) !== ''
            ? (string) ($resource['resourceType'] ?? 'Resource').'/'.trim($id)
            : null;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function hasCategory(array $resource, string $needle): bool
    {
        foreach (($resource['category'] ?? []) as $category) {
            foreach (($category['coding'] ?? []) as $coding) {
                $code = strtolower((string) ($coding['code'] ?? ''));
                $system = strtolower((string) ($coding['system'] ?? ''));
                if (str_contains($code, $needle) || str_contains($system, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function codeableText(mixed $concept): ?string
    {
        if (! is_array($concept)) {
            return is_scalar($concept) ? (string) $concept : null;
        }

        $text = $concept['text'] ?? $concept['display'] ?? data_get($concept, 'coding.0.display') ?? data_get($concept, 'coding.0.code');

        return is_scalar($text) && trim((string) $text) !== '' ? trim((string) $text) : null;
    }

    private function codeForSystem(mixed $concept, string $systemNeedle): ?string
    {
        if (! is_array($concept)) {
            return null;
        }

        foreach (($concept['coding'] ?? []) as $coding) {
            if (! is_array($coding)) {
                continue;
            }

            $system = strtolower((string) ($coding['system'] ?? ''));
            if (str_contains($system, strtolower($systemNeedle)) && isset($coding['code'])) {
                return (string) $coding['code'];
            }
        }

        return null;
    }
}

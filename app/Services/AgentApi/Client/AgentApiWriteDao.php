<?php

namespace App\Services\AgentApi\Client;

use App\DataTransferObjects\AgentApi\ClinicalRecordUpdateData;
use App\DataTransferObjects\AgentApi\ClinicalUpsertData;
use App\DataTransferObjects\AgentApi\DicomUploadFileData;
use App\DataTransferObjects\AgentApi\DocumentUploadData;
use App\DataTransferObjects\AgentApi\HealthLogCreateData;
use App\DataTransferObjects\AgentApi\HealthLogEntryAppendData;
use App\DataTransferObjects\AgentApi\ImportReviewData;
use App\DataTransferObjects\AgentApi\PatientCreateData;
use App\DataTransferObjects\AgentApi\RespiratoryEventBatchData;
use Bherila\McpLaravelBridge\Http\AgentApiTransport;

/** Typed data-access boundary for reusable v1 REST mutations. */
final readonly class AgentApiWriteDao
{
    public function __construct(private AgentApiTransport $transport) {}

    public function patientCreate(PatientCreateData $data): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            'patients',
            json: $data->attributes,
        ));
    }

    public function clinicalUpsert(int $patientId, ClinicalUpsertData $data): AgentClinicalUpsertPayload
    {
        return AgentClinicalUpsertPayload::from($this->transport->send(
            'PUT',
            "patients/{$patientId}/{$data->resource}",
            json: $data->toRequestPayload(),
        ));
    }

    /** @param array<string, mixed> $payload */
    public function medicationFillUpsert(int $patientId, array $payload): AgentClinicalUpsertPayload
    {
        return AgentClinicalUpsertPayload::from($this->transport->send(
            'PUT',
            "patients/{$patientId}/medication-fills",
            json: $payload,
        ));
    }

    public function clinicalRetract(int $patientId, string $resource, int $recordId, string $expectedVersion): AgentApiPayload
    {
        return AgentApiPayload::item(
            $this->transport->send(
                'POST',
                "patients/{$patientId}/{$resource}/{$recordId}/retract",
                json: ['expected_version' => $expectedVersion],
            ),
            ['resource_type', 'patient_id', 'outcome', 'data', 'lifecycle'],
        );
    }

    public function clinicalUpdate(int $patientId, int $recordId, ClinicalRecordUpdateData $data): AgentClinicalUpsertPayload
    {
        return AgentClinicalUpsertPayload::from($this->transport->send(
            'PATCH',
            "patients/{$patientId}/{$data->resource}/{$recordId}",
            json: $data->toRequestPayload(),
        ));
    }

    public function documentUpload(int $patientId, DocumentUploadData $data): AgentDocumentUploadPayload
    {
        return AgentDocumentUploadPayload::from($this->transport->send(
            'POST',
            "patients/{$patientId}/documents",
            multipart: $data->toMultipart(),
        ));
    }

    public function dicomUploadOpen(int $patientId, ?string $rootName): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/dicom/uploads",
            json: ['root_name' => $rootName],
        ), ['resource_type', 'patient_id', 'data', 'limits']);
    }

    public function dicomUploadFile(int $patientId, int $uploadId, DicomUploadFileData $data): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/dicom/uploads/{$uploadId}/files",
            multipart: $data->toMultipart(),
        ), ['resource_type', 'patient_id', 'upload_id', 'result', 'data']);
    }

    public function dicomUploadFinalize(int $patientId, int $uploadId): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/dicom/uploads/{$uploadId}/finalize",
        ), ['resource_type', 'patient_id', 'duplicate_upload', 'data']);
    }

    public function dicomUploadCancel(int $patientId, int $uploadId): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/dicom/uploads/{$uploadId}/cancel",
        ), ['resource_type', 'patient_id', 'data']);
    }

    public function reconciliationApply(int $patientId, string $reconciliation, string $previewDigest): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/reconciliations/{$reconciliation}/apply",
            json: ['preview_digest' => $previewDigest],
        ), ['resource_type', 'patient_id', 'outcome', 'data']);
    }

    /** @param list<string> $formats */
    public function exportCreate(int $patientId, array $formats): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/exports",
            json: ['formats' => $formats],
        ), ['resource_type', 'patient_id', 'outcome', 'data']);
    }

    public function nativeBackupCreate(int $patientId): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/native-backups",
        ), ['resource_type', 'patient_id', 'outcome', 'data']);
    }

    public function importCreate(int $patientId, int $documentId): AgentImportPayload
    {
        return AgentImportPayload::mutation($this->transport->send(
            'POST',
            "patients/{$patientId}/imports",
            json: ['document_id' => $documentId],
        ), 'import_job', 'structured_import', 'client_extraction');
    }

    public function importRetry(int $patientId, int $importId): AgentImportPayload
    {
        return AgentImportPayload::mutation($this->transport->send(
            'POST',
            "patients/{$patientId}/imports/{$importId}/retry",
        ), 'import_job');
    }

    public function importReview(
        int $patientId,
        int $importId,
        int $resultId,
        ImportReviewData $data,
    ): AgentImportPayload {
        return AgentImportPayload::mutation($this->transport->send(
            'POST',
            "patients/{$patientId}/imports/{$importId}/results/{$resultId}/review",
            json: $data->toRequestPayload(),
        ), 'import_result');
    }

    public function healthLogCreate(int $patientId, HealthLogCreateData $data): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/health-logs",
            json: ['external_id' => $data->externalId, ...$data->attributes],
        ), ['resource_type', 'patient_id', 'outcome', 'data']);
    }

    public function healthLogEntryAppend(
        int $patientId,
        int $healthLogId,
        HealthLogEntryAppendData $data,
    ): AgentApiPayload {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/health-logs/{$healthLogId}/entries",
            json: $data->toRequestPayload(),
        ), ['resource_type', 'patient_id', 'health_log_id', 'outcome', 'data']);
    }

    public function respiratoryEventsIngest(int $patientId, RespiratoryEventBatchData $data): AgentApiPayload
    {
        return AgentApiPayload::from($this->transport->send(
            'POST',
            "patients/{$patientId}/respiratory-events/batch",
            json: $data->toRequestPayload(),
        ), ['resource_type', 'patient_id', 'results']);
    }

    /** @param array{kind: string, document_type: string|null, filename: string, content_type: string, byte_size: int, sha256: string|null} $declared */
    public function documentUploadAccessCreate(int $patientId, array $declared): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/documents/upload-access",
            json: array_filter($declared, static fn (mixed $value): bool => $value !== null),
        ), ['resource_type', 'patient_id', 'data']);
    }

    /** @param array<string, mixed> $note the markdown body, its metadata and its external_id */
    public function documentCreateText(int $patientId, array $note): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/documents/text",
            json: $note,
        ), ['resource_type', 'patient_id', 'outcome', 'data']);
    }

    /** @param array<string, mixed> $data the fields to change */
    public function documentUpdate(int $patientId, int $documentId, string $expectedVersion, array $data): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'PATCH',
            "patients/{$patientId}/documents/{$documentId}",
            json: ['expected_version' => $expectedVersion, 'data' => $data],
        ), ['resource_type', 'patient_id', 'outcome', 'version', 'data']);
    }

    public function documentUploadFinalize(int $patientId, string $uploadId): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/documents/uploads/{$uploadId}/finalize",
        ), ['resource_type', 'patient_id', 'outcome', 'data']);
    }

    public function documentUploadCancel(int $patientId, string $uploadId): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/documents/uploads/{$uploadId}/cancel",
        ), ['resource_type', 'patient_id', 'data']);
    }

    public function eobImport(int $patientId, int $documentId): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/eobs/import",
            json: ['document_id' => $documentId],
        ), ['resource_type', 'patient_id', 'outcome', 'data']);
    }

    /** @param array<string, mixed> $payload */
    public function eobUpsert(int $patientId, array $payload): AgentClinicalUpsertPayload
    {
        return AgentClinicalUpsertPayload::from($this->transport->send(
            'PUT',
            "patients/{$patientId}/eobs",
            json: $payload,
        ));
    }

    /** @param array<string, mixed> $payload */
    public function eobUpdate(int $patientId, int $eobId, array $payload): AgentClinicalUpsertPayload
    {
        return AgentClinicalUpsertPayload::from($this->transport->send(
            'PATCH',
            "patients/{$patientId}/eobs/{$eobId}",
            json: $payload,
        ));
    }

    public function eobDelete(int $patientId, int $eobId, string $expectedVersion): AgentApiPayload
    {
        return $this->deletion("patients/{$patientId}/eobs/{$eobId}", $expectedVersion);
    }

    public function medicationFillResolveDuplicate(int $patientId, int $fillId, string $action, string $expectedVersion, ?int $candidateFillId): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/medication-fills/{$fillId}/resolve-duplicate",
            json: array_filter(['action' => $action, 'expected_version' => $expectedVersion, 'candidate_fill_id' => $candidateFillId], static fn (mixed $value): bool => $value !== null),
        ), ['resource_type', 'patient_id', 'outcome', 'data']);
    }

    public function officeVisitLinkRecord(int $patientId, int $visitId, string $resourceType, int $resourceId): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/office-visits/{$visitId}/links",
            json: ['resource_type' => $resourceType, 'resource_id' => $resourceId],
        ), ['resource_type', 'patient_id', 'outcome', 'data']);
    }

    /** @param  list<array{resource_type: string, resource_id: int}>  $links */
    public function officeVisitLinkRecords(int $patientId, int $visitId, array $links): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/office-visits/{$visitId}/links/batch",
            json: ['links' => $links],
        ), ['resource_type', 'patient_id', 'data']);
    }

    public function officeVisitUnlinkRecord(int $patientId, int $visitId, string $resourceType, int $resourceId): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'DELETE',
            "patients/{$patientId}/office-visits/{$visitId}/links/{$resourceType}/{$resourceId}",
        ), ['resource_type', 'patient_id', 'outcome', 'data']);
    }

    public function episodeLinkEncounter(int $patientId, int $episodeId, int $visitId): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/episodes/{$episodeId}/encounters",
            json: ['office_visit_id' => $visitId],
        ), ['resource_type', 'patient_id', 'outcome', 'data']);
    }

    public function episodeUnlinkEncounter(int $patientId, int $episodeId, int $visitId): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'DELETE',
            "patients/{$patientId}/episodes/{$episodeId}/encounters/{$visitId}",
        ), ['resource_type', 'patient_id', 'outcome', 'data']);
    }

    public function medicationFillAnswerEobLink(int $patientId, int $fillId, string $action, ?int $eobId): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'POST',
            "patients/{$patientId}/medication-fills/{$fillId}/eob-link",
            json: array_filter(['action' => $action, 'eob_id' => $eobId], static fn (mixed $value): bool => $value !== null),
        ), ['resource_type', 'patient_id', 'outcome', 'data']);
    }

    /** @param array<string, mixed> $data the fields to change */
    public function patientUpdate(int $patientId, string $expectedVersion, array $data): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'PATCH',
            "patients/{$patientId}",
            json: ['expected_version' => $expectedVersion, 'data' => $data],
        ), ['resource_type', 'patient_id', 'outcome', 'version', 'data']);
    }

    public function clinicalDelete(int $patientId, string $resource, int $recordId, string $expectedVersion): AgentApiPayload
    {
        return $this->deletion("patients/{$patientId}/{$resource}/{$recordId}", $expectedVersion);
    }

    public function documentDelete(int $patientId, int $documentId, string $expectedVersion): AgentApiPayload
    {
        return $this->deletion("patients/{$patientId}/documents/{$documentId}", $expectedVersion);
    }

    /** @param array<string, mixed> $data the fields to change */
    public function healthLogUpdate(int $patientId, int $healthLogId, string $expectedVersion, array $data): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'PATCH',
            "patients/{$patientId}/health-logs/{$healthLogId}",
            json: ['expected_version' => $expectedVersion, 'data' => $data],
        ), ['resource_type', 'patient_id', 'outcome', 'version', 'data']);
    }

    public function healthLogDelete(int $patientId, int $healthLogId, string $expectedVersion): AgentApiPayload
    {
        return $this->deletion("patients/{$patientId}/health-logs/{$healthLogId}", $expectedVersion);
    }

    /** @param array<string, mixed> $data the fields to change, in their wire shape */
    public function healthLogEntryUpdate(int $patientId, int $healthLogId, int $entryId, string $expectedVersion, array $data): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'PATCH',
            "patients/{$patientId}/health-logs/{$healthLogId}/entries/{$entryId}",
            json: ['expected_version' => $expectedVersion, 'data' => $data],
        ), ['resource_type', 'patient_id', 'health_log_id', 'outcome', 'version', 'data']);
    }

    public function healthLogEntryDelete(int $patientId, int $healthLogId, int $entryId, string $expectedVersion): AgentApiPayload
    {
        return $this->deletion("patients/{$patientId}/health-logs/{$healthLogId}/entries/{$entryId}", $expectedVersion);
    }

    /** @param list<string> $uuids */
    public function respiratoryEventsDelete(int $patientId, array $uuids): AgentApiPayload
    {
        return AgentApiPayload::from($this->transport->send(
            'DELETE',
            "patients/{$patientId}/respiratory-events/batch",
            json: ['uuids' => $uuids],
        ), ['resource_type', 'patient_id', 'deleted', 'results']);
    }

    /** Every single-record delete answers with the tombstone it left. */
    private function deletion(string $path, string $expectedVersion): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send(
            'DELETE',
            $path,
            json: ['expected_version' => $expectedVersion],
        ), ['resource_type', 'patient_id', 'outcome', 'data']);
    }
}

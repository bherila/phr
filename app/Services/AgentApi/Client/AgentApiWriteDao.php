<?php

namespace App\Services\AgentApi\Client;

use App\DataTransferObjects\AgentApi\ClinicalRecordUpdateData;
use App\DataTransferObjects\AgentApi\ClinicalUpsertData;
use App\DataTransferObjects\AgentApi\DocumentUploadData;
use App\DataTransferObjects\AgentApi\HealthLogCreateData;
use App\DataTransferObjects\AgentApi\HealthLogEntryAppendData;
use App\DataTransferObjects\AgentApi\ImportReviewData;
use App\DataTransferObjects\AgentApi\RespiratoryEventBatchData;

/** Typed data-access boundary for reusable v1 REST mutations. */
final readonly class AgentApiWriteDao
{
    public function __construct(private AgentApiTransport $transport) {}

    public function clinicalUpsert(int $patientId, ClinicalUpsertData $data): AgentClinicalUpsertPayload
    {
        return AgentClinicalUpsertPayload::from($this->transport->send(
            'PUT',
            "patients/{$patientId}/{$data->resource}",
            json: $data->toRequestPayload(),
        ));
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

    public function importCreate(int $patientId, int $documentId): AgentImportPayload
    {
        return AgentImportPayload::mutation($this->transport->send(
            'POST',
            "patients/{$patientId}/imports",
            json: ['document_id' => $documentId],
        ), 'import_job');
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
}

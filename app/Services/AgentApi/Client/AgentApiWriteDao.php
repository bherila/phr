<?php

namespace App\Services\AgentApi\Client;

use App\DataTransferObjects\AgentApi\ClinicalUpsertData;
use App\DataTransferObjects\AgentApi\DocumentUploadData;
use App\DataTransferObjects\AgentApi\ImportReviewData;

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
}

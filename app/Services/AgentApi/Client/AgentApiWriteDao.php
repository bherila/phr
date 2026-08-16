<?php

namespace App\Services\AgentApi\Client;

use App\DataTransferObjects\AgentApi\ClinicalUpsertData;
use App\DataTransferObjects\AgentApi\DocumentUploadData;

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
}

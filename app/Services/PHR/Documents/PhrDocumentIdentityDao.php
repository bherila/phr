<?php

namespace App\Services\PHR\Documents;

use App\DataTransferObjects\PHR\DocumentUploadData;
use App\Models\PhrDocument;
use App\Models\PhrPatient;

/** Typed persistence boundary for client-scoped document identities. */
final class PhrDocumentIdentityDao
{
    public function find(PhrPatient $patient, DocumentUploadData $data): ?PhrDocument
    {
        if ($data->importSource === null || $data->externalId === null) {
            return null;
        }

        return PhrDocument::withTrashed()
            ->where('patient_id', $patient->id)
            ->where('import_source', $data->importSource)
            ->where('external_id', $data->externalId)
            ->first();
    }

    public function findDuplicate(PhrPatient $patient, DocumentUploadData $data, string $hash): ?PhrDocument
    {
        if ($data->importSource === null) {
            return null;
        }

        return PhrDocument::query()
            ->where('patient_id', $patient->id)
            ->where('import_source', $data->importSource)
            ->where('file_hash', $hash)
            ->first();
    }
}

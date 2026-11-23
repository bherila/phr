<?php

namespace App\Services\PHR\Documents;

use App\Models\PhrDocument;
use App\Models\PhrPatient;

/** Typed persistence boundary for client-scoped document identities. */
final class PhrDocumentIdentityDao
{
    public function find(PhrPatient $patient, ?string $importSource, ?string $externalId): ?PhrDocument
    {
        if ($importSource === null || $externalId === null) {
            return null;
        }

        return PhrDocument::withTrashed()
            ->where('patient_id', $patient->id)
            ->where('import_source', $importSource)
            ->where('external_id', $externalId)
            ->first();
    }

    public function findDuplicate(PhrPatient $patient, ?string $importSource, string $hash): ?PhrDocument
    {
        if ($importSource === null) {
            return null;
        }

        return PhrDocument::query()
            ->where('patient_id', $patient->id)
            ->where('import_source', $importSource)
            ->where('file_hash', $hash)
            ->first();
    }
}

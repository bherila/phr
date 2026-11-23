<?php

namespace App\Services\PHR\Documents;

use App\Models\PhrDocument;

/**
 * A document's clinical note metadata (phr#409) as the browser and the agent API
 * show it. Each caller formats moments its own way.
 */
final class PhrDocumentNotePayload
{
    /**
     * @param  callable(\DateTimeInterface|null): (string|null)  $moment
     * @return array<string, mixed>
     */
    public static function of(PhrDocument $document, callable $moment): array
    {
        return [
            'loinc_code' => $document->loinc_code,
            'source_type_label' => $document->source_type_label,
            'author_name' => $document->author_name,
            'author_role' => $document->author_role,
            'cosigner_name' => $document->cosigner_name,
            'authored_at' => $moment($document->authored_at),
            'signed_at' => $moment($document->signed_at),
            'doc_status' => $document->doc_status,
            'patient_signature_status' => $document->patient_signature_status,
            'patient_signed_at' => $moment($document->patient_signed_at),
            'relates_to' => PhrDocumentRelations::payload($document),
        ];
    }
}

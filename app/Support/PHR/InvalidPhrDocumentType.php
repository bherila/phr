<?php

namespace App\Support\PHR;

use App\Models\PhrDocument;
use DomainException;

/**
 * A document was about to be stored with a type outside
 * PhrDocument::DOCUMENT_TYPES.
 *
 * This is a programming error in the writer, not bad user input: request input
 * is validated against the list and imported values are normalized first.
 */
final class InvalidPhrDocumentType extends DomainException
{
    public static function for(mixed $type): self
    {
        $shown = match (true) {
            $type === null => 'null',
            is_string($type) => '"'.mb_substr($type, 0, 80).'"',
            default => get_debug_type($type),
        };

        return new self(sprintf(
            'Refusing to store a PHR document with document_type %s; expected one of: %s.',
            $shown,
            implode(', ', PhrDocument::DOCUMENT_TYPES),
        ));
    }
}

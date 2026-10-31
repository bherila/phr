<?php

namespace App\Support\PHR;

use App\Models\PhrDocument;
use DomainException;

/**
 * A document was about to be stored with a source outside PhrDocument::SOURCES.
 *
 * This is a programming error in the writer, not bad user input: request input
 * is validated against the list and imported values are normalized first.
 */
final class InvalidPhrDocumentSource extends DomainException
{
    public static function for(mixed $source): self
    {
        $shown = match (true) {
            $source === null => 'null',
            is_string($source) => '"'.mb_substr($source, 0, 80).'"',
            default => get_debug_type($source),
        };

        return new self(sprintf(
            'Refusing to store a PHR document with source %s; expected one of: %s.',
            $shown,
            implode(', ', PhrDocument::SOURCES),
        ));
    }
}

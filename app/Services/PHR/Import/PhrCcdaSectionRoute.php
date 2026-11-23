<?php

namespace App\Services\PHR\Import;

/**
 * Where PhrCcdaSectionCatalog sends one C-CDA section: to a section reader, to
 * the Results reader, to nothing because it is a known kind no reader handles
 * (`unread` names it), or to nothing because nothing names it at all.
 */
final readonly class PhrCcdaSectionRoute
{
    private function __construct(
        public ?PhrCcdaSectionReader $reader = null,
        public bool $results = false,
        public ?string $unread = null,
    ) {}

    public static function reader(PhrCcdaSectionReader $reader): self
    {
        return new self(reader: $reader);
    }

    public static function results(): self
    {
        return new self(results: true);
    }

    /** A known section no reader handles, by its catalog name. */
    public static function unread(string $name): self
    {
        return new self(unread: $name);
    }

    /** A section with no code PHR knows and a title no reader matches. */
    public static function unknown(): self
    {
        return new self;
    }
}

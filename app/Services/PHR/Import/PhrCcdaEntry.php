<?php

namespace App\Services\PHR\Import;

/**
 * One structured entry a C-CDA section reader found.
 *
 * - `jobType` and `record`: what to import. `jobType` is the section's own
 *   record type, or `phr_negative_assertion` for a negated entry ("no known
 *   allergies"). A null `record` is an entry nothing names.
 * - `narrativeCells`: the narrative-table row the entry references, when it
 *   references one. An entry nothing names is read from that row instead, the
 *   way the narrative-table reader would, so the structured path never imports
 *   fewer records than that reader.
 * - `narrativeId` and `sourceIds`: ids an earlier import may have given the same
 *   fact, for PhrCcdaLegacyRows. `narrativeId` is the narrative-table reader's id
 *   for that row; `sourceIds` are the entry's own id as `root|extension` and as
 *   the extension alone.
 * - `skip`: an entry of the section's kind that is deliberately not a record
 *   (for example a planned immunization). It still counts as a structured entry,
 *   so the section does not fall back to its narrative table.
 */
final readonly class PhrCcdaEntry
{
    /**
     * @param  array<string, mixed>|null  $record
     * @param  list<string>|null  $narrativeCells
     * @param  list<string>  $sourceIds
     */
    public function __construct(
        public string $jobType,
        public ?array $record,
        public ?array $narrativeCells = null,
        public ?string $narrativeId = null,
        public array $sourceIds = [],
        public bool $skip = false,
    ) {}
}

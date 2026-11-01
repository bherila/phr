<?php

namespace App\Services\PHR\Import;

use DOMElement;

/**
 * Reads one kind of C-CDA section from its structured entries.
 *
 * The narrative table is a rendering whose columns differ by vendor, so reading
 * it by position puts values in the wrong fields (a "Noted Date" column read as
 * an ICD-10 code, a "Next Due" column as a lot number). The structured entries
 * carry every field explicitly.
 *
 * The importer reads a section from its entries when it has any of the reader's
 * kind, and from its narrative table, by position, only when it has none (PHR's
 * own export, for one, carries tables without entries). Both readings live here,
 * so a section's two id shapes cannot drift apart: an entry recomputes the id
 * the narrative-table reader gave its row, and PhrCcdaLegacyRows moves that row
 * onto the entry's id on re-import.
 */
abstract class PhrCcdaSectionReader
{
    public const string NEGATIVE_ASSERTION = 'phr_negative_assertion';

    public function __construct(protected PhrCcdaXml $xml) {}

    /**
     * The section's template roots, entries-optional and entries-required.
     *
     * @return list<string>
     */
    abstract public function sectionTemplates(): array;

    /**
     * Whether a section with no recognized template is this kind, by its title.
     */
    abstract public function matchesTitle(string $title): bool;

    /** The record type the section's entries import as. */
    abstract public function jobType(): string;

    /** The external-id prefix both readings give this section's records. */
    abstract public function idPrefix(): string;

    /** The kind an earlier load put in its ids, `<label>:<kind>:<key>`. */
    abstract public function legacyKind(): string;

    /**
     * Every structured entry of this kind in the section.
     *
     * @return list<PhrCcdaEntry>
     */
    abstract public function entries(DOMElement $section): array;

    /**
     * The record the narrative-table reader makes of a table row, by position.
     *
     * @param  list<string>  $cells
     * @return array<string, string|null>
     */
    abstract public function narrativeRecord(array $cells): array;

    /**
     * @param  list<string>  $cells
     */
    public function narrativeId(array $cells): string
    {
        return PhrCcdaNarrativeRow::externalId($this->idPrefix(), $cells);
    }

    /**
     * @param  array<string, mixed>|null  $record
     * @param  list<DOMElement|null>  $references  elements whose `<reference>` may point at the entry's table row
     */
    protected function entry(DOMElement $element, ?array $record, array $references): PhrCcdaEntry
    {
        $cells = $this->xml->narrativeCells($references);

        return new PhrCcdaEntry(
            jobType: $this->jobType(),
            record: $record,
            narrativeCells: $cells,
            narrativeId: $cells === null ? null : $this->narrativeId($cells),
            sourceIds: $this->xml->sourceIds($element),
        );
    }

    /**
     * An entry stating that something is absent or was not done: a negative
     * assertion, never a record of the thing it negates.
     */
    protected function negation(DOMElement $element, string $assertionType, string $scope, string $statement, ?string $observedOn): PhrCcdaEntry
    {
        return new PhrCcdaEntry(self::NEGATIVE_ASSERTION, [
            'assertion_type' => $assertionType,
            'statement' => $statement,
            'scope' => $scope,
            'observed_on' => $observedOn,
            'external_id' => 'ccda-negation-'.sha1(implode('|', [$this->jobType(), ...$this->xml->identity($element, [$statement, $observedOn])])),
        ]);
    }

    protected function skipped(): PhrCcdaEntry
    {
        return new PhrCcdaEntry($this->jobType(), null, skip: true);
    }

    /**
     * @param  list<string|null>  $content  what identifies the entry when it has no id
     */
    protected function externalId(DOMElement $element, array $content): string
    {
        return $this->idPrefix().sha1(implode('|', $this->xml->identity($element, $content)));
    }

    protected function negated(DOMElement $element): bool
    {
        return $this->xml->attribute($element, 'negationInd') === 'true';
    }

    /**
     * Where an entry usually references its table row: its own text and its
     * code's original text, then any the reader adds.
     *
     * @return list<DOMElement|null>
     */
    protected function references(DOMElement $element, ?DOMElement ...$more): array
    {
        return [
            $this->xml->child($element, 'text'),
            $this->xml->path($element, 'code', 'originalText'),
            ...$more,
        ];
    }

    /**
     * A severity observation's value (SNOMED mild, moderate, severe) in the
     * app's vocabulary, or null.
     */
    protected function severity(?DOMElement $observation): ?string
    {
        $value = $this->xml->child($observation, 'value');
        $code = $this->xml->attribute($value, 'code');
        $mapped = match ($code) {
            '255604002' => 'mild',
            '6736007' => 'moderate',
            '24484000' => 'severe',
            default => null,
        };
        if ($mapped !== null) {
            return $mapped;
        }

        $name = strtolower((string) $this->xml->codeName($value));

        return in_array($name, ['mild', 'moderate', 'severe'], true) ? $name : null;
    }

    /**
     * A problem's or allergy's clinical status: its own status observation
     * (SNOMED active, inactive, resolved) when it has one; else resolved once it
     * has ended; else its concern act's status, where a completed concern is
     * resolved and a suspended one inactive.
     *
     * @param  list<string>  $statusTemplates
     * @param  list<string>  $concernTemplates
     */
    protected function clinicalStatus(DOMElement $observation, ?string $ended, array $statusTemplates, array $concernTemplates): string
    {
        $status = $this->xml->attribute($this->xml->child($this->xml->related($observation, 'observation', $statusTemplates)[0] ?? null, 'value'), 'code');
        $mapped = match ($status) {
            '55561003' => 'active',
            '73425007' => 'inactive',
            '413322009' => 'resolved',
            default => null,
        };
        if ($mapped !== null) {
            return $mapped;
        }
        if ($ended !== null) {
            return 'resolved';
        }

        $concern = $this->xml->first('ancestor::*[local-name()="act"][1]', $observation);
        $concern = $concern !== null && $this->xml->hasTemplate($concern, $concernTemplates) ? $concern : null;

        return match ($this->xml->attribute($this->xml->child($concern, 'statusCode'), 'code')) {
            'completed' => 'resolved',
            'suspended' => 'inactive',
            default => 'active',
        };
    }
}

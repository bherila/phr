<?php

namespace App\Services\PHR\Import\Ehi;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Reads an installation's HTML dictionary into an EhiDictionary.
 *
 * Input is untrusted. Documents are parsed with libxml's HTML parser with network
 * access off, the external entity loader refusing everything, and no entity
 * substitution; nothing referenced (scripts, stylesheets, images, frames) is ever
 * fetched, and script-like elements are removed before analysis.
 *
 * Strategy VERSION ehi-dict-2 (structural heuristics; bump on any change):
 * 1. Headings (h1-h6), captions, and elements whose class contains "tablename"
 *    are read in document order. A heading names a table when its whole text is
 *    an upper-case identifier (`PAT_ENC`), `Table: NAME` / `Table Name - NAME` /
 *    `NAME Table`, or `NAME - description`. Any other heading opens a section;
 *    a section whose heading mentions "primary key" is a key section.
 *    The document <title> names a grid only when no heading has.
 * 2. A <table> whose first row has a "Column Name"/"Column"/"Name"/"Field" cell
 *    is a column grid: names from that cell, types from "Type"/"Data Type", key
 *    membership from a "Primary Key"/"PK"/"Key" cell holding a yes-like marker.
 *    A "Table Name" cell makes it a multi-table grid keyed by that cell instead.
 * 3. A <table> in a key section (or captioned "primary key") lists key columns,
 *    from its name cell or else its first cell, ordered by an "Ordinal" cell when
 *    every row has an integer one.
 * 4. Column names must be identifiers; other rows (sub-headings, notes) are
 *    skipped. A table documented differently by two documents keeps the first.
 * Unrecognized structure yields an empty catalogue, never an exception.
 */
final class EhiDictionaryReader
{
    public const string VERSION = 'ehi-dict-2';

    private const array NAME_HEADERS = ['column name', 'column', 'name', 'field name', 'field', 'column_name'];

    private const array TYPE_HEADERS = ['type', 'data type', 'datatype', 'column type', 'sql type'];

    private const array KEY_HEADERS = ['primary key', 'pk', 'is pk', 'key', 'is primary key', 'primary key?'];

    private const array TABLE_HEADERS = ['table name', 'table'];

    private const array ORDINAL_HEADERS = ['ordinal position', 'ordinal', 'position', 'order'];

    private const array YES_MARKERS = ['y', 'yes', 'true', 'x', 'pk', '1', '*', "\u{2713}", "\u{2714}"];

    /** Elements whose content is never analysed or kept. */
    private const array STRIPPED = ['script', 'style', 'noscript', 'template', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet'];

    private const string IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_$#]{0,127}$/';

    private const string TABLE_IDENTIFIER = '/^[A-Z][A-Z0-9_$#]{1,127}$/';

    public function __construct(private readonly int $maxDocumentBytes) {}

    public static function fromLimits(EhiLimits $limits): self
    {
        return new self($limits->maxDictionaryBytes);
    }

    /**
     * @param  iterable<string>  $documents  raw HTML documents, in package path order
     */
    public function read(iterable $documents): EhiDictionary
    {
        /** @var array<string, EhiDictionaryTable> $tables */
        $tables = [];
        $stats = ['documents' => 0, 'refused' => 0, 'unparseable' => 0, 'conflicts' => 0, 'unnamed' => 0, 'markers' => 0];

        foreach ($documents as $html) {
            if (strlen($html) > $this->maxDocumentBytes) {
                $stats['refused']++;

                continue;
            }
            $document = self::loadDocument($html);
            if ($document === null) {
                $stats['unparseable']++;

                continue;
            }
            $stats['documents']++;

            foreach ($this->readDocument($document, $stats) as $table) {
                $existing = $tables[$table->name] ?? null;
                if ($existing === null) {
                    $tables[$table->name] = $table;
                } elseif ($existing->toArray() !== $table->toArray()) {
                    $stats['conflicts']++;
                }
            }
        }

        return new EhiDictionary(
            $tables,
            self::VERSION,
            documents: $stats['documents'],
            refusedDocuments: $stats['refused'],
            unparseableDocuments: $stats['unparseable'],
            conflictingTables: $stats['conflicts'],
            unnamedGrids: $stats['unnamed'],
            primaryKeyMarkers: $stats['markers'],
        );
    }

    /**
     * Parse untrusted HTML without network access, entity expansion, or external
     * loading, with script-like elements removed. Null when libxml cannot load it.
     * Callers enforce the size limit before calling.
     */
    public static function loadDocument(string $html): ?DOMDocument
    {
        $previousErrors = libxml_use_internal_errors(true);
        $previousLoader = libxml_get_external_entity_loader();
        libxml_set_external_entity_loader(static fn (): null => null);

        try {
            if (trim($html) === '') {
                return null;
            }
            // libxml's HTML parser assumes Latin-1 without a charset; numeric
            // references make valid UTF-8 unambiguous without trusting a <meta>.
            if (mb_check_encoding($html, 'UTF-8')) {
                $html = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
            }

            $document = new DOMDocument;
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            $document->validateOnParse = false;
            if (! $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_HTML_NODEFDTD)) {
                return null;
            }

            $xpath = new DOMXPath($document);
            foreach (self::STRIPPED as $tag) {
                $nodes = $xpath->query('//'.$tag);
                foreach ($nodes === false ? [] : iterator_to_array($nodes) as $node) {
                    $node->parentNode?->removeChild($node);
                }
            }
            // Event-handler attributes are inert here but never worth keeping.
            $handlers = $xpath->query('//@*[starts-with(translate(name(), "ON", "on"), "on")]');
            foreach ($handlers === false ? [] : iterator_to_array($handlers) as $attribute) {
                if ($attribute->parentNode instanceof DOMElement) {
                    $attribute->parentNode->removeAttribute($attribute->nodeName);
                }
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
            libxml_set_external_entity_loader($previousLoader);
        }
    }

    /** Whitespace-collapsed text of a node (including non-breaking spaces). */
    public static function text(DOMNode $node): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $node->textContent));
    }

    /**
     * @param  array{documents: int, refused: int, unparseable: int, conflicts: int, unnamed: int, markers: int}  $stats
     * @return list<EhiDictionaryTable>
     */
    private function readDocument(DOMDocument $document, array &$stats): array
    {
        $xpath = new DOMXPath($document);
        $title = null;
        $titleNodes = $xpath->query('//title');
        if ($titleNodes !== false && $titleNodes->item(0) !== null) {
            $title = $this->tableNameFrom(self::text($titleNodes->item(0)));
        }

        $nodes = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6|//caption|//table'
            .'|//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "tablename")]');
        if ($nodes === false) {
            return [];
        }

        /** @var array<string, array{columns: list<array{name: string, type: ?string}>, key: list<string>}> $built */
        $built = [];
        $current = null;
        $keySection = false;
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($node->tagName);
            if ($tag !== 'table') {
                if ($tag === 'caption' || $this->isInsideTable($node)) {
                    continue;
                }
                $text = self::text($node);
                $unsupportedName = false;
                $name = $this->tableNameFrom($text, $unsupportedName);
                if ($name !== null) {
                    $current = $name;
                    $keySection = false;
                } elseif ($unsupportedName) {
                    // Its grids must not be attributed to an earlier valid
                    // table or the document's title.
                    $current = null;
                    $title = null;
                    $keySection = false;
                } elseif ($text !== '') {
                    $keySection = (bool) preg_match('/primary\s*key/i', $text);
                }

                continue;
            }

            $caption = $xpath->query('./caption', $node);
            $captionText = $caption !== false && $caption->item(0) !== null ? self::text($caption->item(0)) : '';
            $unsupportedCaptionName = false;
            $captionName = $this->tableNameFrom($captionText, $unsupportedCaptionName);
            $isKeyGrid = $keySection || preg_match('/primary\s*key/i', $captionText) === 1;
            $name = $unsupportedCaptionName ? null : ($captionName ?? $current ?? $title);

            $rows = $this->rowsOf($xpath, $node);
            if ($rows === []) {
                continue;
            }
            $headers = array_map(static fn (string $cell): string => strtolower($cell), $rows[0]);
            $nameIndex = $this->indexOf($headers, self::NAME_HEADERS);
            $typeIndex = $this->indexOf($headers, self::TYPE_HEADERS);
            $keyIndex = $this->indexOf($headers, self::KEY_HEADERS);
            $tableIndex = $this->indexOf($headers, self::TABLE_HEADERS);
            $ordinalIndex = $this->indexOf($headers, self::ORDINAL_HEADERS);
            $headerRecognized = $nameIndex !== null || $typeIndex !== null || $keyIndex !== null || $ordinalIndex !== null;

            if ($isKeyGrid && $tableIndex === null) {
                $stats['markers']++;
                if ($name === null) {
                    $stats['unnamed']++;

                    continue;
                }
                $body = $headerRecognized ? array_slice($rows, 1) : $rows;
                $key = $this->orderedNames($body, $nameIndex ?? 0, $headerRecognized ? $ordinalIndex : null);
                $built[$name] ??= ['columns' => [], 'key' => []];
                $built[$name]['key'] = $this->mergeNames($built[$name]['key'], $key);

                continue;
            }

            if ($nameIndex === null) {
                continue;
            }
            if ($keyIndex !== null) {
                $stats['markers']++;
            }

            foreach (array_slice($rows, 1) as $cells) {
                $column = $cells[$nameIndex] ?? '';
                $tableName = $tableIndex === null ? $name : $this->tableNameFrom($cells[$tableIndex] ?? '');
                if ($tableName === null) {
                    if ($tableIndex === null) {
                        $stats['unnamed']++;

                        break;
                    }

                    continue;
                }
                if (preg_match(self::IDENTIFIER, $column) !== 1) {
                    continue;
                }
                $type = $typeIndex === null ? null : ($cells[$typeIndex] ?? '');
                $type = $type === null || $type === '' || strlen($type) > 128 ? null : $type;

                $built[$tableName] ??= ['columns' => [], 'key' => []];
                if (! in_array($column, array_column($built[$tableName]['columns'], 'name'), true)) {
                    $built[$tableName]['columns'][] = ['name' => $column, 'type' => $type];
                }
                if ($keyIndex !== null && in_array(strtolower($cells[$keyIndex] ?? ''), self::YES_MARKERS, true)) {
                    $built[$tableName]['key'] = $this->mergeNames($built[$tableName]['key'], [$column]);
                }
            }
        }

        $tables = [];
        foreach ($built as $name => $parts) {
            if ($parts['columns'] === [] && $parts['key'] === []) {
                continue;
            }
            $tables[] = new EhiDictionaryTable((string) $name, $parts['columns'], $parts['key']);
        }

        return $tables;
    }

    private function tableNameFrom(string $text, bool &$unsupportedName = false): ?string
    {
        $unsupportedName = false;
        if ($text === '') {
            return null;
        }
        if (preg_match(self::TABLE_IDENTIFIER, $text) === 1) {
            $unsupportedName = preg_match(EhiMemberClassifier::TABLE_NAME, $text) !== 1;

            return $unsupportedName ? null : $text;
        }
        $patterns = [
            '/^table(?:\s+name)?\s*[:\x{2013}-]\s*([A-Za-z][A-Za-z0-9_$#]{1,127})$/iu',
            '/^table\s+([A-Z][A-Z0-9_$#]{1,127})$/u',
            '/^([A-Z][A-Z0-9_$#]{1,127})\s+table$/iu',
            '/^([A-Z][A-Z0-9_$#]{2,127})\s+[:\x{2013}-]\s+\S/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) === 1) {
                // Unicode case-insensitive heading matching also admits letters
                // such as Kelvin sign. Account keys must use the same ASCII
                // contract as member names, regardless of heading syntax.
                $unsupportedName = preg_match(EhiMemberClassifier::TABLE_NAME, $match[1]) !== 1;

                return $unsupportedName ? null : $match[1];
            }
        }

        return null;
    }

    private function isInsideTable(DOMNode $node): bool
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'table') {
                return true;
            }
        }

        return false;
    }

    /** @return list<list<string>> cell texts of the table's own rows (not nested tables') */
    private function rowsOf(DOMXPath $xpath, DOMElement $table): array
    {
        $rows = [];
        $nodes = $xpath->query('./tr|./thead/tr|./tbody/tr|./tfoot/tr', $table);
        foreach ($nodes === false ? [] : $nodes as $row) {
            $cells = [];
            $cellNodes = $xpath->query('./th|./td', $row);
            foreach ($cellNodes === false ? [] : $cellNodes as $cell) {
                $cells[] = self::text($cell);
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $candidates  in preference order
     */
    private function indexOf(array $headers, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $index = array_search($candidate, $headers, true);
            if ($index !== false) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<string>
     */
    private function orderedNames(array $rows, int $nameIndex, ?int $ordinalIndex): array
    {
        $entries = [];
        $ordered = $ordinalIndex !== null;
        foreach ($rows as $position => $cells) {
            $name = $cells[$nameIndex] ?? '';
            if (preg_match(self::IDENTIFIER, $name) !== 1) {
                continue;
            }
            $ordinal = $ordinalIndex === null ? '' : ($cells[$ordinalIndex] ?? '');
            if (! ctype_digit($ordinal)) {
                $ordered = false;
            }
            $entries[] = ['name' => $name, 'ordinal' => $ordered ? (int) $ordinal : 0, 'position' => $position];
        }
        if ($ordered) {
            usort($entries, static fn (array $a, array $b): int => [$a['ordinal'], $a['position']] <=> [$b['ordinal'], $b['position']]);
        }

        return $this->mergeNames([], array_column($entries, 'name'));
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $more
     * @return list<string>
     */
    private function mergeNames(array $names, array $more): array
    {
        foreach ($more as $name) {
            if (! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}

<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrEhiTableAccount;
use App\Services\PHR\Import\Ehi\EhiArchiveGuard;
use App\Services\PHR\Import\Ehi\EhiDialect;
use App\Services\PHR\Import\Ehi\EhiDictionary;
use App\Services\PHR\Import\Ehi\EhiDictionaryReader;
use App\Services\PHR\Import\Ehi\EhiDirectorySource;
use App\Services\PHR\Import\Ehi\EhiLimits;
use App\Services\PHR\Import\Ehi\EhiMemberClassifier;
use App\Services\PHR\Import\Ehi\EhiMemberKind;
use App\Services\PHR\Import\Ehi\EhiPackageDetector;
use App\Services\PHR\Import\Ehi\EhiPackageRejected;
use App\Services\PHR\Import\Ehi\EhiPackageSource;
use App\Services\PHR\Import\Ehi\EhiTsvMalformedRow;
use App\Services\PHR\Import\Ehi\EhiTsvReader;
use App\Services\PHR\Import\Ehi\EhiValidatedMember;
use App\Services\PHR\Import\Ehi\EhiZipSource;
use Generator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/*
 * ============================================================================
 *  SAFE TO PASTE PUBLICLY: this command prints package STRUCTURE only.
 * ============================================================================
 *
 * Its output exists to be pasted into a public issue so the supported EHI Tables
 * dialect and dictionary shape can be pinned without anyone sharing real data.
 * Every value it prints is one of:
 *   - a count, a boolean, or a size bucket;
 *   - a fixed code or label defined in this repository (kinds, reason codes,
 *     malformed-row codes, the null-marker candidates below, readme keywords);
 *   - a file extension from EXTENSIONS or an HTML tag name from HTML_TAGS (any
 *     other tag is "(other)"); CSS class tokens are only counted, by fixed shape
 *     bucket (CLASS_SHAPES), never printed, since any token could be a name;
 *   - presence (yes/no) of the well-known table names in
 *     EhiPackageDetector::KNOWN_TABLE_NAMES.
 * It never prints a member path or file name, any other table or column name, a
 * cell value, dictionary or readme text, or an exception message. PAT_ID values
 * are hashed in memory only to count them. Any unexpected failure prints only a
 * fixed code and the exception's class name.
 *
 * Changing this command: keep every printed string inside the categories above,
 * and extend PhrEhiProbeCommandTest's planted-marker assertions to match.
 */
#[Signature('phr:ehi:probe {--dir= : Extracted EHI package directory} {--zip= : EHI package ZIP} {--json : Print JSON instead of text}')]
#[Description('Print the structure (never values or names) of an EHI Tables package, safe to share publicly')]
final class PhrEhiProbeCommand extends Command
{
    public const string VERSION = 'ehi-probe-2';

    /** Shape buckets for CSS class tokens; a token's text is never printed. */
    public const array CLASS_SHAPES = ['lowercase', 'lowercase_hyphenated', 'uppercase', 'mixed_case', 'contains_digits', 'other'];

    public const array NULL_CANDIDATES = ['NULL', '\\N', 'null', '<null>'];

    public const array README_KEYWORDS = ['version', 'release', 'deployment', 'organization', 'installation', 'instance', 'system'];

    private const string README_PATTERN = '/(version|release|deployment|organization|installation|instance|system)\s*[:=]/i';

    private const array COLUMN_SUFFIXES = ['_ID', '_DATE', '_DTTM', '_REAL'];

    private const array EXTENSIONS = [
        'tsv', 'txt', 'csv', 'htm', 'html', 'css', 'js', 'xml', 'json', 'md', 'rtf', 'pdf', 'doc', 'docx', 'xls', 'xlsx',
        'png', 'jpg', 'jpeg', 'gif', 'bmp', 'tif', 'tiff', 'webp', 'heic', 'heif', 'svg', 'avif',
        'mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac', 'wma', 'mp4', 'm4v', 'mov', 'avi', 'wmv', 'mkv', 'webm', 'mpg', 'mpeg',
        'dcm', 'zip', 'gz', 'ini', 'log', 'dat',
    ];

    /** Text-like extensions whose first bytes are inspected (BOM, first line). */
    private const array TEXT_EXTENSIONS = ['tsv', 'txt', 'csv', 'htm', 'html', 'xml', 'json', 'md', 'rtf', 'css', 'js', 'ini', 'log'];

    private const array HTML_TAGS = [
        'a', 'abbr', 'address', 'area', 'article', 'aside', 'audio', 'b', 'base', 'bdi', 'bdo', 'blockquote', 'body', 'br',
        'button', 'canvas', 'caption', 'center', 'cite', 'code', 'col', 'colgroup', 'data', 'dd', 'del', 'details', 'dfn',
        'dialog', 'div', 'dl', 'dt', 'em', 'fieldset', 'figcaption', 'figure', 'font', 'footer', 'form', 'h1', 'h2', 'h3',
        'h4', 'h5', 'h6', 'head', 'header', 'hr', 'html', 'i', 'img', 'input', 'ins', 'kbd', 'label', 'legend', 'li', 'link',
        'main', 'map', 'mark', 'meta', 'meter', 'nav', 'nobr', 'ol', 'optgroup', 'option', 'output', 'p', 'param', 'picture',
        'pre', 'progress', 'q', 's', 'samp', 'section', 'select', 'small', 'source', 'span', 'strike', 'strong', 'sub',
        'summary', 'sup', 'table', 'tbody', 'td', 'textarea', 'tfoot', 'th', 'thead', 'time', 'title', 'tr', 'track', 'tt',
        'u', 'ul', 'var', 'video', 'wbr',
    ];

    private const int FIRST_LINE_BYTES = 64 * 1024;

    private const int TOP_OUTLINE = 40;

    private const int PAT_ID_CAP = 100000;

    public function handle(EhiArchiveGuard $guard, EhiMemberClassifier $classifier, EhiPackageDetector $detector): int
    {
        $dir = $this->option('dir');
        $zip = $this->option('zip');
        $dir = is_string($dir) && $dir !== '' ? $dir : null;
        $zip = is_string($zip) && $zip !== '' ? $zip : null;
        if (($dir === null) === ($zip === null)) {
            $this->line('error: pass exactly one of --dir or --zip');

            return self::INVALID;
        }

        $source = null;
        try {
            $limits = EhiLimits::fromConfig();
            $source = $dir !== null ? new EhiDirectorySource($dir) : new EhiZipSource((string) $zip);
            $members = $guard->validate($source, $limits);
            $report = $this->probe($source, $members, $limits, $classifier, $detector);
        } catch (EhiPackageRejected $rejected) {
            $this->emit(['probe_version' => self::VERSION, 'refused' => $rejected->reason]);

            return self::FAILURE;
        } catch (Throwable $failure) {
            // Never print the message: it could name a file.
            $this->emit(['probe_version' => self::VERSION, 'error' => 'probe_failed', 'exception' => class_basename($failure)]);

            return self::FAILURE;
        } finally {
            $source?->close();
        }

        $this->emit($report);

        return self::SUCCESS;
    }

    /**
     * @param  list<EhiValidatedMember>  $members
     * @return array<string, mixed>
     */
    private function probe(EhiPackageSource $source, array $members, EhiLimits $limits, EhiMemberClassifier $classifier, EhiPackageDetector $detector): array
    {
        $byKind = array_fill_keys(EhiMemberKind::ALL, 0);
        $byExtension = [];
        $sizes = array_fill_keys(['0', '<1KiB', '<64KiB', '<1MiB', '<16MiB', '<256MiB', '>=256MiB'], 0);
        $bomByKind = array_fill_keys(EhiMemberKind::ALL, 0);
        $unreadable = 0;

        /** @var array<string, ?string> $firstLines */
        $firstLines = [];
        /** @var list<array{member: EhiValidatedMember, kind: string, table: ?string}> $classified */
        $classified = [];
        foreach ($members as $member) {
            $extension = strtolower(pathinfo($member->path, PATHINFO_EXTENSION));
            $line = null;
            if (in_array($extension, self::TEXT_EXTENSIONS, true)) {
                $line = $this->readFirstLine($source, $member);
                if ($line === false) {
                    $unreadable++;
                    $line = null;
                }
            }
            $firstLines[$member->path] = $line;
            $class = $classifier->classify($member->path, $line);
            $classified[] = ['member' => $member, 'kind' => $class['kind'], 'table' => $class['table']];

            $byKind[$class['kind']]++;
            $extensionKey = $extension === '' ? '(none)' : (in_array($extension, self::EXTENSIONS, true) ? $extension : '(other)');
            $byExtension[$extensionKey] = ($byExtension[$extensionKey] ?? 0) + 1;
            $sizes[$this->sizeBucket($member->entry->declaredBytes)]++;
            if ($line !== null && str_starts_with($line, EhiTsvReader::BOM)) {
                $bomByKind[$class['kind']]++;
            }
        }
        ksort($byExtension, SORT_STRING);

        $detection = $detector->detect($members, static fn (EhiValidatedMember $member): ?string => $firstLines[$member->path] ?? null);

        [$dictionaryReport, $dictionary] = $this->probeDictionary($source, $classified, $limits);

        return [
            'probe_version' => self::VERSION,
            'versions' => [
                'classifier' => EhiMemberClassifier::VERSION,
                'detector' => EhiPackageDetector::VERSION,
                'dictionary_reader' => EhiDictionaryReader::VERSION,
                'dialect_provisional' => EhiDialect::provisional()->version,
            ],
            'refused' => null,
            'members' => [
                'total' => count($members),
                'unreadable' => $unreadable,
                'by_kind' => $byKind,
                'by_extension' => $byExtension,
                'size_histogram' => $sizes,
                'bom_by_kind' => $bomByKind,
            ],
            'detection' => [
                'is_ehi' => $detection->isEhi,
                'reasons' => $detection->reasons,
                'table_members' => $detection->tableCount,
                'documentation_members' => $detection->dictionaryCount,
            ],
            'tables' => $this->probeTables($source, $classified, $limits, $dictionary),
            'dictionary' => $dictionaryReport,
            'readme_index' => $this->probeReadmes($source, $classified, $limits),
        ];
    }

    /**
     * @param  list<array{member: EhiValidatedMember, kind: string, table: ?string}>  $classified
     * @return array<string, mixed>
     */
    private function probeTables(EhiPackageSource $source, array $classified, EhiLimits $limits, EhiDictionary $dictionary): array
    {
        $none = EhiDialect::provisional();
        $rfc = $none->withQuoteMode(EhiDialect::QUOTE_RFC4180);

        $states = array_fill_keys([PhrEhiTableAccount::STATE_EMPTY, PhrEhiTableAccount::STATE_HEADER_ONLY, PhrEhiTableAccount::STATE_PARSED, PhrEhiTableAccount::STATE_MALFORMED], 0);
        $headerProblems = [];
        $known = array_fill_keys(EhiPackageDetector::KNOWN_TABLE_NAMES, false);
        $suffixes = array_fill_keys(self::COLUMN_SUFFIXES, 0);
        $columns = 0;
        $maxColumns = 0;
        $totals = array_fill_keys(EhiTsvReader::COUNTERS, 0);
        $malformedNone = array_fill_keys(EhiTsvMalformedRow::CODES, 0);
        $malformedRfc = array_fill_keys(EhiTsvMalformedRow::CODES, 0);
        $rfcRecords = 0;
        $rfcStates = $states;
        $nullCandidates = array_fill_keys(self::NULL_CANDIDATES, 0);
        $patIdTables = 0;
        // Either refuses intake (unscoped_patient_row), so the probe shows it first.
        $patIdBlankRows = 0;
        $patIdMalformedRows = 0;
        /** @var array<string, true> $patIds hashes only */
        $patIds = [];
        $patIdCapped = false;
        $coverage = ['tables_in_dictionary' => 0, 'tables_not_in_dictionary' => 0, 'header_columns_not_in_dictionary' => 0, 'dictionary_columns_not_in_header' => 0];
        $unreadable = 0;
        $tableCount = 0;

        foreach ($classified as $item) {
            if ($item['kind'] !== EhiMemberKind::TABLE) {
                continue;
            }
            $tableCount++;
            if ($item['table'] !== null && EhiPackageDetector::isKnownTableName($item['table'])) {
                $known[strtoupper($item['table'])] = true;
            }

            try {
                // Pass 1: provisional dialect (no quoting).
                $stream = $source->open($item['member']->entry);
                try {
                    $reader = new EhiTsvReader($stream, $none, $limits);
                    $state = $reader->state();
                    $states[$state]++;
                    if ($reader->headerProblem() !== null) {
                        $headerProblems[$reader->headerProblem()] = ($headerProblems[$reader->headerProblem()] ?? 0) + 1;
                    }
                    $header = $reader->header() ?? [];
                    $columns += count($header);
                    $maxColumns = max($maxColumns, count($header));
                    foreach ($header as $name) {
                        foreach (self::COLUMN_SUFFIXES as $suffix) {
                            if (str_ends_with(strtoupper($name), $suffix)) {
                                $suffixes[$suffix]++;
                            }
                        }
                    }
                    $patIdIndex = array_search('PAT_ID', $header, true);
                    $patIdTables += $patIdIndex === false ? 0 : 1;

                    if ($header !== [] && $item['table'] !== null) {
                        $documented = $dictionary->table($item['table']) ?? $dictionary->table(strtoupper($item['table']));
                        if ($documented === null) {
                            $coverage['tables_not_in_dictionary']++;
                        } else {
                            $coverage['tables_in_dictionary']++;
                            $coverage['header_columns_not_in_dictionary'] += count(array_diff($header, $documented->columnNames()));
                            $coverage['dictionary_columns_not_in_header'] += count(array_diff($documented->columnNames(), $header));
                        }
                    }

                    foreach ($reader->rows() as $row) {
                        if ($row instanceof EhiTsvMalformedRow) {
                            $patIdMalformedRows += $patIdIndex === false ? 0 : 1;

                            continue;
                        }
                        if ($patIdIndex !== false && in_array($row->values[$patIdIndex] ?? null, [null, ''], true)) {
                            $patIdBlankRows++;
                        }
                        foreach ($row->values as $index => $value) {
                            if ($value !== null && isset($nullCandidates[$value])) {
                                $nullCandidates[$value]++;
                            }
                            if ($index === $patIdIndex && $value !== null && $value !== '' && ! $patIdCapped) {
                                $patIds[hash('xxh128', $value)] = true;
                                $patIdCapped = count($patIds) >= self::PAT_ID_CAP;
                            }
                        }
                    }
                    foreach ($reader->counters() as $key => $count) {
                        if (isset($totals[$key])) {
                            $totals[$key] += $count;
                        } elseif (str_starts_with($key, 'malformed_')) {
                            $malformedNone[substr($key, strlen('malformed_'))] += $count;
                        }
                    }
                } finally {
                    fclose($stream);
                }

                // Pass 2: RFC 4180 quoting, to compare how the same bytes split.
                $stream = $source->open($item['member']->entry);
                try {
                    $reader = new EhiTsvReader($stream, $rfc, $limits);
                    $rfcStates[$reader->state()]++;
                    foreach ($reader->rows() as $row) {
                        unset($row);
                    }
                    $counters = $reader->counters();
                    $rfcRecords += $counters['records'];
                    foreach (EhiTsvMalformedRow::CODES as $code) {
                        $malformedRfc[$code] += $counters['malformed_'.$code];
                    }
                } finally {
                    fclose($stream);
                }
            } catch (EhiPackageRejected) {
                $unreadable++;
            }
        }

        return [
            'table_members' => $tableCount,
            'unreadable' => $unreadable,
            'states' => $states,
            'header_problems' => $headerProblems,
            'known_table_names_present' => $known,
            'columns' => [
                'total' => $columns,
                'max_per_table' => $maxColumns,
                'name_suffix_counts' => $suffixes,
            ],
            'bytes' => $totals['bytes'],
            'records' => $totals['records'],
            'line_endings' => [
                'lf' => $totals['terminator_lf'],
                'crlf' => $totals['terminator_crlf'],
                'final_record_without_terminator' => $totals['terminator_none'],
                'records_with_lone_cr' => $totals['records_with_lone_cr'],
            ],
            'quotes' => [
                'records_with_quote_at_field_start' => $totals['records_with_leading_quote'],
                'records_with_embedded_quote' => $totals['records_with_embedded_quote'],
            ],
            'field_count_mismatch' => [
                'quote_none' => $malformedNone[EhiTsvMalformedRow::FIELD_COUNT_MISMATCH],
                'rfc4180' => $malformedRfc[EhiTsvMalformedRow::FIELD_COUNT_MISMATCH],
            ],
            'malformed_records' => [
                'quote_none' => $malformedNone,
                'rfc4180' => $malformedRfc,
            ],
            'rfc4180' => [
                'records' => $rfcRecords,
                'states' => $rfcStates,
            ],
            'invalid_utf8_records' => $malformedNone[EhiTsvMalformedRow::INVALID_ENCODING],
            'empty_cells' => $totals['empty_cells'],
            'null_marker_candidates' => $nullCandidates,
            'pat_id' => [
                'tables_with_column' => $patIdTables,
                'distinct_values' => count($patIds),
                'distinct_values_capped' => $patIdCapped,
                'blank_value_rows' => $patIdBlankRows,
                'malformed_rows' => $patIdMalformedRows,
            ],
            'dictionary_coverage' => $coverage,
        ];
    }

    /**
     * @param  list<array{member: EhiValidatedMember, kind: string, table: ?string}>  $classified
     * @return array{0: array<string, mixed>, 1: EhiDictionary}
     */
    private function probeDictionary(EhiPackageSource $source, array $classified, EhiLimits $limits): array
    {
        $tags = [];
        $classes = ['total' => 0, 'by_shape' => array_fill_keys(self::CLASS_SHAPES, 0)];
        /** @var array<string, true> $distinctClasses hashes only */
        $distinctClasses = [];
        $counts = ['dictionary_members' => 0, 'index_members' => 0, 'oversize' => 0, 'unreadable' => 0];

        $documents = function () use ($source, $classified, $limits, &$tags, &$classes, &$distinctClasses, &$counts): Generator {
            foreach ($classified as $item) {
                if ($item['kind'] !== EhiMemberKind::DICTIONARY && $item['kind'] !== EhiMemberKind::INDEX) {
                    continue;
                }
                $counts[$item['kind'] === EhiMemberKind::DICTIONARY ? 'dictionary_members' : 'index_members']++;
                if ($item['member']->entry->declaredBytes > $limits->maxDictionaryBytes) {
                    $counts['oversize']++;

                    continue;
                }
                try {
                    $stream = $source->open($item['member']->entry);
                } catch (EhiPackageRejected) {
                    $counts['unreadable']++;

                    continue;
                }
                try {
                    $html = stream_get_contents($stream, $limits->maxDictionaryBytes + 1);
                } finally {
                    fclose($stream);
                }
                if (! is_string($html)) {
                    $counts['unreadable']++;

                    continue;
                }
                if (strlen($html) <= $limits->maxDictionaryBytes) {
                    $document = EhiDictionaryReader::loadDocument($html);
                    foreach ($document === null ? [] : $document->getElementsByTagName('*') as $element) {
                        $tag = strtolower($element->tagName);
                        $tag = in_array($tag, self::HTML_TAGS, true) ? $tag : '(other)';
                        $tags[$tag] = ($tags[$tag] ?? 0) + 1;
                        foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [] as $token) {
                            if ($token !== '') {
                                $classes['total']++;
                                $classes['by_shape'][$this->classShape($token)]++;
                                $distinctClasses[hash('xxh128', $token)] = true;
                            }
                        }
                    }
                }

                yield $html;
            }
        };

        $dictionary = EhiDictionaryReader::fromLimits($limits)->read($documents());

        return [[
            ...$counts,
            'outline' => [
                'tags' => $this->top($tags),
                'class_tokens' => ['total' => $classes['total'], 'distinct' => count($distinctClasses), 'by_shape' => $classes['by_shape']],
            ],
            'parsed' => [
                'documents' => $dictionary->documents,
                'refused_documents' => $dictionary->refusedDocuments,
                'unparseable_documents' => $dictionary->unparseableDocuments,
                'tables' => count($dictionary->tables),
                'columns' => $dictionary->columnCount(),
                'columns_with_type' => array_sum(array_map(
                    static fn ($table): int => count(array_filter($table->columns, static fn (array $column): bool => $column['type'] !== null)),
                    $dictionary->tables,
                )),
                'primary_key_markers_found' => $dictionary->primaryKeyMarkers > 0,
                'primary_key_markers' => $dictionary->primaryKeyMarkers,
                'tables_with_primary_key' => $dictionary->primaryKeyTableCount(),
                'unnamed_grids' => $dictionary->unnamedGrids,
                'conflicting_tables' => $dictionary->conflictingTables,
                'known_table_names_documented' => array_combine(
                    EhiPackageDetector::KNOWN_TABLE_NAMES,
                    array_map(static fn (string $name): bool => $dictionary->table($name) !== null, EhiPackageDetector::KNOWN_TABLE_NAMES),
                ),
            ],
        ], $dictionary];
    }

    /**
     * @param  list<array{member: EhiValidatedMember, kind: string, table: ?string}>  $classified
     * @return array<string, mixed>
     */
    private function probeReadmes(EhiPackageSource $source, array $classified, EhiLimits $limits): array
    {
        $members = 0;
        $lines = 0;
        $keywords = array_fill_keys(self::README_KEYWORDS, 0);
        foreach ($classified as $item) {
            if ($item['kind'] !== EhiMemberKind::README && $item['kind'] !== EhiMemberKind::INDEX) {
                continue;
            }
            $members++;
            try {
                $stream = $source->open($item['member']->entry);
            } catch (EhiPackageRejected) {
                continue;
            }
            try {
                $read = 0;
                while ($read < $limits->maxDictionaryBytes && ($line = fgets($stream, self::FIRST_LINE_BYTES)) !== false) {
                    $read += strlen($line);
                    if (preg_match_all(self::README_PATTERN, strip_tags($line), $matches) > 0) {
                        $lines++;
                        foreach ($matches[1] as $keyword) {
                            $keywords[strtolower($keyword)]++;
                        }
                    }
                }
            } finally {
                fclose($stream);
            }
        }

        return ['members' => $members, 'lines_with_identifier_shape' => $lines, 'keywords' => $keywords];
    }

    /** @return string|false the first line without its terminator ("" for an empty member), or false when unreadable */
    private function readFirstLine(EhiPackageSource $source, EhiValidatedMember $member): string|false
    {
        try {
            $stream = $source->open($member->entry);
        } catch (EhiPackageRejected) {
            return false;
        }
        try {
            $line = fgets($stream, self::FIRST_LINE_BYTES);
        } finally {
            fclose($stream);
        }

        return $line === false ? '' : rtrim($line, "\r\n");
    }

    private function sizeBucket(int $bytes): string
    {
        return match (true) {
            $bytes === 0 => '0',
            $bytes < 1024 => '<1KiB',
            $bytes < 64 * 1024 => '<64KiB',
            $bytes < 1024 * 1024 => '<1MiB',
            $bytes < 16 * 1024 * 1024 => '<16MiB',
            $bytes < 256 * 1024 * 1024 => '<256MiB',
            default => '>=256MiB',
        };
    }

    /** One of CLASS_SHAPES; the token itself is never returned. */
    private function classShape(string $token): string
    {
        return match (true) {
            preg_match('/^[A-Za-z0-9_-]+\z/', $token) !== 1 => 'other',
            preg_match('/\d/', $token) === 1 => 'contains_digits',
            preg_match('/^[a-z]+\z/', $token) === 1 => 'lowercase',
            preg_match('/^[a-z_-]+\z/', $token) === 1 => 'lowercase_hyphenated',
            preg_match('/^[A-Z_-]+\z/', $token) === 1 => 'uppercase',
            default => 'mixed_case',
        };
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function top(array $counts): array
    {
        uksort($counts, static fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return array_slice($counts, 0, self::TOP_OUTLINE, true);
    }

    /** @param  array<string, mixed>  $report */
    private function emit(array $report): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return;
        }
        foreach ($this->flatten($report) as $line) {
            $this->line($line);
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function flatten(array $values, int $depth = 0): array
    {
        $lines = [];
        $indent = str_repeat('  ', $depth);
        foreach ($values as $key => $value) {
            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $lines[] = "{$indent}{$key}:";
                array_push($lines, ...$this->flatten($value, $depth + 1));

                continue;
            }
            $lines[] = "{$indent}{$key}: ".match (true) {
                is_bool($value) => $value ? 'yes' : 'no',
                $value === null => '-',
                is_array($value) => $value === [] ? '(none)' : implode(', ', array_map(static fn ($item): string => is_scalar($item) ? (string) $item : '?', $value)),
                is_scalar($value) => (string) $value,
                default => '?',
            };
        }

        return $lines;
    }
}

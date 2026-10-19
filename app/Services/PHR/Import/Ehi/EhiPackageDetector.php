<?php

namespace App\Services\PHR\Import\Ehi;

/**
 * Recognizes an EHI Tables package by structure. A package is EHI when it has at
 * least one table member and at least two independent signals hold:
 * - SIGNAL_TSV_TABLES: two or more table members whose first line is a header of
 *   identifier-shaped column names
 * - SIGNAL_HTML_DOCUMENTATION: at least one dictionary or index HTML member
 * - SIGNAL_EPIC_TABLE_NAMES: a table member named after a well-known EHI table
 *
 * Never uses the archive name, export date, or vendor wording (VERSION ehi-detect-1).
 */
final class EhiPackageDetector
{
    public const string VERSION = 'ehi-detect-1';

    public const string SIGNAL_TSV_TABLES = 'tsv_tables_with_headers';

    public const string SIGNAL_HTML_DOCUMENTATION = 'html_documentation';

    public const string SIGNAL_EPIC_TABLE_NAMES = 'known_table_names';

    /** Public-spec table names; matching is case-insensitive on the member's table name. */
    public const array KNOWN_TABLE_NAMES = ['PATIENT', 'PAT_ENC', 'ORDER_PROC', 'ORDER_RESULTS', 'IMMUNE', 'PAT_IMMUNIZATIONS', 'HNO_INFO'];

    private const string COLUMN_NAME = '/^[A-Za-z_][A-Za-z0-9_$#]{0,127}$/';

    public function __construct(private readonly EhiMemberClassifier $classifier = new EhiMemberClassifier) {}

    /**
     * @param  list<EhiValidatedMember>  $members
     * @param  callable(EhiValidatedMember): ?string  $firstLine  the member's first line (bounded, without terminator), or null
     */
    public function detect(array $members, callable $firstLine): EhiDetection
    {
        $tables = 0;
        $headedTables = 0;
        $documentation = 0;
        $knownNames = 0;
        foreach ($members as $member) {
            $extension = strtolower(pathinfo($member->path, PATHINFO_EXTENSION));
            $line = in_array($extension, ['tsv', 'txt', 'htm', 'html'], true) ? $firstLine($member) : null;
            $class = $this->classifier->classify($member->path, $line);

            if ($class['kind'] === EhiMemberKind::TABLE) {
                $tables++;
                if ($line !== null && self::looksLikeHeader($line)) {
                    $headedTables++;
                }
                if ($class['table'] !== null && self::isKnownTableName($class['table'])) {
                    $knownNames++;
                }
            } elseif ($class['kind'] === EhiMemberKind::DICTIONARY || $class['kind'] === EhiMemberKind::INDEX) {
                $documentation++;
            }
        }

        $reasons = [];
        if ($headedTables >= 2) {
            $reasons[] = self::SIGNAL_TSV_TABLES;
        }
        if ($documentation >= 1) {
            $reasons[] = self::SIGNAL_HTML_DOCUMENTATION;
        }
        if ($knownNames >= 1) {
            $reasons[] = self::SIGNAL_EPIC_TABLE_NAMES;
        }

        return new EhiDetection($tables >= 1 && count($reasons) >= 2, $reasons, $tables, $documentation);
    }

    /**
     * Name-only variant for archive dispatch: same signals from entry names alone
     * (`.tsv` counts as a headed table; `.txt` only when named after a known table).
     *
     * @param  list<string>  $paths
     */
    public static function detectZipPaths(array $paths): bool
    {
        $tsv = 0;
        $tables = 0;
        $documentation = 0;
        $knownNames = 0;
        foreach ($paths as $path) {
            $path = str_replace('\\', '/', $path);
            if (str_ends_with($path, '/')) {
                continue;
            }
            $basename = basename($path);
            $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
            $stem = pathinfo($basename, PATHINFO_FILENAME);
            $known = self::isKnownTableName($stem);
            if ($extension === 'tsv') {
                $tsv++;
                $tables++;
                $knownNames += $known ? 1 : 0;
            } elseif ($extension === 'txt' && $known) {
                $tables++;
                $knownNames++;
            } elseif ($extension === 'htm' || $extension === 'html') {
                $documentation++;
            }
        }

        $signals = (int) ($tsv >= 2) + (int) ($documentation >= 1) + (int) ($knownNames >= 1);

        return $tables >= 1 && $signals >= 2;
    }

    public static function isKnownTableName(string $name): bool
    {
        return in_array(strtoupper($name), self::KNOWN_TABLE_NAMES, true);
    }

    /** A first line of two or more tab-separated identifier-shaped column names. */
    public static function looksLikeHeader(string $line): bool
    {
        if (str_starts_with($line, EhiTsvReader::BOM)) {
            $line = substr($line, strlen(EhiTsvReader::BOM));
        }
        $fields = explode("\t", rtrim($line, "\r\n"));
        if (count($fields) < 2) {
            return false;
        }
        foreach ($fields as $field) {
            if (preg_match(self::COLUMN_NAME, $field) !== 1) {
                return false;
            }
        }

        return true;
    }
}

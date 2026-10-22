<?php

namespace Tests\Support;

use RuntimeException;
use ZipArchive;

/**
 * A synthetic EHI Tables package built entirely in code. Every value is invented;
 * MARKER is planted in cells and file names so tests can prove it never reaches
 * command output, exceptions, or findings.
 *
 * Standard package (23 members):
 * - 9 table members: PATIENT (an unknown column; the dictionary also documents a
 *   column it lacks), PAT_ENC, IMMUNE (no PAT_ID column, so its one malformed
 *   row is accounted; in a PAT_ID table it would refuse the package), PAT_IMMUNIZATIONS, ORDER_RESULTS, ORDER_PROC (header only), HNO_INFO (empty),
 *   ARPB_TRANSACTIONS (out of scope), and MARKER.tsv (not in the dictionary)
 * - 9 dictionary pages: one per documented table, including PAT_ADDRESS, which no
 *   member supplies (missing)
 * - index, readme, RTF note, media file, and an unknown file with the media bytes
 */
final class EhiSyntheticPackage
{
    /** An ASCII identifier, so MARKER.tsv is still accounted as a table. */
    public const string MARKER = 'SYNTHETIC_SECRET_456';

    public const string PATIENT_KEY = 'Z00094567';

    public const string OTHER_PATIENT_KEY = 'Z00011112';

    public const int MEMBERS = 23;

    public const int TABLE_MEMBERS = 9;

    public const int DISTINCT_BLOBS = 22;

    private const array DICTIONARY = [
        'PATIENT' => [['PAT_ID', 'VARCHAR'], ['PAT_NAME', 'VARCHAR'], ['BIRTH_DATE', 'DATETIME (Local)'], ['PAT_MRN_ID', 'VARCHAR']],
        'PAT_ENC' => [['PAT_ID', 'VARCHAR'], ['PAT_ENC_CSN_ID', 'NUMERIC'], ['CONTACT_DATE', 'DATETIME (Local)']],
        'IMMUNE' => [['IMMUNE_ID', 'NUMERIC'], ['DOSE', 'VARCHAR'], ['NDC_CODE', 'VARCHAR']],
        'PAT_IMMUNIZATIONS' => [['PAT_ID', 'VARCHAR'], ['LINE', 'INTEGER'], ['IMMUNE_ID', 'NUMERIC']],
        'ORDER_RESULTS' => [['ORDER_PROC_ID', 'NUMERIC'], ['ORD_DATE_REAL', 'FLOAT'], ['LINE', 'INTEGER'], ['ORD_VALUE', 'VARCHAR']],
        'ORDER_PROC' => [['ORDER_PROC_ID', 'NUMERIC'], ['PAT_ID', 'VARCHAR'], ['PAT_ENC_CSN_ID', 'NUMERIC']],
        'HNO_INFO' => [['NOTE_ID', 'VARCHAR'], ['PAT_ENC_CSN_ID', 'NUMERIC']],
        'ARPB_TRANSACTIONS' => [['TX_ID', 'NUMERIC'], ['PAT_ID', 'VARCHAR'], ['AMOUNT', 'NUMERIC']],
        'PAT_ADDRESS' => [['PAT_ID', 'VARCHAR'], ['LINE', 'INTEGER'], ['ADDRESS', 'VARCHAR']],
    ];

    private const string MEDIA = "\x89PNG\r\n\x1A\nsynthetic-image-bytes";

    /** @param array<string, string> $files path => bytes */
    private function __construct(public array $files) {}

    public static function standard(): self
    {
        $key = self::PATIENT_KEY;
        $marker = self::MARKER;
        $files = [
            'PATIENT.tsv' => "PAT_ID\tPAT_NAME\tBIRTH_DATE\tSYNTH_EXTRA_COL\n{$key}\t{$marker} Person\t1970-01-01\tx\n",
            'PAT_ENC.tsv' => "PAT_ID\tPAT_ENC_CSN_ID\tCONTACT_DATE\n"
                ."{$key}\t1001\t2020-01-01\n"
                ."{$key}\t1002\t2020-02-01\n"
                ."{$key}\t1004\t2020-03-01\n",
            'IMMUNE.tsv' => "IMMUNE_ID\tDOSE\tNDC_CODE\n501\t0.5 mL {$marker}\t00000-0000-00\n502\t1 mL\t\n503\t{$marker}\n",
            'PAT_IMMUNIZATIONS.tsv' => "PAT_ID\tLINE\tIMMUNE_ID\n{$key}\t1\t501\n{$key}\t2\t502\n",
            'ORDER_RESULTS.tsv' => "ORDER_PROC_ID\tORD_DATE_REAL\tLINE\tORD_VALUE\n7001\t64000.01\t1\t13.2\n7001\t64000.02\t1\t{$marker}\n",
            'ORDER_PROC.tsv' => "ORDER_PROC_ID\tPAT_ID\tPAT_ENC_CSN_ID\n",
            'HNO_INFO.tsv' => '',
            'ARPB_TRANSACTIONS.tsv' => "TX_ID\tPAT_ID\tAMOUNT\n9001\t{$key}\t10.00\n",
            "{$marker}.tsv" => "SYNTH_A\tSYNTH_B\n{$marker}\t1\n",
            'index.html' => '<html><body><p>Synthetic export contents</p><a href="docs/PATIENT.htm">PATIENT</a></body></html>',
            'README.txt' => "Synthetic export readme {$marker}\n",
            "notes/note-{$marker}.rtf" => "{\\rtf1\\ansi Synthetic note {$marker}}",
            "media/scan-{$marker}.png" => self::MEDIA,
            "extra/{$marker}.bin" => self::MEDIA,
        ];
        foreach (self::DICTIONARY as $table => $columns) {
            $files["docs/{$table}.htm"] = self::dictionaryPage($table, $columns);
        }
        ksort($files, SORT_STRING);

        return new self($files);
    }

    /**
     * A package of exactly these files (path => bytes), for builders that
     * assemble their own tables and dictionary pages.
     *
     * @param  array<string, string>  $files
     */
    public static function fromFiles(array $files): self
    {
        ksort($files, SORT_STRING);

        return new self($files);
    }

    public function with(string $path, string $bytes): self
    {
        $files = $this->files;
        $files[$path] = $bytes;

        return new self($files);
    }

    public function without(string $path): self
    {
        $files = $this->files;
        unset($files[$path]);

        return new self($files);
    }

    /** A second, distinct PAT_ID value in one table. */
    public function withSecondPatient(): self
    {
        return $this->with('PAT_IMMUNIZATIONS.tsv', "PAT_ID\tLINE\tIMMUNE_ID\n".self::PATIENT_KEY."\t1\t501\n".self::OTHER_PATIENT_KEY."\t2\t502\n");
    }

    /** No PAT_ID value anywhere: each table with a PAT_ID column keeps only its header. */
    public function withoutPatientKeys(): self
    {
        return new self(array_map(static function (string $bytes): string {
            $header = strstr($bytes, "\n", true);

            return $header !== false && in_array('PAT_ID', explode("\t", $header), true) ? $header."\n" : $bytes;
        }, $this->files));
    }

    /** One table with a well-formed row whose PAT_ID cell is blank, beside the patient's own row. */
    public function withBlankPatientKey(): self
    {
        return $this->with('PAT_IMMUNIZATIONS.tsv', "PAT_ID\tLINE\tIMMUNE_ID\n".self::PATIENT_KEY."\t1\t501\n\t2\t502\n");
    }

    /** One table changed; every other member is byte-identical. */
    public function withChangedResults(): self
    {
        return $this->with('ORDER_RESULTS.tsv', "ORDER_PROC_ID\tORD_DATE_REAL\tLINE\tORD_VALUE\n7001\t64000.01\t1\t13.4\n");
    }

    public function writeDirectory(string $root): string
    {
        if (! is_dir($root) && ! mkdir($root, 0700, true)) {
            throw new RuntimeException('Could not create the synthetic package directory.');
        }
        foreach ($this->files as $path => $bytes) {
            $target = $root.'/'.$path;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            file_put_contents($target, $bytes);
        }

        return $root;
    }

    public function writeZip(string $path, int $compression = ZipArchive::CM_DEFLATE, bool $reverse = false): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the synthetic package ZIP.');
        }
        $files = $reverse ? array_reverse($this->files, true) : $this->files;
        foreach ($files as $name => $bytes) {
            $zip->addFromString((string) $name, $bytes);
            $zip->setCompressionName((string) $name, $compression);
        }
        $zip->close();

        return $path;
    }

    /** @param list<array{0: string, 1: string}> $columns */
    private static function dictionaryPage(string $table, array $columns): string
    {
        $rows = implode('', array_map(static fn (array $column): string => "<tr><td>{$column[0]}</td><td>{$column[1]}</td><td>Synthetic description</td></tr>", $columns));
        $key = $columns[0][0];

        return <<<HTML
<html><head><title>{$table}</title><meta charset="utf-8"></head><body>
<div class="header"><h1>{$table}</h1><p>Synthetic table description.</p></div>
<h2>Column Information</h2>
<table class="SubList"><tr><th>Column Name</th><th>Type</th><th>Description</th></tr>{$rows}</table>
<h2>Primary Key</h2>
<table class="SubList"><tr><th>Column Name</th></tr><tr><td>{$key}</td></tr></table>
</body></html>
HTML;
    }
}

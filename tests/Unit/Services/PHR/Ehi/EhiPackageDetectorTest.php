<?php

namespace Tests\Unit\Services\PHR\Ehi;

use App\Services\PHR\Import\Ehi\EhiDetection;
use App\Services\PHR\Import\Ehi\EhiEntry;
use App\Services\PHR\Import\Ehi\EhiPackageDetector;
use App\Services\PHR\Import\Ehi\EhiValidatedMember;
use PHPUnit\Framework\TestCase;

class EhiPackageDetectorTest extends TestCase
{
    public function test_tables_with_headers_and_documentation_are_ehi(): void
    {
        $detection = $this->detect([
            'Tables/SYNTH_ONE.tsv' => "SYN_ID\tSYN_DATE",
            'Tables/SYNTH_TWO.tsv' => "SYN_ID\tLINE",
            'Docs/index.html' => '<html>',
        ]);

        self::assertTrue($detection->isEhi);
        self::assertSame([EhiPackageDetector::SIGNAL_TSV_TABLES, EhiPackageDetector::SIGNAL_HTML_DOCUMENTATION], $detection->reasons);
        self::assertSame(2, $detection->tableCount);
        self::assertSame(1, $detection->dictionaryCount);
    }

    public function test_known_table_names_count_as_a_signal(): void
    {
        $detection = $this->detect([
            'PAT_ENC.tsv' => "PAT_ID\tPAT_ENC_CSN_ID",
            'Documentation/PAT_ENC.htm' => '<html>',
        ]);

        self::assertTrue($detection->isEhi);
        self::assertSame([EhiPackageDetector::SIGNAL_HTML_DOCUMENTATION, EhiPackageDetector::SIGNAL_EPIC_TABLE_NAMES], $detection->reasons);
    }

    public function test_a_single_signal_is_not_enough(): void
    {
        // Headed tables only.
        self::assertFalse($this->detect(['a.tsv' => "A\tB", 'b.tsv' => "C\tD"])->isEhi);
        // Documentation plus non-table files (a MyChart-like export).
        self::assertFalse($this->detect(['index.html' => '<html>', 'record.json' => null, 'ccd.xml' => null])->isEhi);
        // Headerless TSVs plus documentation: one signal.
        self::assertFalse($this->detect(['a.tsv' => 'just one value', 'b.tsv' => '', 'index.html' => '<html>'])->isEhi);
        // Documentation and a known name require at least one table member.
        self::assertFalse($this->detect(['PATIENT.htm' => '<html>', 'other.html' => '<html>'])->isEhi);
    }

    public function test_detect_zip_paths_uses_names_only(): void
    {
        self::assertTrue(EhiPackageDetector::detectZipPaths(['x/', 'x/A.tsv', 'x/B.tsv', 'x/doc/index.htm']));
        self::assertTrue(EhiPackageDetector::detectZipPaths(['ORDER_PROC.tsv', 'ORDER_RESULTS.tsv']));
        self::assertTrue(EhiPackageDetector::detectZipPaths(['PATIENT.txt', 'Docs\\PATIENT.html']));
        self::assertFalse(EhiPackageDetector::detectZipPaths(['IHE_XDM/SUBSET01/DOC0001.XML', 'IHE_XDM/SUBSET01/STYLE.XSL', 'INDEX.HTM', 'README.TXT']));
        self::assertFalse(EhiPackageDetector::detectZipPaths(['fhir/Patient.json', 'view.html']));
        self::assertFalse(EhiPackageDetector::detectZipPaths(['one.tsv', 'index.html', 'two.txt']));
        self::assertFalse(EhiPackageDetector::detectZipPaths(['one.tsv', 'two.csv']));
        self::assertFalse(EhiPackageDetector::detectZipPaths([]));
    }

    public function test_header_shape(): void
    {
        self::assertTrue(EhiPackageDetector::looksLikeHeader("\xEF\xBB\xBFPAT_ID\tLINE\r\n"));
        self::assertFalse(EhiPackageDetector::looksLikeHeader('PAT_ID'));
        self::assertFalse(EhiPackageDetector::looksLikeHeader("000123\tSynthetic value"));
    }

    /** @param  array<string, ?string>  $members */
    private function detect(array $members): EhiDetection
    {
        $validated = [];
        foreach ($members as $path => $line) {
            $validated[] = new EhiValidatedMember($path, new EhiEntry($path, 10));
        }

        return (new EhiPackageDetector)->detect($validated, fn (EhiValidatedMember $member): ?string => $members[$member->path]);
    }
}

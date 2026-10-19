<?php

namespace Tests\Feature\PHR\Ehi;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use ZipArchive;

class PhrEhiProbeCommandTest extends TestCase
{
    private const string MARKER = 'SYNTHETIC-SECRET-123';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ehi-probe-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_directory_probe_prints_structure_without_planted_values(): void
    {
        $dir = $this->writeTree('package', $this->syntheticPackage());

        foreach ([[], ['--json' => true]] as $extra) {
            $exit = Artisan::call('phr:ehi:probe', ['--dir' => $dir, ...$extra]);
            $output = Artisan::output();

            self::assertSame(0, $exit, $output);
            $this->assertSafe($output, $dir);
        }

        $report = $this->jsonReport(['--dir' => $dir]);
        self::assertNull($report['refused']);
        self::assertSame(11, $report['members']['total']);
        self::assertSame(4, $report['members']['by_kind']['table']);
        self::assertSame(1, $report['members']['by_kind']['dictionary']);
        self::assertSame(1, $report['members']['by_kind']['index']);
        self::assertSame(1, $report['members']['by_kind']['readme']);
        self::assertSame(1, $report['members']['by_kind']['note']);
        self::assertSame(1, $report['members']['by_kind']['media']);
        self::assertSame(2, $report['members']['by_kind']['unknown']);
        self::assertSame(1, $report['members']['by_extension']['(other)']);
        self::assertSame(1, $report['members']['bom_by_kind']['table']);
        self::assertTrue($report['detection']['is_ehi']);

        $tables = $report['tables'];
        self::assertSame(['empty' => 1, 'header_only' => 1, 'parsed' => 2, 'malformed' => 0], $tables['states']);
        self::assertTrue($tables['known_table_names_present']['PATIENT']);
        self::assertTrue($tables['known_table_names_present']['PAT_ENC']);
        self::assertFalse($tables['known_table_names_present']['HNO_INFO']);
        self::assertSame(2, $tables['pat_id']['distinct_values']);
        self::assertSame(2, $tables['pat_id']['tables_with_column']);
        self::assertSame(1, $tables['null_marker_candidates']['NULL']);
        self::assertSame(1, $tables['null_marker_candidates']['\\N']);
        self::assertSame(2, $tables['line_endings']['crlf']);
        self::assertSame(1, $tables['quotes']['records_with_quote_at_field_start']);
        self::assertSame(0, $tables['field_count_mismatch']['quote_none']);
        self::assertSame(1, $tables['field_count_mismatch']['rfc4180']);
        self::assertSame(1, $tables['invalid_utf8_records']);
        self::assertGreaterThanOrEqual(1, $tables['empty_cells']);
        self::assertSame(1, $tables['columns']['name_suffix_counts']['_DATE']);
        self::assertSame(1, $tables['dictionary_coverage']['tables_in_dictionary']);
        self::assertSame(1, $tables['dictionary_coverage']['header_columns_not_in_dictionary']);

        $dictionary = $report['dictionary'];
        self::assertTrue($dictionary['parsed']['primary_key_markers_found']);
        self::assertSame(1, $dictionary['parsed']['tables']);
        self::assertSame(2, $dictionary['parsed']['columns']);
        self::assertSame(1, $dictionary['parsed']['tables_with_primary_key']);
        self::assertArrayHasKey('table', $dictionary['outline']['tags']);
        self::assertArrayHasKey('(masked)', $dictionary['outline']['classes']);
        self::assertArrayHasKey('SubList', $dictionary['outline']['classes']);

        $readme = $report['readme_index'];
        self::assertSame(2, $readme['members']);
        self::assertSame(1, $readme['keywords']['version']);
        self::assertSame(1, $readme['keywords']['organization']);
        self::assertSame(1, $readme['keywords']['installation']);
        self::assertSame(0, $readme['keywords']['release']);
    }

    public function test_zip_probe_matches_directory_probe(): void
    {
        $files = $this->syntheticPackage();
        $dir = $this->writeTree('package', $files);
        $zip = $this->root.'/'.self::MARKER.'.zip';
        $archive = new ZipArchive;
        self::assertTrue($archive->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($files as $path => $bytes) {
            $archive->addFromString($path, $bytes);
        }
        $archive->close();

        $exit = Artisan::call('phr:ehi:probe', ['--zip' => $zip]);
        $output = Artisan::output();
        self::assertSame(0, $exit, $output);
        $this->assertSafe($output, $zip);

        $fromZip = $this->jsonReport(['--zip' => $zip]);
        $fromDir = $this->jsonReport(['--dir' => $dir]);
        self::assertSame($fromDir['tables'], $fromZip['tables']);
        self::assertSame($fromDir['dictionary'], $fromZip['dictionary']);
        self::assertSame($fromDir['members'], $fromZip['members']);
    }

    public function test_refused_package_prints_only_the_reason_code(): void
    {
        $dir = $this->writeTree('package', ['Tables/PATIENT.tsv' => "PAT_ID\n1\n"]);
        symlink('/etc/hostname', $dir.'/'.self::MARKER.'.tsv');

        $exit = Artisan::call('phr:ehi:probe', ['--dir' => $dir]);
        $output = Artisan::output();

        self::assertSame(1, $exit);
        self::assertStringContainsString('refused: symlink', $output);
        $this->assertSafe($output, $dir);
    }

    public function test_unreadable_input_and_bad_options(): void
    {
        self::assertSame(2, Artisan::call('phr:ehi:probe'));
        self::assertSame(2, Artisan::call('phr:ehi:probe', ['--dir' => $this->root, '--zip' => $this->root.'/x.zip']));

        $exit = Artisan::call('phr:ehi:probe', ['--zip' => $this->root.'/'.self::MARKER.'.zip']);
        $output = Artisan::output();
        self::assertSame(1, $exit);
        self::assertStringContainsString('refused: unreadable', $output);
        $this->assertSafe($output, $this->root);
    }

    private function assertSafe(string $output, string $path): void
    {
        self::assertStringNotContainsString(self::MARKER, $output);
        self::assertStringNotContainsString('SECRET', $output);
        self::assertStringNotContainsString('Synthetic', $output);
        self::assertStringNotContainsString('SYNTH_', $output);
        self::assertStringNotContainsString('PAT_NAME', $output);
        self::assertStringNotContainsString('000777', $output);
        self::assertStringNotContainsString('65000.01', $output);
        self::assertStringNotContainsString($path, $output);
        self::assertStringNotContainsString($this->root, $output);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function jsonReport(array $options): array
    {
        Artisan::call('phr:ehi:probe', [...$options, '--json' => true]);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private function syntheticPackage(): array
    {
        $m = self::MARKER;

        return [
            // Known table: BOM, a planted marker, the NULL and \N spellings, an empty cell.
            'Tables/PATIENT.tsv' => "\xEF\xBB\xBFPAT_ID\tPAT_NAME\tBIRTH_DATE\n000777\t{$m}\tNULL\n000778\t\\N\t\n",
            // Known table: CRLF, a quoted field that only splits under RFC 4180, invalid UTF-8.
            'Tables/PAT_ENC.tsv' => "PAT_ID\tPAT_ENC_CSN_ID\tORD_DATE_REAL\r\n000777\t1\t65000.01\r\n000778\t\"2\t{$m}\"\n000777\t3\t\xC3\x28\n",
            'Tables/SYNTH_'.$m.'.tsv' => "SYN_{$m}_ID\tSYN_VALUE\n",
            'Tables/SYNTH_EMPTY.tsv' => '',
            'Documentation/PATIENT.htm' => <<<HTML
<html><head><title>PATIENT</title><script>var leak = "{$m}";</script></head><body>
<div class="header {$m} SYNTH_CLASS"><h1>PATIENT</h1><p>Synthetic description {$m}</p></div>
<h2>Column Information</h2>
<table class="SubList"><tr><th>Column Name</th><th>Type</th><th>Description</th></tr>
<tr><td>PAT_ID</td><td>VARCHAR</td><td>{$m}</td></tr>
<tr><td>BIRTH_DATE</td><td>DATETIME (Local)</td><td>Synthetic</td></tr></table>
<h2>Primary Key</h2>
<table class="SubList"><tr><th>Column Name</th></tr><tr><td>PAT_ID</td></tr></table>
<{$m}>custom element</{$m}>
</body></html>
HTML,
            'Documentation/index.html' => "<html><body><p>Version: {$m}</p><p>Organization = {$m}</p></body></html>",
            'README.txt' => "Synthetic readme\nInstallation: {$m}\n",
            'Rich Text/'.$m.'.rtf' => '{\\rtf1 '.$m.'}',
            'Media/'.$m.'.png' => "\x89PNG synthetic",
            'Other/'.$m.'.json' => '{"k":"'.$m.'"}',
            'Other/data.'.$m => $m,
        ];
    }

    /** @param  array<string, string>  $files */
    private function writeTree(string $name, array $files): string
    {
        $base = $this->root.'/'.$name;
        foreach ($files as $path => $bytes) {
            $target = $base.'/'.$path;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            file_put_contents($target, $bytes);
        }

        return $base;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path.'/'.$entry);
            }
        }
        rmdir($path);
    }
}

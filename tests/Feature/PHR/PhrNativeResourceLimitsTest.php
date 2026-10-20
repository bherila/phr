<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Services\PHR\NativeBackup\NativeBackupException;
use App\Services\PHR\NativeBackup\NativeResourceBudget;
use App\Services\PHR\NativeBackup\NativeRestoreException;
use App\Services\PHR\NativeBackup\PhrNativeArchiveBuilder;
use App\Services\PHR\NativeBackup\PhrNativeRestoreArchiveReader;
use App\Services\PHR\NativeBackup\PhrNativeRestorePlanner;
use App\Services\PHR\NativeBackup\PhrNativeSnapshotService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class PhrNativeResourceLimitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('phr_documents');
    }

    public function test_writer_and_reader_share_exact_count_and_stream_byte_boundaries(): void
    {
        $patient = $this->patient();
        $this->document($patient);
        $built = app(PhrNativeArchiveBuilder::class)->build($patient);
        $zip = new ZipArchive;
        self::assertTrue($zip->open($built->path));
        $streamBytes = 0;
        foreach ($built->manifest['tables'] as $entry) {
            $streamBytes += $zip->statName($entry['path'])['size'];
        }
        $zip->close();
        $count = array_sum($built->counts);
        config(['phr.native_max_records' => $count, 'phr.native_max_record_stream_bytes' => $streamBytes]);
        $exact = app(PhrNativeArchiveBuilder::class)->build($patient);
        $archive = app(PhrNativeRestoreArchiveReader::class)->openLocal($exact->path);
        self::assertSame($count, array_sum(array_column($archive->manifest['tables'], 'count')));
        unset($archive);
        config(['phr.native_max_records' => $count - 1]);
        $this->assertBackupRefused($patient, 'record_count_limit');
        $this->assertReaderRefused($built->path, 'record_count_limit');
        config(['phr.native_max_records' => $count, 'phr.native_max_record_stream_bytes' => $streamBytes - 1]);
        $this->assertBackupRefused($patient, 'record_stream_size_limit');
        $this->assertReaderRefused($built->path, 'record_stream_size_limit');
        @unlink($built->path);
    }

    public function test_a_forged_manifest_count_cannot_bypass_actual_record_limits(): void
    {
        $patient = $this->patient();
        $this->document($patient);
        $built = app(PhrNativeArchiveBuilder::class)->build($patient);
        $zip = new ZipArchive;
        self::assertTrue($zip->open($built->path));
        $manifest = $built->manifest;
        $manifest['tables']['phr_documents']['count'] = 0;
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $zip->close();
        config(['phr.native_max_records' => array_sum($built->counts) - 1]);
        $this->assertReaderRefused($built->path, 'record_count_limit');
        @unlink($built->path);
    }

    public function test_record_limit_charges_the_actual_canonical_line_including_newline(): void
    {
        $patient = $this->patient();
        $this->document($patient, str_repeat('synthetic ', 300));
        $built = app(PhrNativeArchiveBuilder::class)->build($patient);
        $zip = new ZipArchive;
        self::assertTrue($zip->open($built->path));
        $max = 0;
        foreach ($built->manifest['tables'] as $entry) {
            foreach (explode("\n", rtrim((string) $zip->getFromName($entry['path']), "\n")) as $line) {
                $max = max($max, strlen($line) + 1);
            }
        }
        $zip->close();
        config(['phr.native_restore_max_record_bytes' => $max]);
        $exact = app(PhrNativeArchiveBuilder::class)->build($patient);
        $archive = app(PhrNativeRestoreArchiveReader::class)->openLocal($exact->path);
        unset($archive);
        config(['phr.native_restore_max_record_bytes' => $max - 1]);
        $this->assertBackupRefused($patient, 'record_size_limit');
        $this->assertReaderRefused($built->path, 'record_size_limit');
        @unlink($built->path);
    }

    public function test_payload_pages_are_limited_by_bytes_before_hydration(): void
    {
        $patient = $this->patient();
        for ($i = 0; $i < 4; $i++) {
            $this->document($patient, str_repeat('x', 2000));
        }
        config(['phr.native_page_bytes' => 3000, 'phr.native_backup_chunk_size' => 500]);
        $pages = [];
        $snapshot = app(PhrNativeSnapshotService::class);
        $snapshot->snapshot($patient->id, false, function () use ($snapshot, $patient, &$pages): void {
            foreach ($snapshot->chunks($patient->id, 'phr_documents') as $rows) {
                $pages[] = count($rows);
            }
        });
        self::assertSame([1, 1, 1, 1], $pages);
    }

    public function test_artifact_metadata_and_manifest_limits_are_explicit_resource_refusals(): void
    {
        $patient = $this->patient();
        foreach (['one', 'two'] as $name) {
            $doc = $this->document($patient);
            Storage::disk('phr_documents')->put($name, 'synthetic');
            $doc->forceFill(['storage_path' => $name, 'byte_size' => 9, 'file_hash' => hash('sha256', 'synthetic')])->save();
        }
        $built = app(PhrNativeArchiveBuilder::class)->build($patient);
        config(['phr.native_max_artifacts' => 1]);
        $this->assertBackupRefused($patient, 'artifact_metadata_limit');
        $this->assertReaderRefused($built->path, 'artifact_metadata_limit');
        config(['phr.native_max_artifacts' => 2000]);
        $zip = new ZipArchive;
        self::assertTrue($zip->open($built->path));
        $manifest = $built->manifest;
        $manifest['synthetic_padding'] = str_repeat('x', NativeResourceBudget::MANIFEST_BYTES);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $zip->close();
        $this->assertReaderRefused($built->path, 'manifest_size_limit');
        @unlink($built->path);
    }

    public function test_planning_a_small_archive_never_fetches_unrelated_target_identity_rows(): void
    {
        $patient = $this->patient();
        $this->document($patient);
        $built = app(PhrNativeArchiveBuilder::class)->build($patient);
        for ($i = 1; $i <= 600; $i++) {
            DB::table('phr_native_record_identities')->insert(['patient_id' => $patient->id, 'record_table' => 'phr_lab_results', 'record_id' => $i, 'native_id' => (string) Str::uuid()]);
        }
        $unbounded = [];
        DB::listen(static function (QueryExecuted $event) use (&$unbounded): void {
            if (str_starts_with($event->sql, 'select') && str_contains($event->sql, 'from "phr_native_record_identities"')
                && ! str_contains($event->sql, '"native_id" = ?') && ! str_contains($event->sql, '"record_id" = ?')) {
                $unbounded[] = $event->sql;
            }
        });
        $archive = app(PhrNativeRestoreArchiveReader::class)->openLocal($built->path);
        $plan = app(PhrNativeRestorePlanner::class)->plan($archive, $patient->owner_user_id, false);
        self::assertSame([], $plan->blockers);
        self::assertSame([], $unbounded);
        self::assertSame(1, $plan->tables['phr_documents']['skip']);
    }

    private function patient(): PhrPatient
    {
        return PhrPatient::create(['owner_user_id' => $this->createUser()->id, 'display_name' => 'Synthetic Resource Fixture', 'relationship' => 'self']);
    }

    private function document(PhrPatient $patient, string $text = 'synthetic'): PhrDocument
    {
        return PhrDocument::create(['patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'title' => 'Synthetic Resource Document', 'document_type' => 'other', 'extracted_text' => $text, 'byte_size' => 0]);
    }

    private function assertBackupRefused(PhrPatient $patient, string $reason): void
    {
        try {
            $built = app(PhrNativeArchiveBuilder::class)->build($patient);
            @unlink($built->path);
            self::fail('Backup must be refused.');
        } catch (NativeBackupException $exception) {
            self::assertSame($reason, $exception->failureCategory);
        }
    }

    private function assertReaderRefused(string $path, string $reason): void
    {
        $copy = tempnam(sys_get_temp_dir(), 'phr-resource-test-');
        copy($path, $copy);
        try {
            app(PhrNativeRestoreArchiveReader::class)->openLocal($copy);
            self::fail('Restore must be refused.');
        } catch (NativeRestoreException $exception) {
            self::assertSame($reason, $exception->failureCategory);
        } finally {
            @unlink($copy);
        }
    }
}

<?php

namespace Tests\Feature\PHR\Ehi;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\Support\EhiCapacityMetrics;
use Tests\TestCase;

final class EhiCapacityMetricsTest extends TestCase
{
    public function test_runtime_provenance_is_durable_before_work_and_cannot_be_replaced_by_later_limits(): void
    {
        $directory = sys_get_temp_dir().'/phr-capacity-metrics-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            config(['phr.ehi_preview_max_rows' => 123, 'phr.native_max_records' => 700]);
            $metrics = new EhiCapacityMetrics($directory);
            $metrics->startQualification();
            $initial = json_decode(file_get_contents($directory.'/qualification-progress.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame([], $initial['stages']);
            self::assertSame(PHP_VERSION, $initial['php_version']);
            self::assertSame(DB::connection()->getDriverName(), $initial['database_driver']);
            self::assertSame(hash_file('sha256', base_path('composer.lock')), $initial['composer_lock_sha256']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/D', $initial['candidate']);
            self::assertSame(ini_get('memory_limit'), $initial['php_memory_limit']);

            config(['phr.ehi_preview_max_rows' => 999, 'phr.native_max_records' => 17]);
            $metrics->startQualification();
            $metrics->nativeBoundary(17);
            $metrics->completedArchive(17, 1024);
            $metrics->stage('inspection', function () use ($directory): void {
                $active = json_decode(file_get_contents($directory.'/qualification-active.json'), true, flags: JSON_THROW_ON_ERROR);
                $progress = json_decode(file_get_contents($directory.'/qualification-progress.json'), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame('inspection', $active['stage']);
                self::assertTrue($active['metrics_incomplete']);
                self::assertSame(17, $progress['completed_archive']['archive_records']);
                self::assertNull($progress['completed_archive']['record_stream_bytes']);
                self::assertFalse($progress['completed_archive']['download_completed']);
            });
            $latest = json_decode(file_get_contents($directory.'/qualification-progress.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($initial['runtime_provenance'], $latest['runtime_provenance']);
            self::assertSame(123, $latest['limits']['rows']);
            self::assertSame(17, $latest['limits']['native_records']);
            self::assertSame(700, $latest['runtime_provenance']['limits']['native_records']);
            self::assertArrayHasKey('inspection', $latest['stages']);
            self::assertArrayNotHasKey('lifecycle', $latest);

            $metrics->completedDownload(2048);
            $latest = json_decode(file_get_contents($directory.'/qualification-progress.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(2048, $latest['completed_archive']['record_stream_bytes']);
            self::assertTrue($latest['completed_archive']['download_completed']);
            self::assertSame($initial['runtime_provenance'], $latest['runtime_provenance']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_an_inspection_failure_retains_its_named_stage_and_does_not_create_archive_evidence(): void
    {
        $directory = sys_get_temp_dir().'/phr-capacity-metrics-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $metrics = new EhiCapacityMetrics($directory);
            try {
                $metrics->stage('inspection', function () use ($directory): void {
                    $marker = json_decode(file_get_contents($directory.'/qualification-stage.json'), true, flags: JSON_THROW_ON_ERROR);
                    self::assertSame('inspection', $marker['stage']);
                    throw new RuntimeException('Synthetic interrupted inspection.');
                });
                self::fail('The inspection failure must propagate.');
            } catch (RuntimeException $failure) {
                self::assertSame('Synthetic interrupted inspection.', $failure->getMessage());
            }
            $progress = json_decode(file_get_contents($directory.'/qualification-progress.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('inspection', $progress['stages']);
            self::assertArrayNotHasKey('completed_archive', $progress);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}

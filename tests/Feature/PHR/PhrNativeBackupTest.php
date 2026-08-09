<?php

namespace Tests\Feature\PHR;

use App\Jobs\PHR\GeneratePhrNativeBackupJob;
use App\Models\PhrDocument;
use App\Models\PhrNativeBackup;
use App\Models\PhrNativeBackupAudit;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\NativeBackup\NativeBackupException;
use App\Services\PHR\NativeBackup\PhrNativeBackupCatalog;
use App\Services\PHR\NativeBackup\PhrNativeBackupService;
use App\Services\PHR\NativeBackup\PhrNativeSnapshotService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\PhrNativeBackupTestReader;
use Tests\TestCase;
use ZipArchive;

class PhrNativeBackupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('phr_documents');
        Storage::fake('phr_dicom');
        Storage::fake('phr_exports');
        Queue::fake();
    }

    public function test_native_backup_is_owner_only_queued_and_private(): void
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $unrelatedUser = $this->createUser();
        $patient = $this->createPatient($owner);

        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $manager->id,
            'access_level' => PhrPatientUserAccess::LEVEL_MANAGER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);

        $this->actingAs($manager)
            ->postJson("/api/phr/patients/{$patient->id}/native-backups")
            ->assertForbidden();
        $this->actingAs($unrelatedUser)
            ->postJson("/api/phr/patients/{$patient->id}/native-backups")
            ->assertNotFound();

        $response = $this->actingAs($owner)
            ->postJson("/api/phr/patients/{$patient->id}/native-backups")
            ->assertAccepted()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonPath('backup.format', PhrNativeBackupCatalog::FORMAT)
            ->assertJsonPath('backup.schema_version', PhrNativeBackupCatalog::SCHEMA_VERSION)
            ->assertJsonPath('backup.status', PhrNativeBackup::STATUS_PENDING)
            ->assertJsonPath('backup.download_url', null);

        $backupId = (int) $response->json('backup.id');
        $this->assertNull(PhrNativeBackup::query()->findOrFail($backupId)->expires_at);
        Queue::assertPushed(
            GeneratePhrNativeBackupJob::class,
            fn (GeneratePhrNativeBackupJob $job): bool => $job->backupId === $backupId
                && $job->queue === 'phr-exports',
        );

        $this->actingAs($owner)
            ->getJson("/api/phr/patients/{$patient->id}/native-backups")
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonPath('backups.0.id', $backupId);
    }

    public function test_active_backup_is_reused_but_every_completed_backup_request_gets_a_new_snapshot(): void
    {
        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        $service = app(PhrNativeBackupService::class);
        $original = $service->createQueuedBackup($patient, (int) $owner->id);

        $this->assertSame($original->id, $service->createQueuedBackup($patient, (int) $owner->id)->id);

        $original->update(['status' => PhrNativeBackup::STATUS_PROCESSING]);
        $this->assertSame($original->id, $service->createQueuedBackup($patient, (int) $owner->id)->id);

        $original->update([
            'status' => PhrNativeBackup::STATUS_READY,
            'storage_path' => 'phr/native-backups/fixture/current.zip',
            'generated_at' => now()->addSecond(),
            'expires_at' => now()->addHour(),
        ]);
        $replacement = $service->createQueuedBackup($patient, (int) $owner->id);

        $this->assertNotSame($original->id, $replacement->id);
        $this->assertSame(PhrNativeBackup::STATUS_PENDING, $replacement->status);
        $this->assertSame(2, PhrNativeBackup::query()->where('patient_id', $patient->id)->count());
        Queue::assertPushed(GeneratePhrNativeBackupJob::class, 2);
    }

    public function test_abandoned_active_backup_is_redispatched_after_its_lease_expires(): void
    {
        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        $service = app(PhrNativeBackupService::class);
        $backup = $service->createQueuedBackup($patient, (int) $owner->id);

        DB::table('phr_native_backups')->where('id', $backup->id)->update([
            'updated_at' => now()->subMinutes(16),
        ]);
        $redispatched = $service->createQueuedBackup($patient, (int) $owner->id);

        $this->assertSame($backup->id, $redispatched->id);
        $this->assertTrue($redispatched->updated_at?->isAfter(now()->subMinute()));
        Queue::assertPushed(GeneratePhrNativeBackupJob::class, 2);
    }

    public function test_native_backup_job_drops_overlapping_dispatches(): void
    {
        $job = new GeneratePhrNativeBackupJob(123);
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertSame('phr-native-backup:123', $middleware[0]->key);
        $this->assertNull($middleware[0]->releaseAfter);
        $this->assertSame(360, $middleware[0]->expiresAfter);
    }

    public function test_all_relational_snapshot_queries_run_inside_one_transaction(): void
    {
        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        $outsideLevel = DB::transactionLevel();
        $transactionLevels = [];
        DB::listen(function (QueryExecuted $query) use (&$transactionLevels): void {
            if (str_contains($query->sql, 'phr_')) {
                $transactionLevels[] = DB::transactionLevel();
            }
        });

        $rows = app(PhrNativeSnapshotService::class)->rows($patient->id);

        $this->assertSame(array_keys(PhrNativeBackupCatalog::included()), array_keys($rows));
        $this->assertNotEmpty($transactionLevels);
        $this->assertNotContains($outsideLevel, $transactionLevels);
        $this->assertSame($outsideLevel, DB::transactionLevel());
    }

    public function test_worker_failure_marks_active_backup_terminal_and_preserves_domain_failure(): void
    {
        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        $backup = $this->newBackup($patient, $owner);
        $backup->update(['status' => PhrNativeBackup::STATUS_PROCESSING]);
        $job = new GeneratePhrNativeBackupJob($backup->id);

        $this->assertTrue($job->failOnTimeout);
        $job->failed(new \RuntimeException('Synthetic worker failure'));

        $this->assertDatabaseHas('phr_native_backups', [
            'id' => $backup->id,
            'status' => PhrNativeBackup::STATUS_FAILED,
            'failure_category' => 'queue_failure',
        ]);
        $this->assertDatabaseHas('phr_native_backup_audits', [
            'patient_root_id' => $patient->id,
            'outcome' => 'failed',
            'failure_category' => 'queue_failure',
        ]);

        $backup->update(['failure_category' => 'size_limit']);
        $job->failed(new \RuntimeException('Synthetic retry exhaustion'));
        $this->assertSame('size_limit', $backup->refresh()->failure_category);
    }

    public function test_patient_api_deletion_removes_native_archive_before_rows_cascade(): void
    {
        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        $path = 'phr/native-backups/fixture/patient-delete.zip';
        Storage::disk('phr_exports')->put($path, 'synthetic archive');
        $backup = PhrNativeBackup::query()->create([
            'patient_id' => $patient->id,
            'requested_by_user_id' => $owner->id,
            'status' => PhrNativeBackup::STATUS_READY,
            'schema_version' => PhrNativeBackupCatalog::SCHEMA_VERSION,
            'storage_disk' => 'phr_exports',
            'storage_path' => $path,
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patient->id}")->assertNoContent();

        Storage::disk('phr_exports')->assertMissing($path);
        $this->assertDatabaseMissing('phr_native_backups', ['id' => $backup->id]);
        $this->assertDatabaseMissing('phr_patients', ['id' => $patient->id]);
    }

    public function test_patient_deletion_waits_for_processing_backup_finalization(): void
    {
        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        $backup = $this->newBackup($patient, $owner);
        $backup->update(['status' => PhrNativeBackup::STATUS_PROCESSING]);

        $this->actingAs($owner)
            ->deleteJson("/api/phr/patients/{$patient->id}")
            ->assertConflict();

        $this->assertDatabaseHas('phr_native_backups', [
            'id' => $backup->id,
            'status' => PhrNativeBackup::STATUS_PROCESSING,
        ]);
        $this->assertDatabaseHas('phr_patients', ['id' => $patient->id]);

        DB::table('phr_native_backups')->where('id', $backup->id)->update([
            'updated_at' => now()->subMinutes(16),
        ]);
        $this->actingAs($owner)
            ->deleteJson("/api/phr/patients/{$patient->id}")
            ->assertNoContent();
        $this->assertDatabaseMissing('phr_patients', ['id' => $patient->id]);
    }

    public function test_partial_archive_cleanup_failure_never_restores_a_row_for_deleted_bytes(): void
    {
        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        $first = $this->readyBackup($patient, $owner, 'phr/native-backups/fixture/first.zip');
        $second = $this->readyBackup($patient, $owner, 'phr/native-backups/fixture/second.zip');

        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->once()->with((string) $first->storage_path)->andReturnTrue()->ordered();
        $disk->shouldReceive('delete')->once()->with((string) $second->storage_path)->andReturnFalse()->ordered();
        Storage::shouldReceive('disk')->twice()->with('phr_exports')->andReturn($disk);

        $this->actingAs($owner)
            ->deleteJson("/api/phr/patients/{$patient->id}")
            ->assertServerError();

        $this->assertDatabaseMissing('phr_native_backups', ['id' => $first->id]);
        $this->assertDatabaseHas('phr_native_backups', ['id' => $second->id]);
        $this->assertDatabaseHas('phr_patients', ['id' => $patient->id]);
    }

    public function test_archive_round_trips_every_catalog_table_with_stable_hashes_and_artifacts(): void
    {
        $owner = $this->createUser();
        $reviewer = $this->createUser();
        $patient = $this->seedCompletePatientGraph($owner, $reviewer);

        $backup = $this->generate($patient, $owner);
        $this->assertNotNull($backup->generated_at);
        $this->assertTrue($backup->expires_at?->between(
            now()->addDays(7)->subMinute(),
            now()->addDays(7)->addMinute(),
        ));
        $firstManifest = $this->manifest($backup);
        $archivePath = Storage::disk('phr_exports')->path((string) $backup->storage_path);

        $this->assertSame(PhrNativeBackupCatalog::FORMAT, $firstManifest['format']);
        $this->assertSame(PhrNativeBackupCatalog::SCHEMA_VERSION, $firstManifest['schemaVersion']);
        $this->assertSame('durable-opaque-uuid', $firstManifest['identity']['scheme']);
        $this->assertTrue($firstManifest['container']['zip64']);
        $this->assertSame('stored-artifacts', $firstManifest['container']['compression']);
        $this->assertSame('fail_closed', $firstManifest['container']['oversizePolicy']);
        $this->assertSame('included_with_deleted_at_and_source_bytes', $firstManifest['decisions']['softDeletedDocuments']);
        $this->assertSame('review_only_never_auto_restore', $firstManifest['decisions']['accessGrants']);
        $expectedTables = array_keys(PhrNativeBackupCatalog::included());
        $manifestTables = array_keys($firstManifest['tables']);
        sort($expectedTables);
        sort($manifestTables);
        $this->assertSame($expectedTables, $manifestTables);
        $expectedExclusions = array_keys(PhrNativeBackupCatalog::excluded());
        $manifestExclusions = array_keys($firstManifest['exclusions']['tables']);
        sort($expectedExclusions);
        sort($manifestExclusions);
        $this->assertSame($expectedExclusions, $manifestExclusions);
        $this->assertSame(2, $firstManifest['tables']['phr_documents']['count']);
        $this->assertSame(2, $firstManifest['tables']['phr_patient_user_access']['count']);
        $this->assertSame(2, count($firstManifest['artifacts']));
        $this->assertSame(['dicom', 'document'], $this->artifactKinds($firstManifest));

        $downloadUrl = URL::temporarySignedRoute(
            'phr.native-backups.download',
            now()->addMinutes(5),
            ['backup' => $backup->id],
        );
        $this->actingAs($reviewer)->get($downloadUrl)->assertForbidden();
        $this->actingAs($owner)
            ->get($downloadUrl)
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertDownload('phr-native-v1-backup.zip');

        $accessRecords = $this->archiveEntry($archivePath, $firstManifest['tables']['phr_patient_user_access']['path']);
        $this->assertStringNotContainsString((string) $reviewer->email, $accessRecords);
        $this->assertStringNotContainsString((string) $reviewer->name, $accessRecords);
        $this->assertStringContainsString('"access_level":"viewer"', $accessRecords);

        $documentRecords = $this->archiveEntry($archivePath, $firstManifest['tables']['phr_documents']['path']);
        $this->assertStringContainsString('"deleted_at":', $documentRecords);

        $this->assertSame(
            [
                'id', 'actor_user_id', 'patient_root_id', 'operation', 'schema_version',
                'archive_sha256', 'counts_json', 'outcome', 'failure_category', 'created_at', 'updated_at',
            ],
            array_keys(PhrNativeBackupAudit::query()->sole()->getAttributes()),
        );

        // Empty the PHR domain while retaining the private archive bytes. Audit rows
        // intentionally survive because they have no cascading patient foreign key.
        $patient->delete();
        $this->assertSame(0, PhrPatient::query()->count());
        foreach (array_keys(PhrNativeBackupCatalog::included()) as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' was not emptied');
        }

        $restoredOwner = $this->createUser();
        $restoredPatientId = (new PhrNativeBackupTestReader($archivePath))->restore(
            $restoredOwner,
            restoreAccessGrants: true, // Explicit test-only proof; production restore is out of scope.
        );

        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $restoredPatient = PhrPatient::query()->findOrFail($restoredPatientId);
        $secondBackup = $this->generate($restoredPatient, $restoredOwner);
        $secondManifest = $this->manifest($secondBackup);

        foreach (array_keys(PhrNativeBackupCatalog::included()) as $table) {
            $this->assertSame($firstManifest['tables'][$table]['count'], $secondManifest['tables'][$table]['count'], $table.' count changed');
            $this->assertSame($firstManifest['tables'][$table]['sha256'], $secondManifest['tables'][$table]['sha256'], $table.' content hash changed');
        }

        $projectArtifact = static fn (array $artifact): array => array_intersect_key(
            $artifact,
            array_flip(['kind', 'size', 'sha256']),
        );
        $firstArtifacts = collect($firstManifest['artifacts'])->keyBy('recordNativeId')->map($projectArtifact)->all();
        $secondArtifacts = collect($secondManifest['artifacts'])->keyBy('recordNativeId')->map($projectArtifact)->all();
        $this->assertSame($firstArtifacts, $secondArtifacts);
    }

    public function test_missing_object_and_size_ceiling_fail_without_leaking_storage_details(): void
    {
        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
            'title' => 'Synthetic missing artifact',
            'document_type' => 'other',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => 'fixtures/missing-artifact.bin',
            'mime_type' => 'application/octet-stream',
            'byte_size' => 16,
            'file_hash' => str_repeat('0', 64),
            'source' => 'manual_upload',
        ]);

        $missing = $this->newBackup($patient, $owner);
        try {
            app(PhrNativeBackupService::class)->generate($missing);
            $this->fail('A missing artifact must fail closed.');
        } catch (NativeBackupException $exception) {
            $this->assertSame('artifact_unreadable', $exception->failureCategory);
            $this->assertSame('Native backup generation failed.', $exception->getMessage());
            $this->assertStringNotContainsString('missing-artifact', $exception->getMessage());
        }
        $this->assertDatabaseHas('phr_native_backups', [
            'id' => $missing->id,
            'status' => PhrNativeBackup::STATUS_FAILED,
            'storage_path' => null,
            'failure_category' => 'artifact_unreadable',
        ]);
        $this->assertDatabaseHas('phr_native_backup_audits', [
            'patient_root_id' => $patient->id,
            'outcome' => 'failed',
            'failure_category' => 'artifact_unreadable',
        ]);

        PhrDocument::query()->forceDelete();
        $bytes = 'synthetic-ceiling-payload';
        Storage::disk('phr_documents')->put('fixtures/ceiling-artifact.bin', $bytes);
        PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'title' => 'Synthetic ceiling artifact',
            'document_type' => 'other',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => 'fixtures/ceiling-artifact.bin',
            'mime_type' => 'application/octet-stream',
            'byte_size' => strlen($bytes),
            'file_hash' => hash('sha256', $bytes),
            'source' => 'manual_upload',
        ]);
        config(['phr.native_backup_max_uncompressed_bytes' => strlen($bytes) - 1]);

        $oversize = $this->newBackup($patient, $owner);
        try {
            app(PhrNativeBackupService::class)->generate($oversize);
            $this->fail('An oversized archive must fail closed.');
        } catch (NativeBackupException $exception) {
            $this->assertSame('size_limit', $exception->failureCategory);
        }
        $this->assertSame(PhrNativeBackup::STATUS_FAILED, $oversize->refresh()->status);
        $this->assertNull($oversize->storage_path);
        Storage::disk('phr_exports')->assertDirectoryEmpty('phr/native-backups');
    }

    public function test_expired_native_archive_is_pruned_from_storage_and_database(): void
    {
        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        $path = 'phr/native-backups/fixture/expired.zip';
        Storage::disk('phr_exports')->put($path, 'synthetic archive');
        $backup = PhrNativeBackup::query()->create([
            'patient_id' => $patient->id,
            'requested_by_user_id' => $owner->id,
            'status' => PhrNativeBackup::STATUS_READY,
            'schema_version' => PhrNativeBackupCatalog::SCHEMA_VERSION,
            'storage_disk' => 'phr_exports',
            'storage_path' => $path,
            'expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($owner)
            ->getJson("/api/phr/patients/{$patient->id}/native-backups")
            ->assertOk()
            ->assertJsonPath('backups.0.status', PhrNativeBackup::STATUS_READY)
            ->assertJsonPath('backups.0.download_url', null);

        $this->artisan('phr:native-backups:purge')->assertSuccessful();

        Storage::disk('phr_exports')->assertMissing($path);
        $this->assertDatabaseMissing('phr_native_backups', ['id' => $backup->id]);
    }

    private function createPatient(User $owner): PhrPatient
    {
        return PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Synthetic Patient',
            'relationship' => 'self',
            'birth_date' => '2000-01-01',
            'sex_at_birth' => 'unknown',
            'notes' => 'Synthetic round-trip fixture.',
        ]);
    }

    private function seedCompletePatientGraph(User $owner, User $reviewer): PhrPatient
    {
        $patient = $this->createPatient($owner);
        $now = '2026-01-02 03:04:05';
        foreach ([
            [$owner->id, PhrPatientUserAccess::LEVEL_OWNER],
            [$reviewer->id, PhrPatientUserAccess::LEVEL_VIEWER],
        ] as [$userId, $level]) {
            DB::table('phr_patient_user_access')->insert([
                'patient_id' => $patient->id,
                'user_id' => $userId,
                'access_level' => $level,
                'granted_by_user_id' => $owner->id,
                'granted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $documentBytes = 'synthetic document bytes';
        Storage::disk('phr_documents')->put('fixtures/document-active.bin', $documentBytes);
        $documentId = DB::table('phr_documents')->insertGetId([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
            'title' => 'Synthetic source document',
            'document_type' => 'other',
            'original_filename' => 'fixture-document.bin',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => 'fixtures/document-active.bin',
            'mime_type' => 'application/octet-stream',
            'byte_size' => strlen($documentBytes),
            'file_hash' => hash('sha256', $documentBytes),
            'source' => 'manual_upload',
            'tags' => json_encode(['synthetic'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('phr_documents')->insert([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'title' => 'Synthetic deleted metadata',
            'document_type' => 'other',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => null,
            'byte_size' => 0,
            'source' => 'manual_upload',
            'deleted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $uploadId = DB::table('phr_dicom_uploads')->insertGetId([
            'patient_id' => $patient->id,
            'uploaded_by_user_id' => $owner->id,
            'status' => 'processed',
            'original_root_name' => 'synthetic-study',
            'total_files' => 2,
            'stored_files' => 2,
            'total_bytes' => 40,
            'stored_bytes' => 40,
            'r2_prefix' => 'fixtures/dicom/',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $studyId = DB::table('phr_dicom_studies')->insertGetId([
            'patient_id' => $patient->id,
            'upload_id' => $uploadId,
            'study_instance_uid' => 'synthetic-study-uid',
            'description' => 'Synthetic study',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $seriesId = DB::table('phr_dicom_series')->insertGetId([
            'patient_id' => $patient->id,
            'study_id' => $studyId,
            'series_instance_uid' => 'synthetic-series-uid',
            'modality' => 'OT',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $dicomBytes = 'synthetic original dicom bytes';
        Storage::disk('phr_dicom')->put('fixtures/dicom/original.dcm', $dicomBytes);
        $fileId = DB::table('phr_dicom_files')->insertGetId([
            'patient_id' => $patient->id,
            'upload_id' => $uploadId,
            'file_kind' => 'dicom',
            'r2_key' => 'fixtures/dicom/original.dcm',
            'original_relative_path' => 'synthetic/original.dcm',
            'original_path_hash' => hash('sha256', 'synthetic/original.dcm'),
            'original_filename' => 'synthetic-original.dcm',
            'mime_type' => 'application/dicom',
            'file_size_bytes' => strlen($dicomBytes),
            'sha256' => hash('sha256', $dicomBytes),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $derivedBytes = 'synthetic derived cache';
        Storage::disk('phr_dicom')->put('fixtures/dicom/derived.bin', $derivedBytes);
        DB::table('phr_dicom_files')->insert([
            'patient_id' => $patient->id,
            'upload_id' => $uploadId,
            'file_kind' => 'volume',
            'r2_key' => 'fixtures/dicom/derived.bin',
            'original_relative_path' => 'synthetic/derived.bin',
            'original_path_hash' => hash('sha256', 'synthetic/derived.bin'),
            'original_filename' => 'synthetic-derived.bin',
            'mime_type' => 'application/octet-stream',
            'file_size_bytes' => strlen($derivedBytes),
            'sha256' => hash('sha256', $derivedBytes),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('phr_dicom_instances')->insert([
            'patient_id' => $patient->id,
            'study_id' => $studyId,
            'series_id' => $seriesId,
            'upload_id' => $uploadId,
            'file_id' => $fileId,
            'sop_instance_uid' => 'synthetic-instance-uid',
            'instance_number' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $logId = DB::table('phr_health_logs')->insertGetId([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'name' => 'Synthetic log',
            'kind' => 'symptom',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('phr_health_log_entries')->insert([
            'health_log_id' => $logId,
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'recorded_by_user_id' => $reviewer->id,
            'occurred_at' => $now,
            'title' => 'Synthetic entry',
            'tags' => json_encode(['fixture'], JSON_THROW_ON_ERROR),
            'details' => json_encode(['value' => 'synthetic'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $clinicalRows = [
            'phr_lab_results' => ['test_name' => 'Synthetic test', 'analyte' => 'Synthetic analyte', 'value_numeric' => '1.2500000000'],
            'phr_patient_vitals' => ['vital_name' => 'Synthetic vital', 'vital_value' => '1', 'value_numeric' => '1.0000000000'],
            'phr_office_visits' => ['visit_type' => 'Synthetic visit'],
            'phr_medications' => ['name' => 'Synthetic medication'],
            'phr_conditions' => ['name' => 'Synthetic condition'],
            'phr_procedures' => ['name' => 'Synthetic procedure'],
            'phr_immunizations' => ['vaccine_name' => 'Synthetic immunization'],
            'phr_allergies' => ['substance' => 'Synthetic substance'],
        ];
        foreach ($clinicalRows as $table => $values) {
            DB::table($table)->insert($values + [
                'patient_id' => $patient->id,
                'user_id' => $owner->id,
                'source_document_id' => $documentId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('phr_portal_messages')->insert([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'source_document_id' => null,
            'subject' => 'Synthetic message without source document',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('phr_negative_assertions')->insert([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'source_document_id' => null,
            'assertion_type' => 'synthetic_absence',
            'statement' => 'Synthetic assertion without source document.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('phr_respiratory_events')->insert([
            'phr_patient_id' => $patient->id,
            'client_event_uuid' => 'synthetic-event-uuid',
            'event_type' => 'cough',
            'occurred_at' => $now,
            'tz_offset_min' => 0,
            'burst_count' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('phr_sinus_settings')->insert([
            'phr_patient_id' => $patient->id,
            'settings' => json_encode(['sensitivity' => 0.5], JSON_THROW_ON_ERROR),
            'settings_updated_at' => $now,
            'received_at' => $now,
            'updated_by_device' => 'synthetic-device',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('phr_sinus_enrollments')->insert([
            'phr_patient_id' => $patient->id,
            'client_enrollment_uuid' => hex2bin('00112233445566778899aabbccddeeff'),
            'class' => 'cough',
            'is_negative' => false,
            'negative_scoped' => false,
            'embedding' => pack('g*', 0.25, 0.5),
            'embedding_dim' => 2,
            'captured_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $patient;
    }

    private function newBackup(PhrPatient $patient, User $owner): PhrNativeBackup
    {
        return app(PhrNativeBackupService::class)->createQueuedBackup($patient, (int) $owner->id);
    }

    private function readyBackup(PhrPatient $patient, User $owner, string $path): PhrNativeBackup
    {
        return PhrNativeBackup::query()->create([
            'patient_id' => $patient->id,
            'requested_by_user_id' => $owner->id,
            'status' => PhrNativeBackup::STATUS_READY,
            'schema_version' => PhrNativeBackupCatalog::SCHEMA_VERSION,
            'storage_disk' => 'phr_exports',
            'storage_path' => $path,
            'generated_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
    }

    private function generate(PhrPatient $patient, User $owner): PhrNativeBackup
    {
        return app(PhrNativeBackupService::class)->generate($this->newBackup($patient, $owner));
    }

    /** @return array<string, mixed> */
    private function manifest(PhrNativeBackup $backup): array
    {
        $path = Storage::disk('phr_exports')->path((string) $backup->storage_path);
        $manifest = json_decode($this->archiveEntry($path, 'manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);

        return $manifest;
    }

    private function archiveEntry(string $archivePath, string $entry): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archivePath));
        try {
            $contents = $zip->getFromName($entry);
            $this->assertIsString($contents);

            return $contents;
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, mixed> $manifest */
    private function artifactKinds(array $manifest): array
    {
        $kinds = array_column($manifest['artifacts'], 'kind');
        sort($kinds);

        return $kinds;
    }
}

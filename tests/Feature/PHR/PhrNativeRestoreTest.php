<?php

namespace Tests\Feature\PHR;

use App\Jobs\PHR\ApplyPhrNativeRestoreJob;
use App\Jobs\PHR\PreviewPhrNativeRestoreJob;
use App\Models\PhrDocument;
use App\Models\PhrNativeRecordIdentity;
use App\Models\PhrNativeRestoreAttempt;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Services\PHR\NativeBackup\NativeRestoreException;
use App\Services\PHR\NativeBackup\PhrNativeBackupService;
use App\Services\PHR\NativeBackup\PhrNativeRecordCodec;
use App\Services\PHR\NativeBackup\PhrNativeRestoreService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use ZipArchive;

final class PhrNativeRestoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('phr_documents');
        Storage::fake('phr_dicom');
        Storage::fake('phr_exports');
        Queue::fake();
    }

    public function test_queue_retry_window_exceeds_native_restore_timeouts(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $this->assertGreaterThan((new PreviewPhrNativeRestoreJob(1))->timeout, $retryAfter);
        $this->assertGreaterThan((new ApplyPhrNativeRestoreJob(1))->timeout, $retryAfter);
    }

    public function test_owner_can_preview_restore_into_empty_database_apply_and_reimport_idempotently(): void
    {
        $owner = $this->createUser();
        $reviewer = $this->createUser();
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $reviewer->id,
            'access_level' => PhrPatientUserAccess::LEVEL_VIEWER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);
        $documentBytes = 'synthetic native restore artifact';
        Storage::disk('phr_documents')->put('fixtures/native-restore-source.bin', $documentBytes);
        PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
            'title' => 'Synthetic restore source',
            'document_type' => 'other',
            'original_filename' => 'synthetic-source.bin',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => 'fixtures/native-restore-source.bin',
            'mime_type' => 'application/octet-stream',
            'byte_size' => strlen($documentBytes),
            'file_hash' => hash('sha256', $documentBytes),
            'source' => 'manual_upload',
        ]);
        $dicomBytes = 'synthetic dicom bytes';
        Storage::disk('phr_dicom')->put('fixtures/dicom/SERIES/IMAGE1.dcm', $dicomBytes);
        $uploadId = DB::table('phr_dicom_uploads')->insertGetId([
            'patient_id' => $patient->id,
            'uploaded_by_user_id' => $owner->id,
            'status' => 'processed',
            'total_files' => 1,
            'stored_files' => 1,
            'total_bytes' => strlen($dicomBytes),
            'stored_bytes' => strlen($dicomBytes),
            'r2_prefix' => 'fixtures/dicom',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('phr_dicom_files')->insert([
            'patient_id' => $patient->id,
            'upload_id' => $uploadId,
            'file_kind' => 'dicom',
            'r2_key' => 'fixtures/dicom/SERIES/IMAGE1.dcm',
            'original_relative_path' => 'SERIES/IMAGE1.dcm',
            'original_path_hash' => hash('sha256', 'SERIES/IMAGE1.dcm'),
            'original_filename' => 'IMAGE1.dcm',
            'mime_type' => 'application/dicom',
            'file_size_bytes' => strlen($dicomBytes),
            'sha256' => hash('sha256', $dicomBytes),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        $archiveSize = filesize($archivePath);
        $this->assertIsInt($archiveSize);

        DB::table('phr_patients')->where('id', $patient->id)->delete();

        $upload = $this->actingAs($owner)->postJson('/api/phr/data-hub/native-restores/uploads', [
            'source_file_size_bytes' => $archiveSize,
            'restore_access_grants' => false,
        ])->assertCreated()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('restore.status', PhrNativeRestoreAttempt::STATUS_UPLOADING)
            ->assertJsonPath('restore.uploaded_bytes', 0);
        $attemptId = (int) $upload->json('restore.id');

        $this->actingAs($owner)->post("/api/phr/data-hub/native-restores/{$attemptId}/chunks", [
            'chunk' => $this->upload($archivePath),
            'offset' => 0,
        ])->assertOk()->assertJsonPath('restore.uploaded_bytes', $archiveSize);
        $queuedPreview = $this->actingAs($owner)->postJson("/api/phr/data-hub/native-restores/{$attemptId}/preview")
            ->assertAccepted()
            ->assertJsonPath('restore.status', PhrNativeRestoreAttempt::STATUS_PREVIEW_PENDING)
            ->assertJsonPath('restore.target', null);

        Queue::assertPushed(PreviewPhrNativeRestoreJob::class, fn (PreviewPhrNativeRestoreJob $job): bool => $job->attemptId === $attemptId);
        app(PhrNativeRestoreService::class)->preview(PhrNativeRestoreAttempt::query()->findOrFail($attemptId));
        $preview = $this->actingAs($owner)->getJson("/api/phr/data-hub/native-restores/{$attemptId}")
            ->assertOk()
            ->assertJsonPath('restore.status', PhrNativeRestoreAttempt::STATUS_PREVIEW_READY)
            ->assertJsonPath('restore.target', 'new_patient')
            ->assertJsonPath('restore.blockers', []);

        $digest = (string) $preview->json('restore.plan_digest');
        $this->assertSame(1, $preview->json('restore.tables.phr_patients.create'));
        $this->assertSame(1, $preview->json('restore.tables.phr_documents.create'));
        $this->assertSame(2, $preview->json('restore.artifacts.create'));
        $this->assertSame(1, $preview->json('restore.tables.phr_patient_user_access.create'));
        $this->assertSame(1, $preview->json('restore.tables.phr_patient_user_access.skip'));
        $this->assertSame(1, $preview->json('restore.access_grant_count'));

        $this->actingAs($owner)->postJson("/api/phr/data-hub/native-restores/{$attemptId}/apply", [
            'confirmation' => 'RESTORE',
            'plan_digest' => $digest,
            'restore_access_grants' => false,
        ])->assertAccepted()
            ->assertJsonPath('restore.status', PhrNativeRestoreAttempt::STATUS_PENDING);
        Queue::assertPushed(ApplyPhrNativeRestoreJob::class, fn (ApplyPhrNativeRestoreJob $job): bool => $job->attemptId === $attemptId);

        $attempt = PhrNativeRestoreAttempt::query()->findOrFail($attemptId);
        app(PhrNativeRestoreService::class)->apply($attempt);
        $attempt->refresh();
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_COMPLETED, $attempt->status);
        $this->assertNotNull($attempt->target_patient_root_id);
        $this->assertNull($attempt->source_storage_path);
        $this->assertDatabaseHas('phr_patient_user_access', [
            'patient_id' => $attempt->target_patient_root_id,
            'user_id' => $owner->id,
            'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
        ]);
        $this->assertDatabaseMissing('phr_patient_user_access', [
            'patient_id' => $attempt->target_patient_root_id,
            'user_id' => $reviewer->id,
        ]);
        $this->assertGreaterThan(
            0,
            PhrNativeRecordIdentity::query()
                ->where('patient_id', $attempt->target_patient_root_id)
                ->whereNotNull('restored_at')
                ->count(),
        );
        $restoredDocument = PhrDocument::withTrashed()->where('patient_id', $attempt->target_patient_root_id)->sole();
        $this->assertSame($documentBytes, Storage::disk(PhrDocument::STORAGE_DISK)->get((string) $restoredDocument->storage_path));
        $restoredDicom = DB::table('phr_dicom_files')->where('patient_id', $attempt->target_patient_root_id)->sole();
        $this->assertStringEndsWith('/SERIES/IMAGE1.dcm', $restoredDicom->r2_key);
        $this->assertSame($dicomBytes, Storage::disk('phr_dicom')->get($restoredDicom->r2_key));

        $secondAttempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $second = $this->actingAs($owner)->getJson("/api/phr/data-hub/native-restores/{$secondAttempt->id}")
            ->assertOk()
            ->assertJsonPath('restore.target', 'existing_patient')
            ->assertJsonPath('restore.blockers', []);
        $this->assertSame(1, $second->json('restore.tables.phr_patients.skip'));
        $this->assertSame(1, $second->json('restore.tables.phr_documents.skip'));
        $this->assertSame(2, $second->json('restore.artifacts.skip'));
        $this->assertSame(2, $second->json('restore.tables.phr_patient_user_access.skip'));

        @unlink($archivePath);
    }

    public function test_attempts_are_actor_scoped_and_changed_data_fails_before_mutation(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        $archivePath = $this->archiveCopy($patient, (int) $owner->id);

        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $attemptId = (int) $attempt->id;

        $this->actingAs($other)->getJson("/api/phr/data-hub/native-restores/{$attemptId}")->assertNotFound();
        $this->actingAs($other)->postJson("/api/phr/data-hub/native-restores/{$attemptId}/apply", [
            'confirmation' => 'RESTORE',
            'plan_digest' => $attempt->plan_digest,
            'restore_access_grants' => false,
        ])->assertNotFound();

        $patient->update(['relationship' => 'synthetic-updated']);
        $attempt->update(['status' => PhrNativeRestoreAttempt::STATUS_PENDING]);
        try {
            app(PhrNativeRestoreService::class)->apply($attempt);
            $this->fail('Expected a changed preview to fail closed.');
        } catch (NativeRestoreException $exception) {
            $this->assertSame('preview_changed', $exception->failureCategory);
        }
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_FAILED, $attempt->refresh()->status);
        $this->assertSame('synthetic-updated', $patient->refresh()->relationship);

        @unlink($archivePath);
    }

    public function test_chunk_upload_is_actor_scoped_ordered_and_must_be_complete_before_preview(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $started = $this->actingAs($owner)->postJson('/api/phr/data-hub/native-restores/uploads', [
            'source_file_size_bytes' => 2,
            'restore_access_grants' => false,
        ])->assertCreated();
        $attemptId = (int) $started->json('restore.id');

        $this->actingAs($other)->post("/api/phr/data-hub/native-restores/{$attemptId}/chunks", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'ab'),
            'offset' => 0,
        ])->assertNotFound();
        $this->actingAs($owner)->post("/api/phr/data-hub/native-restores/{$attemptId}/chunks", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'b'),
            'offset' => 1,
        ])->assertConflict()->assertJsonPath('error', 'upload_state_invalid');
        $this->actingAs($owner)->postJson("/api/phr/data-hub/native-restores/{$attemptId}/preview")
            ->assertConflict()->assertJsonPath('error', 'upload_incomplete');

        $this->actingAs($owner)->post("/api/phr/data-hub/native-restores/{$attemptId}/chunks", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'ab'),
            'offset' => 0,
        ])->assertOk()->assertJsonPath('restore.uploaded_bytes', 2);
        $this->actingAs($owner)->postJson("/api/phr/data-hub/native-restores/{$attemptId}/preview")
            ->assertAccepted()->assertJsonPath('restore.status', PhrNativeRestoreAttempt::STATUS_PREVIEW_PENDING);
    }

    public function test_records_created_after_a_backup_remain_outside_its_conflict_set(): void
    {
        $owner = $this->createUser();
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        DB::table('phr_conditions')->insert([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'name' => 'Synthetic newer condition',
            'clinical_status' => 'active',
            'verification_status' => 'confirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $preview = $this->actingAs($owner)->getJson("/api/phr/data-hub/native-restores/{$attempt->id}")
            ->assertOk()->assertJsonPath('restore.blockers', []);

        $this->assertSame(0, $preview->json('restore.tables.phr_conditions.create'));
        $this->assertDatabaseHas('phr_conditions', ['name' => 'Synthetic newer condition']);
        @unlink($archivePath);
    }

    public function test_a_record_deleted_after_backup_is_planned_and_recreated_with_its_stable_identity(): void
    {
        $owner = $this->createUser();
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        $healthLogId = DB::table('phr_health_logs')->insertGetId([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'name' => 'Synthetic archived health log',
            'kind' => 'custom',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $healthLogUpdatedAt = DB::table('phr_health_logs')->where('id', $healthLogId)->value('updated_at');
        $healthLogEntryId = DB::table('phr_health_log_entries')->insertGetId([
            'health_log_id' => $healthLogId,
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'recorded_by_user_id' => $owner->id,
            'occurred_at' => now(),
            'title' => 'Synthetic archived health entry',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $conditionId = DB::table('phr_conditions')->insertGetId([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'name' => 'Synthetic archived condition',
            'clinical_status' => 'active',
            'verification_status' => 'confirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        $nativeId = DB::table('phr_native_record_identities')
            ->where('patient_id', $patient->id)
            ->where('record_table', 'phr_conditions')
            ->where('record_id', $conditionId)
            ->value('native_id');
        $this->assertIsString($nativeId);
        DB::table('phr_conditions')->where('id', $conditionId)->delete();
        DB::table('phr_health_log_entries')->where('id', $healthLogEntryId)->delete();

        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $this->assertSame([], $attempt->plan_counts_json['blockers']);
        $this->assertSame(1, $attempt->plan_counts_json['tables']['phr_conditions']['create']);
        $this->assertSame(1, $attempt->plan_counts_json['tables']['phr_health_log_entries']['create']);
        $attempt->update(['status' => PhrNativeRestoreAttempt::STATUS_PENDING]);
        app(PhrNativeRestoreService::class)->apply($attempt);

        $restoredId = DB::table('phr_conditions')->where('patient_id', $patient->id)->value('id');
        $this->assertIsInt($restoredId);
        $this->assertDatabaseHas('phr_native_record_identities', [
            'patient_id' => $patient->id,
            'record_table' => 'phr_conditions',
            'record_id' => $restoredId,
            'native_id' => $nativeId,
        ]);
        $this->assertNotNull(
            PhrNativeRecordIdentity::query()
                ->where('patient_id', $patient->id)
                ->where('record_table', 'phr_conditions')
                ->where('record_id', $restoredId)
                ->value('restored_at'),
        );
        $attempt->refresh();
        $healthLogIdentity = PhrNativeRecordIdentity::query()
            ->where('patient_id', $patient->id)
            ->where('record_table', 'phr_health_logs')
            ->where('record_id', $healthLogId)
            ->sole();
        $this->assertSame($attempt->id, $healthLogIdentity->restore_attempt_id);
        $this->assertSame(
            $attempt->completed_at?->toDateTimeString(),
            $healthLogIdentity->restored_at?->toDateTimeString(),
        );
        $this->assertSame(
            (string) $healthLogUpdatedAt,
            (string) DB::table('phr_health_logs')->where('id', $healthLogId)->value('updated_at'),
        );
        @unlink($archivePath);
    }

    public function test_expired_sources_are_purged_without_pruning_active_attempt_metadata(): void
    {
        $owner = $this->createUser();
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $storedPath = (string) $attempt->source_storage_path;
        $attempt->update(['expires_at' => now()->subMinute()]);

        $this->artisan('phr:native-restores:purge')->assertSuccessful();

        Storage::disk('phr_exports')->assertMissing($storedPath);
        $this->assertNull($attempt->refresh()->source_storage_path);
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('preview_expired', $attempt->failure_category);
        @unlink($archivePath);
    }

    public function test_object_storage_failure_rolls_back_the_entire_patient_graph(): void
    {
        $owner = $this->createUser();
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        $bytes = 'synthetic rollback artifact';
        Storage::disk('phr_documents')->put('fixtures/rollback-source.bin', $bytes);
        PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'title' => 'Synthetic rollback source',
            'document_type' => 'other',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => 'fixtures/rollback-source.bin',
            'byte_size' => strlen($bytes),
            'file_hash' => hash('sha256', $bytes),
            'source' => 'manual_upload',
        ]);
        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        DB::table('phr_patients')->where('id', $patient->id)->delete();
        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $attempt->update(['status' => PhrNativeRestoreAttempt::STATUS_PENDING]);

        $exportsDisk = Storage::disk('phr_exports');
        $failingDisk = Mockery::mock(Filesystem::class);
        $failingDisk->shouldReceive('put')->andReturn(false);
        $failingDisk->shouldReceive('delete')->andReturn(true);
        Storage::shouldReceive('disk')->with('phr_exports')->andReturn($exportsDisk);
        Storage::shouldReceive('disk')->with(PhrDocument::STORAGE_DISK)->andReturn($failingDisk);

        try {
            app(PhrNativeRestoreService::class)->apply($attempt);
            $this->fail('Expected synthetic object storage failure.');
        } catch (NativeRestoreException $exception) {
            $this->assertSame('artifact_write_failed', $exception->failureCategory);
        }

        $this->assertSame(PhrNativeRestoreAttempt::STATUS_FAILED, $attempt->refresh()->status);
        $this->assertSame('artifact_write_failed', $attempt->failure_category);
        $this->assertDatabaseCount('phr_patients', 0);
        $this->assertDatabaseCount('phr_documents', 0);
        @unlink($archivePath);
    }

    public function test_future_schema_and_unexpected_zip_entries_fail_closed_with_fixed_audit_categories(): void
    {
        $owner = $this->createUser();
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        $futurePath = $this->archiveCopy($patient, (int) $owner->id);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($futurePath));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['schemaVersion'] = 2;
        $zip->deleteName('manifest.json');
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $zip->close();

        $future = app(PhrNativeRestoreService::class)->createPreview($this->upload($futurePath), (int) $owner->id, false);
        try {
            app(PhrNativeRestoreService::class)->preview($future);
            $this->fail('Future schemas must fail closed.');
        } catch (NativeRestoreException $exception) {
            $this->assertSame('unsupported_schema', $exception->failureCategory);
        }
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_FAILED, $future->refresh()->status);
        $this->assertSame('unsupported_schema', $future->failure_category);

        $extraPath = $this->archiveCopy($patient, (int) $owner->id);
        $this->assertTrue($zip->open($extraPath));
        $zip->addFromString('../synthetic.txt', 'synthetic');
        $zip->close();
        $extra = app(PhrNativeRestoreService::class)->createPreview($this->upload($extraPath), (int) $owner->id, false);
        try {
            app(PhrNativeRestoreService::class)->preview($extra);
            $this->fail('Unexpected ZIP entries must fail closed.');
        } catch (NativeRestoreException $exception) {
            $this->assertSame('invalid_archive', $exception->failureCategory);
        }
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_FAILED, $extra->refresh()->status);
        $this->assertSame('invalid_archive', $extra->failure_category);

        @unlink($futurePath);
        @unlink($extraPath);
    }

    public function test_file_backed_document_without_its_artifact_fails_closed(): void
    {
        $owner = $this->createUser();
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        $bytes = 'synthetic required document bytes';
        Storage::disk('phr_documents')->put('fixtures/required-document.bin', $bytes);
        PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'title' => 'Synthetic required document',
            'document_type' => 'other',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => 'fixtures/required-document.bin',
            'byte_size' => strlen($bytes),
            'file_hash' => hash('sha256', $bytes),
            'source' => 'manual_upload',
        ]);
        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archivePath));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $artifact = collect($manifest['artifacts'])->firstWhere('kind', 'document');
        $this->assertIsArray($artifact);
        $this->assertTrue($zip->deleteName((string) $artifact['path']));
        $manifest['artifacts'] = array_values(array_filter(
            $manifest['artifacts'],
            static fn (array $candidate): bool => $candidate['path'] !== $artifact['path'],
        ));
        $this->assertTrue($zip->deleteName('manifest.json'));
        $this->assertTrue($zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR)));
        $zip->close();

        $attempt = app(PhrNativeRestoreService::class)->createPreview($this->upload($archivePath), (int) $owner->id, false);
        try {
            app(PhrNativeRestoreService::class)->preview($attempt);
            $this->fail('A file-backed document must retain its artifact.');
        } catch (NativeRestoreException $exception) {
            $this->assertSame('invalid_archive', $exception->failureCategory);
        }
        $this->assertSame('invalid_archive', $attempt->refresh()->failure_category);
        @unlink($archivePath);
    }

    public function test_non_owner_share_cannot_be_restored_with_owner_access(): void
    {
        $owner = $this->createUser();
        $reviewer = $this->createUser();
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $reviewer->id,
            'access_level' => PhrPatientUserAccess::LEVEL_VIEWER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);
        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        $this->mutateTableRecords($archivePath, 'phr_patient_user_access', static function (array $record): array {
            if (($record['attributes']['access_level'] ?? null) === PhrPatientUserAccess::LEVEL_VIEWER) {
                $record['attributes']['access_level'] = PhrPatientUserAccess::LEVEL_OWNER;
            }

            return $record;
        });

        $attempt = $this->readyAttempt($archivePath, (int) $owner->id, true);
        $this->assertContains('invalid_access_grant', $attempt->plan_counts_json['blockers']);
        $this->assertSame(1, $attempt->plan_counts_json['tables']['phr_patient_user_access']['block']);
        @unlink($archivePath);
    }

    public function test_apply_dispatch_failure_moves_the_attempt_to_a_terminal_state(): void
    {
        $owner = $this->createUser();
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        Queue::getFacadeRoot()->beforePushing(static function (object $job): void {
            if ($job instanceof ApplyPhrNativeRestoreJob) {
                throw new \RuntimeException('Synthetic queue outage.');
            }
        });

        $queued = app(PhrNativeRestoreService::class)->queue(
            $attempt,
            (int) $owner->id,
            (string) $attempt->plan_digest,
            false,
        );

        $this->assertSame(PhrNativeRestoreAttempt::STATUS_FAILED, $queued->status);
        $this->assertSame('restore_queue_failed', $queued->failure_category);
        $this->assertNotNull($queued->completed_at);
        @unlink($archivePath);
    }

    public function test_source_cleanup_failure_cannot_reverse_a_committed_restore(): void
    {
        $owner = $this->createUser();
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        $bytes = 'synthetic committed artifact';
        Storage::disk('phr_documents')->put('fixtures/committed-source.bin', $bytes);
        PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'title' => 'Synthetic committed source',
            'document_type' => 'other',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => 'fixtures/committed-source.bin',
            'byte_size' => strlen($bytes),
            'file_hash' => hash('sha256', $bytes),
            'source' => 'manual_upload',
        ]);
        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        DB::table('phr_patients')->where('id', $patient->id)->delete();
        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $attempt->update(['status' => PhrNativeRestoreAttempt::STATUS_PENDING]);

        $exportsDisk = Storage::disk('phr_exports');
        $documentsDisk = Storage::disk('phr_documents');
        $sourceStream = $exportsDisk->readStream((string) $attempt->source_storage_path);
        $this->assertIsResource($sourceStream);
        $failingCleanupDisk = Mockery::mock(Filesystem::class);
        $failingCleanupDisk->shouldReceive('readStream')->andReturn($sourceStream);
        $failingCleanupDisk->shouldReceive('delete')->andReturn(false);
        Storage::shouldReceive('disk')->with('phr_exports')->andReturn($failingCleanupDisk);
        Storage::shouldReceive('disk')->with(PhrDocument::STORAGE_DISK)->andReturn($documentsDisk);

        $completed = app(PhrNativeRestoreService::class)->apply($attempt);

        $this->assertSame(PhrNativeRestoreAttempt::STATUS_COMPLETED, $completed->status);
        $this->assertNotNull($completed->source_storage_path);
        $restoredDocument = PhrDocument::withTrashed()->where('patient_id', $completed->target_patient_root_id)->sole();
        $this->assertSame($bytes, $documentsDisk->get((string) $restoredDocument->storage_path));
        @unlink($archivePath);
    }

    public function test_restore_audit_schema_has_no_free_text_or_clinical_columns(): void
    {
        $columns = Schema::getColumnListing('phr_native_restore_attempts');
        sort($columns);
        $expected = [
            'access_grant_count', 'actor_user_id', 'archive_sha256', 'completed_at', 'created_at',
            'expires_at', 'failure_category', 'id', 'patient_native_id', 'plan_counts_json',
            'plan_digest', 'restore_access_grants', 'schema_version', 'source_file_size_bytes',
            'source_storage_disk', 'source_storage_path', 'status', 'target_patient_root_id', 'updated_at',
            'uploaded_bytes',
        ];
        sort($expected);
        $this->assertSame($expected, $columns);
    }

    public function test_restore_audit_retention_prunes_terminal_metadata_and_anonymizes_recent_actor(): void
    {
        $owner = $this->createUser();
        $old = now()->subDays(3000);
        $oldSuccess = $this->restoreAudit((int) $owner->id, PhrNativeRestoreAttempt::STATUS_COMPLETED, $old);
        $oldFailure = $this->restoreAudit((int) $owner->id, PhrNativeRestoreAttempt::STATUS_FAILED, $old);
        $recent = $this->restoreAudit((int) $owner->id, PhrNativeRestoreAttempt::STATUS_COMPLETED, now()->subDay());

        $this->artisan('phr:data-hub:prune-audits', ['--dry-run' => true])
            ->expectsOutputToContain('restore_success=1 restore_failure=1')
            ->assertSuccessful();
        $this->artisan('phr:data-hub:prune-audits')->assertSuccessful();
        $this->assertDatabaseMissing('phr_native_restore_attempts', ['id' => $oldSuccess->id]);
        $this->assertDatabaseMissing('phr_native_restore_attempts', ['id' => $oldFailure->id]);
        $this->assertDatabaseHas('phr_native_restore_attempts', ['id' => $recent->id]);

        $owner->delete();
        $this->assertDatabaseHas('phr_native_restore_attempts', ['id' => $recent->id, 'actor_user_id' => null]);
    }

    private function patientWithOwnerGrant(int $ownerId): PhrPatient
    {
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $ownerId,
            'display_name' => 'Synthetic Restore Patient',
            'relationship' => 'self',
            'birth_date' => '2000-01-01',
            'sex_at_birth' => 'unknown',
            'notes' => 'Synthetic restore fixture.',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $ownerId,
            'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $ownerId,
            'granted_at' => now(),
        ]);

        return $patient;
    }

    private function archiveCopy(PhrPatient $patient, int $ownerId): string
    {
        $backup = app(PhrNativeBackupService::class)->createQueuedBackup($patient, $ownerId);
        $backup = app(PhrNativeBackupService::class)->generate($backup);
        $copy = tempnam(sys_get_temp_dir(), 'phr-restore-test-');
        $this->assertIsString($copy);
        $this->assertTrue(copy(Storage::disk('phr_exports')->path((string) $backup->storage_path), $copy));

        return $copy;
    }

    private function upload(string $path): UploadedFile
    {
        return new UploadedFile($path, 'synthetic-native.zip', 'application/zip', null, true);
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $mutate */
    private function mutateTableRecords(string $archivePath, string $table, \Closure $mutate): void
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archivePath));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $entry = $manifest['tables'][$table];
        $records = [];
        foreach (array_filter(explode("\n", (string) $zip->getFromName($entry['path']))) as $line) {
            $record = $mutate(json_decode($line, true, flags: JSON_THROW_ON_ERROR));
            $record['contentHash'] = hash('sha256', PhrNativeRecordCodec::canonicalJson([
                'attributes' => $record['attributes'],
                'relationships' => $record['relationships'],
            ]));
            $records[] = PhrNativeRecordCodec::canonicalJson($record);
        }
        $encoded = implode("\n", $records)."\n";
        $manifest['tables'][$table]['sha256'] = hash('sha256', $encoded);
        $this->assertTrue($zip->deleteName($entry['path']));
        $this->assertTrue($zip->addFromString($entry['path'], $encoded));
        $this->assertTrue($zip->deleteName('manifest.json'));
        $this->assertTrue($zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR)));
        $zip->close();
    }

    private function readyAttempt(string $archivePath, int $ownerId, bool $restoreAccessGrants = false): PhrNativeRestoreAttempt
    {
        $attempt = app(PhrNativeRestoreService::class)->createPreview($this->upload($archivePath), $ownerId, $restoreAccessGrants);

        return app(PhrNativeRestoreService::class)->preview($attempt);
    }

    private function restoreAudit(int $actorUserId, string $status, \DateTimeInterface $completedAt): PhrNativeRestoreAttempt
    {
        $attempt = PhrNativeRestoreAttempt::query()->create([
            'actor_user_id' => $actorUserId,
            'source_storage_disk' => 'phr_exports',
            'source_storage_path' => null,
            'source_file_size_bytes' => 1,
            'uploaded_bytes' => 1,
            'archive_sha256' => str_repeat('a', 64),
            'schema_version' => 1,
            'patient_native_id' => '00000000-0000-4000-8000-000000000001',
            'target_patient_root_id' => 101,
            'plan_digest' => str_repeat('b', 64),
            'plan_counts_json' => ['tables' => [], 'artifacts' => [], 'blockers' => []],
            'access_grant_count' => 0,
            'restore_access_grants' => false,
            'status' => $status,
            'failure_category' => $status === PhrNativeRestoreAttempt::STATUS_FAILED ? 'synthetic_failure' : null,
            'expires_at' => now()->subDay(),
            'completed_at' => $completedAt,
        ]);
        DB::table('phr_native_restore_attempts')->where('id', $attempt->id)->update([
            'created_at' => $completedAt,
            'updated_at' => $completedAt,
        ]);

        return $attempt;
    }
}

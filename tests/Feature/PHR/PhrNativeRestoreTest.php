<?php

namespace Tests\Feature\PHR;

use App\Jobs\PHR\ApplyPhrNativeRestoreJob;
use App\Jobs\PHR\PreviewPhrNativeRestoreJob;
use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Models\PhrMedicationFillEvidence;
use App\Models\PhrNativeRecordIdentity;
use App\Models\PhrNativeRestoreAttempt;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\NativeBackup\NativeRestoreException;
use App\Services\PHR\NativeBackup\PhrNativeBackupService;
use App\Services\PHR\NativeBackup\PhrNativeLifecycleCompatibility;
use App\Services\PHR\NativeBackup\PhrNativeRecordCodec;
use App\Services\PHR\NativeBackup\PhrNativeRestoreService;
use App\Services\PHR\NativeBackup\PhrNativeTableCompatibility;
use App\Support\PHR\PhrReviewStatus;
use App\Support\Storage\PhrStorageKey;
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

    public function test_purge_publishes_a_stale_finalizing_watermark_before_expiring_its_source(): void
    {
        $owner = $this->createUser();
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $storedPath = (string) $attempt->source_storage_path;
        $identity = PhrNativeRecordIdentity::query()
            ->where('patient_id', $patient->id)
            ->where('record_table', 'phr_patients')
            ->where('record_id', $patient->id)
            ->sole();
        $identity->update([
            'restore_attempt_id' => $attempt->id,
            'restored_at' => null,
        ]);
        $attempt->update([
            'status' => PhrNativeRestoreAttempt::STATUS_FINALIZING,
            'expires_at' => now()->subMinute(),
        ]);
        DB::table('phr_native_restore_attempts')->where('id', $attempt->id)->update([
            'updated_at' => now()->subHours(3),
        ]);

        $this->artisan('phr:native-restores:purge')->assertSuccessful();

        $this->assertSame(PhrNativeRestoreAttempt::STATUS_COMPLETED, $attempt->refresh()->status);
        $this->assertNotNull($attempt->completed_at);
        $this->assertNotNull($identity->refresh()->restored_at);
        $this->assertNull($attempt->source_storage_path);
        Storage::disk('phr_exports')->assertMissing($storedPath);
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

    public function test_an_archive_written_before_the_record_lifecycle_still_restores(): void
    {
        $owner = User::factory()->create(['user_role' => 'user']);
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        DB::table('phr_lab_results')->insert([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'test_name' => 'Synthetic legacy panel',
            'analyte' => 'WBC',
            'value' => '5.5',
            'review_status' => PhrReviewStatus::CONFIRMED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        // Forge the pre-lifecycle shape: an archive downloaded before the
        // columns existed has no deleted_at or retracted_at at all, and its
        // content hash was taken over that smaller attribute set. A round trip
        // cannot reproduce this, because it always writes and reads the same
        // schema -- which is exactly why the regression was invisible.
        $this->mutateTableRecords($archivePath, 'phr_lab_results', static function (array $record): array {
            unset($record['attributes']['deleted_at'], $record['attributes']['retracted_at']);

            return $record;
        });

        DB::table('phr_patients')->where('id', $patient->id)->delete();

        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $attempt->status, (string) $attempt->failure_category);

        app(PhrNativeRestoreService::class)->apply($attempt->refresh(), (int) $owner->id);

        $restored = DB::table('phr_lab_results')->where('analyte', 'WBC')->first();
        $this->assertNotNull($restored);
        // The absent attributes restore as the value such a row always had.
        $this->assertNull($restored->deleted_at);
        $this->assertNull($restored->retracted_at);
    }

    public function test_a_legacy_archive_recognises_an_unchanged_row_instead_of_conflicting(): void
    {
        $owner = User::factory()->create(['user_role' => 'user']);
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        DB::table('phr_lab_results')->insert([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'test_name' => 'Synthetic legacy panel',
            'analyte' => 'HGB',
            'value' => '13.1',
            'review_status' => PhrReviewStatus::CONFIRMED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        $this->mutateTableRecords($archivePath, 'phr_lab_results', static function (array $record): array {
            unset($record['attributes']['deleted_at'], $record['attributes']['retracted_at']);

            return $record;
        });

        // Nothing changed since the backup. The archive hashes its record over a
        // smaller attribute set than the current row projects, so without
        // normalizing the absent lifecycle values to null every legacy record
        // would read as a conflict against the very row it came from.
        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $plan = $attempt->refresh()->plan_counts_json;
        $this->assertIsArray($plan);
        $this->assertSame(0, $plan['tables']['phr_lab_results']['block'] ?? -1);
        $this->assertSame(1, $plan['tables']['phr_lab_results']['skip'] ?? 0);
    }

    public function test_a_legacy_archive_conflicts_with_a_row_that_has_since_been_deleted(): void
    {
        $owner = User::factory()->create(['user_role' => 'user']);
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        DB::table('phr_lab_results')->insert([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'test_name' => 'Synthetic legacy panel',
            'analyte' => 'RBC',
            'value' => '4.2',
            'review_status' => PhrReviewStatus::CONFIRMED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        $this->mutateTableRecords($archivePath, 'phr_lab_results', static function (array $record): array {
            unset($record['attributes']['deleted_at'], $record['attributes']['retracted_at']);

            return $record;
        });

        // The person deleted the record after taking the backup. Restoring must
        // report a conflict rather than quietly bringing it back.
        DB::table('phr_lab_results')->where('analyte', 'RBC')->update(['deleted_at' => now()]);

        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $plan = $attempt->refresh()->plan_counts_json;
        $this->assertIsArray($plan);
        $this->assertSame(1, $plan['tables']['phr_lab_results']['block'] ?? 0);
        $this->assertSame(0, $plan['tables']['phr_lab_results']['skip'] ?? -1);
    }

    public function test_an_archived_legacy_document_source_restores_as_an_allowed_source(): void
    {
        $owner = User::factory()->create(['user_role' => 'user']);
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        foreach (['Synthetic legacy upload', 'Synthetic legacy fhir'] as $title) {
            $this->metadataOnlyDocument($patient, (int) $owner->id, $title);
        }

        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        // An archive taken before the source rule holds the free-text values
        // that rows then had. Refusing it would make the backup unrestorable.
        $this->mutateTableRecords($archivePath, 'phr_documents', static function (array $record): array {
            $record['attributes']['source'] = $record['attributes']['title'] === 'Synthetic legacy fhir'
                ? 'fhir'
                : 'outlook_email_attachment';

            return $record;
        });

        DB::table('phr_patients')->where('id', $patient->id)->delete();

        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $attempt->status, (string) $attempt->failure_category);

        app(PhrNativeRestoreService::class)->apply($attempt->refresh());

        $this->assertSame(
            ['Synthetic legacy fhir' => 'fhir_import', 'Synthetic legacy upload' => 'manual_upload'],
            DB::table('phr_documents')->orderBy('title')->pluck('source', 'title')->all(),
        );
    }

    public function test_an_archived_legacy_document_source_matches_its_migrated_row_instead_of_conflicting(): void
    {
        $owner = User::factory()->create(['user_role' => 'user']);
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        $this->metadataOnlyDocument($patient, (int) $owner->id, 'Synthetic migrated upload');

        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        $this->mutateTableRecords($archivePath, 'phr_documents', static function (array $record): array {
            $record['attributes']['source'] = 'onedrive_manual_review';

            return $record;
        });

        // The current row is what the data migration made of the archived one,
        // so the archived record must read as unchanged, not as a conflict.
        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $plan = $attempt->refresh()->plan_counts_json;
        $this->assertIsArray($plan);
        $this->assertSame(0, $plan['tables']['phr_documents']['block'] ?? -1);
        $this->assertSame(1, $plan['tables']['phr_documents']['skip'] ?? 0);
    }

    public function test_an_archived_doubled_lab_reference_range_restores_once(): void
    {
        $owner = User::factory()->create(['user_role' => 'user']);
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        foreach (['Synthetic screen' => 'NEG', 'Synthetic count' => '11', 'Synthetic protein' => '13.6 - 17.5', 'Synthetic fraction' => '4.3 - 5.6 PERCENT'] as $analyte => $range) {
            $this->labWithRange($patient, (int) $owner->id, $analyte, $range);
        }
        DB::table('phr_lab_results')->where('analyte', 'Synthetic fraction')->update(['range_unit' => 'PERCENT']);

        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        // An archive taken before the repair migration holds the doubled text
        // the one-off load wrote.
        $this->mutateTableRecords($archivePath, 'phr_lab_results', static function (array $record): array {
            if ($record['attributes']['reference_range_text'] === 'NEG') {
                $record['attributes']['reference_range_text'] = 'NEGNEG';
            }
            if ($record['attributes']['reference_range_text'] === '4.3 - 5.6 PERCENT') {
                $record['attributes']['reference_range_text'] = '4.3 - 5.6 PERCENTPERCENTPERCENT';
            }

            return $record;
        });

        DB::table('phr_patients')->where('id', $patient->id)->delete();

        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $attempt->status, (string) $attempt->failure_category);

        app(PhrNativeRestoreService::class)->apply($attempt->refresh(), (int) $owner->id);

        $this->assertSame(
            ['Synthetic count' => '11', 'Synthetic fraction' => '4.3 - 5.6 PERCENT', 'Synthetic protein' => '13.6 - 17.5', 'Synthetic screen' => 'NEG'],
            DB::table('phr_lab_results')->orderBy('analyte')->pluck('reference_range_text', 'analyte')->all(),
        );
    }

    public function test_an_archived_doubled_lab_reference_range_matches_its_repaired_row_instead_of_conflicting(): void
    {
        $owner = User::factory()->create(['user_role' => 'user']);
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        $this->labWithRange($patient, (int) $owner->id, 'Synthetic organism', 'Not Detected');

        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        $this->mutateTableRecords($archivePath, 'phr_lab_results', static function (array $record): array {
            $record['attributes']['reference_range_text'] = 'Not DetectedNot Detected';

            return $record;
        });

        // The current row is what the repair migration made of the archived
        // one, updated_at included, so the archived record must read as
        // unchanged, not as a conflict.
        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $plan = $attempt->refresh()->plan_counts_json;
        $this->assertIsArray($plan);
        $this->assertSame(0, $plan['tables']['phr_lab_results']['block'] ?? -1);
        $this->assertSame(1, $plan['tables']['phr_lab_results']['skip'] ?? 0);
    }

    public function test_lab_bound_inclusiveness_survives_a_round_trip_and_an_older_archive_restores_without_it(): void
    {
        $owner = User::factory()->create(['user_role' => 'user']);
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        foreach (['Synthetic at most' => [null, true], 'Synthetic above' => [false, null], 'Synthetic unstated' => [null, null]] as $analyte => [$minInclusive, $maxInclusive]) {
            DB::table('phr_lab_results')->insert([
                'patient_id' => $patient->id,
                'user_id' => $owner->id,
                'analyte' => $analyte,
                'value' => '3',
                'range_min' => $minInclusive === null ? null : '1',
                'range_max' => $maxInclusive === null ? null : '5',
                'range_min_inclusive' => $minInclusive,
                'range_max_inclusive' => $maxInclusive,
                'review_status' => PhrReviewStatus::CONFIRMED,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $flags = fn (int $patientId): array => DB::table('phr_lab_results')->where('patient_id', $patientId)->orderBy('analyte')->get()
            ->mapWithKeys(static fn (object $row): array => [$row->analyte => [
                $row->range_min_inclusive === null ? null : (bool) $row->range_min_inclusive,
                $row->range_max_inclusive === null ? null : (bool) $row->range_max_inclusive,
            ]])->all();

        $current = $this->archiveCopy($patient, (int) $owner->id);
        $older = $this->archiveCopy($patient, (int) $owner->id);
        // An archive downloaded before the attributes existed has none.
        $this->mutateTableRecords($older, 'phr_lab_results', static function (array $record): array {
            unset($record['attributes']['range_min_inclusive'], $record['attributes']['range_max_inclusive']);

            return $record;
        });

        DB::table('phr_patients')->where('id', $patient->id)->delete();
        $attempt = $this->readyAttempt($current, (int) $owner->id);
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $attempt->status, (string) $attempt->failure_category);
        app(PhrNativeRestoreService::class)->apply($attempt->refresh(), (int) $owner->id);
        $target = (int) $attempt->refresh()->target_patient_root_id;
        $this->assertSame([
            'Synthetic above' => [false, null],
            'Synthetic at most' => [null, true],
            'Synthetic unstated' => [null, null],
        ], $flags($target));

        // Against the restored rows, the older archive's records match the one
        // whose bounds said nothing and conflict with the two that now say more.
        $plan = $this->readyAttempt($older, (int) $owner->id)->refresh()->plan_counts_json;
        $this->assertIsArray($plan);
        $this->assertSame(['block' => 2, 'skip' => 1], [
            'block' => $plan['tables']['phr_lab_results']['block'] ?? -1,
            'skip' => $plan['tables']['phr_lab_results']['skip'] ?? -1,
        ]);

        DB::table('phr_patients')->where('id', $target)->delete();
        $olderAttempt = $this->readyAttempt($older, (int) $owner->id);
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $olderAttempt->status, (string) $olderAttempt->failure_category);
        app(PhrNativeRestoreService::class)->apply($olderAttempt->refresh(), (int) $owner->id);
        $this->assertSame([
            'Synthetic above' => [null, null],
            'Synthetic at most' => [null, null],
            'Synthetic unstated' => [null, null],
        ], $flags((int) $olderAttempt->refresh()->target_patient_root_id));
        @unlink($current);
        @unlink($older);
    }

    public function test_an_ehi_package_restores_with_its_bytes_at_the_new_content_address(): void
    {
        $owner = $this->createUser();
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        [$bytes, $digest] = $this->seedEhiPackage($patient, (int) $owner->id);
        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        DB::table('phr_patients')->where('id', $patient->id)->delete();

        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $attempt->status, (string) $attempt->failure_category);
        $this->assertSame(1, $attempt->plan_counts_json['tables']['phr_ehi_blobs']['create']);
        $this->assertSame(1, $attempt->plan_counts_json['artifacts']['create']);
        app(PhrNativeRestoreService::class)->apply($attempt->refresh());

        $restoredPatientId = (int) $attempt->refresh()->target_patient_root_id;
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_COMPLETED, $attempt->status);
        $blob = DB::table('phr_ehi_blobs')->where('patient_id', $restoredPatientId)->sole();
        $this->assertSame(config('phr.ehi_disk'), $blob->storage_disk);
        $this->assertSame(PhrStorageKey::ehiBlob($restoredPatientId, $digest), $blob->storage_path);
        $this->assertSame($bytes, Storage::disk((string) $blob->storage_disk)->get((string) $blob->storage_path));
        $package = DB::table('phr_ehi_packages')->where('patient_id', $restoredPatientId)->sole();
        $this->assertNull($package->lease_token);
        $this->assertNull($package->lease_expires_at);
        $this->assertNull($package->checkpoint_json);
        $this->assertSame(1, DB::table('phr_ehi_package_members')->where('blob_id', $blob->id)->where('package_id', $package->id)->count());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));

        // Re-reading the same archive recognises the restored rows and bytes.
        $second = $this->readyAttempt($archivePath, (int) $owner->id);
        $this->assertSame([], $second->plan_counts_json['blockers']);
        $this->assertSame(1, $second->plan_counts_json['tables']['phr_ehi_blobs']['skip']);
        $this->assertSame(1, $second->plan_counts_json['artifacts']['skip']);

        // Bytes changed under a live row are a conflict, never silently skipped.
        Storage::disk((string) $blob->storage_disk)->put((string) $blob->storage_path, 'synthetic replaced bytes');
        $third = $this->readyAttempt($archivePath, (int) $owner->id);
        $this->assertContains('artifact_conflict', $third->plan_counts_json['blockers']);
        @unlink($archivePath);
    }

    public function test_a_new_blob_whose_digest_the_patient_already_holds_is_a_conflict(): void
    {
        $owner = $this->createUser();
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        [, $digest] = $this->seedEhiPackage($patient, (int) $owner->id);
        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        // A later intake re-created the same bytes under a fresh identity.
        $blob = DB::table('phr_ehi_blobs')->where('patient_id', $patient->id)->sole();
        DB::table('phr_ehi_blobs')->where('id', $blob->id)->delete();
        DB::table('phr_ehi_blobs')->insert([...(array) $blob, 'id' => null]);

        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $this->assertContains('record_conflict', $attempt->plan_counts_json['blockers']);
        $this->assertSame(1, $attempt->plan_counts_json['tables']['phr_ehi_blobs']['block']);
        $this->assertSame(1, DB::table('phr_ehi_blobs')->where('patient_id', $patient->id)->where('sha256', $digest)->count());
        @unlink($archivePath);
    }

    public function test_a_source_label_or_package_manifest_the_patient_already_holds_is_a_conflict(): void
    {
        $owner = $this->createUser();
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        $this->seedEhiPackage($patient, (int) $owner->id);
        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        // A later binding and intake re-created the same label and manifest under
        // fresh identities; applying the archived rows would hit the unique keys.
        $source = DB::table('phr_ehi_sources')->where('patient_id', $patient->id)->sole();
        $package = DB::table('phr_ehi_packages')->where('patient_id', $patient->id)->sole();
        DB::table('phr_ehi_packages')->where('id', $package->id)->delete();
        DB::table('phr_ehi_sources')->where('id', $source->id)->delete();
        $newSourceId = DB::table('phr_ehi_sources')->insertGetId([...(array) $source, 'id' => null]);
        DB::table('phr_ehi_packages')->insert([...(array) $package, 'id' => null, 'source_id' => $newSourceId]);

        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $this->assertContains('record_conflict', $attempt->plan_counts_json['blockers']);
        $this->assertSame(1, $attempt->plan_counts_json['tables']['phr_ehi_sources']['block']);
        $this->assertSame(1, $attempt->plan_counts_json['tables']['phr_ehi_packages']['block']);
        @unlink($archivePath);
    }

    public function test_a_tampered_or_missing_ehi_blob_fails_closed(): void
    {
        $owner = $this->createUser();
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        [$bytes] = $this->seedEhiPackage($patient, (int) $owner->id);
        $replacement = str_repeat('X', strlen($bytes));

        // Bytes swapped inside the ZIP only.
        $swapped = $this->archiveCopy($patient, (int) $owner->id);
        $this->rewriteEhiArtifact($swapped, static fn (array $artifact): array => [$artifact, $replacement]);
        $this->assertPreviewFails($swapped, (int) $owner->id, 'archive_hash_mismatch');

        // Bytes swapped with a self-consistent manifest: the blob row's digest still disagrees.
        $forged = $this->archiveCopy($patient, (int) $owner->id);
        $this->rewriteEhiArtifact($forged, static fn (array $artifact): array => [
            [...$artifact, 'sha256' => hash('sha256', $replacement), 'size' => strlen($replacement)],
            $replacement,
        ]);
        $this->assertPreviewFails($forged, (int) $owner->id, 'archive_hash_mismatch');

        // A blob row without its bytes.
        $missing = $this->archiveCopy($patient, (int) $owner->id);
        $this->rewriteEhiArtifact($missing, static fn (array $artifact): array => [null, null]);
        $this->assertPreviewFails($missing, (int) $owner->id, 'invalid_archive');

        @unlink($swapped);
        @unlink($forged);
        @unlink($missing);
    }

    public function test_an_archive_written_before_the_ehi_tables_still_restores(): void
    {
        $owner = User::factory()->create(['user_role' => 'user']);
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        DB::table('phr_lab_results')->insert([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'test_name' => 'Synthetic pre-EHI panel',
            'analyte' => 'PLT',
            'value' => '250',
            'review_status' => PhrReviewStatus::CONFIRMED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        // Forge the pre-EHI shape: an archive downloaded before these tables
        // existed has no manifest entry and no record file for any of them. A
        // round trip always writes the current catalog, so it cannot catch this.
        $this->removeTables($archivePath, [
            ...PhrNativeTableCompatibility::LATER_GENERATIONS['ehi_tables'],
            ...PhrNativeTableCompatibility::LATER_GENERATIONS['ehi_evidence'],
            ...PhrNativeTableCompatibility::LATER_GENERATIONS['medication_fills'],
            ...PhrNativeTableCompatibility::LATER_GENERATIONS['medication_fill_evidence'],
        ]);
        DB::table('phr_patients')->where('id', $patient->id)->delete();

        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $attempt->status, (string) $attempt->failure_category);
        $this->assertSame([], $attempt->plan_counts_json['blockers']);
        $this->assertSame(['create' => 0, 'skip' => 0, 'block' => 0], $attempt->plan_counts_json['tables']['phr_ehi_packages']);

        app(PhrNativeRestoreService::class)->apply($attempt->refresh());

        $this->assertSame(PhrNativeRestoreAttempt::STATUS_COMPLETED, $attempt->refresh()->status);
        $this->assertDatabaseHas('phr_lab_results', ['patient_id' => $attempt->target_patient_root_id, 'analyte' => 'PLT']);
        $this->assertSame(0, DB::table('phr_ehi_packages')->count());
        @unlink($archivePath);
    }

    public function test_an_archive_written_before_the_ehi_evidence_tables_still_restores(): void
    {
        $owner = User::factory()->create(['user_role' => 'user']);
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        $this->seedEhiPackage($patient, (int) $owner->id);
        DB::table('phr_immunizations')->insert([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'vaccine_name' => 'Synthetic M1-era vaccine',
            'review_status' => PhrReviewStatus::CONFIRMED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        // Forge the M1-era shape: EHI preservation tables present, but no
        // evidence/proposal tables and no immunization provenance columns, with
        // record hashes taken over that smaller attribute set.
        $this->removeTables($archivePath, [
            ...PhrNativeTableCompatibility::LATER_GENERATIONS['ehi_evidence'],
            ...PhrNativeTableCompatibility::LATER_GENERATIONS['medication_fills'],
            ...PhrNativeTableCompatibility::LATER_GENERATIONS['medication_fill_evidence'],
        ]);
        $this->mutateTableRecords($archivePath, 'phr_immunizations', static function (array $record): array {
            foreach (PhrNativeLifecycleCompatibility::TABLE_COLUMNS['phr_immunizations'] as $column) {
                unset($record['attributes'][$column]);
            }

            return $record;
        });

        // Unchanged since the backup: the legacy record is recognised, not a conflict.
        $unchanged = $this->readyAttempt($archivePath, (int) $owner->id);
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $unchanged->status, (string) $unchanged->failure_category);
        $this->assertSame(1, $unchanged->plan_counts_json['tables']['phr_immunizations']['skip']);
        $this->assertSame(['create' => 0, 'skip' => 0, 'block' => 0], $unchanged->plan_counts_json['tables']['phr_ehi_proposals']);

        DB::table('phr_patients')->where('id', $patient->id)->delete();
        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $attempt->status, (string) $attempt->failure_category);
        $this->assertSame([], $attempt->plan_counts_json['blockers']);
        app(PhrNativeRestoreService::class)->apply($attempt->refresh());

        $this->assertSame(PhrNativeRestoreAttempt::STATUS_COMPLETED, $attempt->refresh()->status);
        $restored = DB::table('phr_immunizations')->where('patient_id', $attempt->target_patient_root_id)->sole();
        $this->assertSame('Synthetic M1-era vaccine', $restored->vaccine_name);
        foreach (PhrNativeLifecycleCompatibility::TABLE_COLUMNS['phr_immunizations'] as $column) {
            $this->assertNull($restored->{$column});
        }
        $this->assertSame(1, DB::table('phr_ehi_packages')->where('patient_id', $attempt->target_patient_root_id)->count());
        $this->assertSame(0, DB::table('phr_ehi_proposals')->count());
        @unlink($archivePath);
    }

    public function test_an_archive_written_before_prescription_fills_still_restores_and_a_current_one_carries_them(): void
    {
        $owner = User::factory()->create(['user_role' => 'user']);
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        $medication = PhrMedication::factory()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'name' => 'Synthetic pre-fills medication']);
        PhrMedicationFill::factory()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'medication_id' => $medication->id, 'external_id' => 'fill-1', 'import_source' => 'synthetic']);

        // A current archive round-trips the fill.
        $current = $this->archiveCopy($patient, (int) $owner->id);
        DB::table('phr_patients')->where('id', $patient->id)->delete();
        $currentAttempt = $this->readyAttempt($current, (int) $owner->id);
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $currentAttempt->status, (string) $currentAttempt->failure_category);
        app(PhrNativeRestoreService::class)->apply($currentAttempt->refresh());
        $restoredFill = DB::table('phr_medication_fills')->where('patient_id', $currentAttempt->refresh()->target_patient_root_id)->sole();
        $this->assertSame('fill-1', $restoredFill->external_id);
        $this->assertNotNull($restoredFill->medication_id, 'the link to the restored medication survives');

        // One downloaded before fills existed has no entry or record file for them.
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        PhrMedication::factory()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'name' => 'Synthetic older medication']);
        $archivePath = $this->archiveCopy($patient, (int) $owner->id);
        $this->removeTables($archivePath, [
            ...PhrNativeTableCompatibility::LATER_GENERATIONS['medication_fills'],
            ...PhrNativeTableCompatibility::LATER_GENERATIONS['medication_fill_evidence'],
        ]);
        DB::table('phr_patients')->where('id', $patient->id)->delete();

        $attempt = $this->readyAttempt($archivePath, (int) $owner->id);
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $attempt->status, (string) $attempt->failure_category);
        $this->assertSame([], $attempt->plan_counts_json['blockers']);
        $this->assertSame(['create' => 0, 'skip' => 0, 'block' => 0], $attempt->plan_counts_json['tables']['phr_medication_fills']);
        app(PhrNativeRestoreService::class)->apply($attempt->refresh());

        $this->assertSame(PhrNativeRestoreAttempt::STATUS_COMPLETED, $attempt->refresh()->status);
        $this->assertDatabaseHas('phr_medications', ['patient_id' => $attempt->target_patient_root_id, 'name' => 'Synthetic older medication']);
        $this->assertSame(0, DB::table('phr_medication_fills')->where('patient_id', $attempt->target_patient_root_id)->count());
        @unlink($current);
        @unlink($archivePath);
    }

    public function test_fill_evidence_and_the_eob_review_status_survive_a_round_trip_and_an_older_archive_restores_without_them(): void
    {
        $owner = User::factory()->create(['user_role' => 'user']);
        $patient = $this->patientWithOwnerGrant((int) $owner->id);
        $document = PhrDocument::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'uploaded_by_user_id' => $owner->id, 'document_type' => 'other', 'source' => 'manual_upload', 'storage_disk' => PhrDocument::STORAGE_DISK, 'byte_size' => 0]);
        $eob = PhrEob::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'import_source' => 'synthetic', 'external_id' => 'eob-1', 'claim_type' => 'pharmacy', 'review_status' => 'pending_review']);
        $line = PhrEobLine::query()->create(['eob_id' => $eob->id, 'patient_id' => $patient->id, 'line_number' => 1, 'procedure_code' => 'X']);
        $fill = PhrMedicationFill::factory()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'external_id' => 'fill-1', 'import_source' => 'synthetic']);
        $twin = PhrMedicationFill::factory()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'external_id' => 'fill-2', 'import_source' => 'synthetic']);
        PhrMedicationFillEvidence::query()->create(['patient_id' => $patient->id, 'fill_id' => $twin->id, 'related_fill_id' => $fill->id, 'source_kind' => 'possible_duplicate', 'evidence_key' => str_repeat('b', 40)]);
        PhrMedicationFillEvidence::query()->create(['patient_id' => $patient->id, 'fill_id' => $fill->id, 'document_id' => $document->id, 'eob_line_id' => $line->id, 'source_kind' => 'eob', 'evidence_key' => str_repeat('a', 40)]);

        $current = $this->archiveCopy($patient, (int) $owner->id);
        $older = $this->archiveCopy($patient, (int) $owner->id);
        $this->removeTables($older, PhrNativeTableCompatibility::LATER_GENERATIONS['medication_fill_evidence']);
        // An EOB archived before it had a review status has no such attribute, and was confirmed.
        $this->mutateTableRecords($older, 'phr_eobs', static function (array $record): array {
            unset($record['attributes']['review_status'], $record['attributes']['lines_digest']);

            return $record;
        });
        DB::table('phr_patients')->where('id', $patient->id)->delete();

        $attempt = $this->readyAttempt($current, (int) $owner->id);
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $attempt->status, (string) $attempt->failure_category);
        app(PhrNativeRestoreService::class)->apply($attempt->refresh());
        $target = $attempt->refresh()->target_patient_root_id;
        $restoredFill = DB::table('phr_medication_fills')->where('patient_id', $target)->where('external_id', 'fill-1')->sole();
        $evidence = DB::table('phr_medication_fill_evidence')->where('patient_id', $target)->where('source_kind', 'eob')->sole();
        $this->assertSame($restoredFill->id, $evidence->fill_id);
        $this->assertSame(DB::table('phr_eob_lines')->where('patient_id', $target)->value('id'), $evidence->eob_line_id);
        $this->assertSame($document->file_hash, DB::table('phr_documents')->where('id', $evidence->document_id)->value('file_hash'));
        $this->assertSame('pending_review', DB::table('phr_eobs')->where('patient_id', $target)->value('review_status'));
        $flag = DB::table('phr_medication_fill_evidence')->where('patient_id', $target)->where('source_kind', 'possible_duplicate')->sole();
        $this->assertSame($restoredFill->id, $flag->related_fill_id, 'a possible-duplicate flag points at the restored fill, not the old ID');
        $this->assertNotSame($flag->fill_id, $flag->related_fill_id);

        // An archive downloaded before fill evidence existed has no such table, and restores.
        DB::table('phr_patients')->where('id', $target)->delete();
        $olderAttempt = $this->readyAttempt($older, (int) $owner->id);
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $olderAttempt->status, (string) $olderAttempt->failure_category);
        app(PhrNativeRestoreService::class)->apply($olderAttempt->refresh());
        $this->assertSame(2, DB::table('phr_medication_fills')->where('patient_id', $olderAttempt->refresh()->target_patient_root_id)->count());
        $this->assertSame(0, DB::table('phr_medication_fill_evidence')->where('patient_id', $olderAttempt->target_patient_root_id)->count());
        $this->assertSame('confirmed', DB::table('phr_eobs')->where('patient_id', $olderAttempt->target_patient_root_id)->value('review_status'));
        @unlink($current);
        @unlink($older);
    }

    public function test_only_a_whole_later_table_generation_may_be_absent(): void
    {
        $owner = $this->createUser();
        $patient = $this->patientWithOwnerGrant((int) $owner->id);

        // Part of a generation: written by no build, so damaged or forged.
        $partial = $this->archiveCopy($patient, (int) $owner->id);
        $this->removeTables($partial, ['phr_ehi_findings']);
        $this->assertPreviewFails($partial, (int) $owner->id, 'invalid_archive');

        // A later generation without the earlier one it depends on.
        $skipped = $this->archiveCopy($patient, (int) $owner->id);
        $this->removeTables($skipped, PhrNativeTableCompatibility::LATER_GENERATIONS['ehi_tables']);
        $this->assertPreviewFails($skipped, (int) $owner->id, 'invalid_archive');

        // A table that every v1 archive has always contained.
        $original = $this->archiveCopy($patient, (int) $owner->id);
        $this->removeTables($original, ['phr_lab_results']);
        $this->assertPreviewFails($original, (int) $owner->id, 'invalid_archive');

        // A table the catalog has never heard of.
        $unknown = $this->archiveCopy($patient, (int) $owner->id);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($unknown));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['tables']['phr_synthetic_future'] = ['path' => 'records/phr_synthetic_future.ndjson', 'count' => 0, 'sha256' => hash('sha256', '')];
        $this->assertTrue($zip->addFromString('records/phr_synthetic_future.ndjson', ''));
        $this->assertTrue($zip->deleteName('manifest.json'));
        $this->assertTrue($zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR)));
        $zip->close();
        $this->assertPreviewFails($unknown, (int) $owner->id, 'invalid_archive');

        @unlink($partial);
        @unlink($skipped);
        @unlink($original);
        @unlink($unknown);
    }

    /** @return array{string, string} the blob bytes and their digest */
    private function seedEhiPackage(PhrPatient $patient, int $ownerId): array
    {
        $bytes = "PAT_ID\tSYNTHETIC_COLUMN\n000042\tvalue\n";
        $digest = hash('sha256', $bytes);
        $key = PhrStorageKey::ehiBlob((int) $patient->id, $digest);
        Storage::disk('phr_documents')->put($key, $bytes);
        $sourceId = DB::table('phr_ehi_sources')->insertGetId([
            'patient_id' => $patient->id,
            'created_by_user_id' => $ownerId,
            'label' => 'Synthetic source',
            'source_patient_key' => '000042',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $packageId = DB::table('phr_ehi_packages')->insertGetId([
            'patient_id' => $patient->id,
            'source_id' => $sourceId,
            'created_by_user_id' => $ownerId,
            'bound_by_user_id' => $ownerId,
            'manifest_digest' => hash('sha256', 'synthetic manifest'),
            'intake_kind' => 'directory',
            'member_count' => 1,
            'total_bytes' => strlen($bytes),
            'preservation_status' => 'preserved',
            'accounting_status' => 'accounted',
            'binding_status' => 'bound',
            'lease_token' => '00000000-0000-4000-8000-0000000000bb',
            'lease_expires_at' => now()->subMinute(),
            'checkpoint_json' => json_encode(['step' => 'synthetic'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $blobId = DB::table('phr_ehi_blobs')->insertGetId([
            'patient_id' => $patient->id,
            'sha256' => $digest,
            'byte_size' => strlen($bytes),
            'storage_disk' => 'phr_documents',
            'storage_path' => $key,
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $memberId = DB::table('phr_ehi_package_members')->insertGetId([
            'patient_id' => $patient->id,
            'package_id' => $packageId,
            'blob_id' => $blobId,
            'path' => 'SYNTHETIC_TABLE.tsv',
            'path_sha256' => hash('sha256', 'SYNTHETIC_TABLE.tsv'),
            'original_path' => 'SYNTHETIC_TABLE.tsv',
            'byte_size' => strlen($bytes),
            'sha256' => $digest,
            'kind' => 'table',
            'table_name' => 'SYNTHETIC_TABLE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('phr_ehi_table_accounts')->insert([
            'patient_id' => $patient->id,
            'package_id' => $packageId,
            'member_id' => $memberId,
            'table_name' => 'SYNTHETIC_TABLE',
            'state' => 'parsed',
            'source_row_count' => 1,
            'parser_version' => 'synthetic-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('phr_ehi_findings')->insert([
            'patient_id' => $patient->id,
            'package_id' => $packageId,
            'member_id' => $memberId,
            'code' => 'synthetic_finding',
            'severity' => 'info',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$bytes, $digest];
    }

    /**
     * Replaces (or, given nulls, removes) the single EHI artifact and its manifest entry.
     *
     * @param  \Closure(array<string, mixed>): array{array<string, mixed>|null, string|null}  $rewrite
     */
    private function rewriteEhiArtifact(string $archivePath, \Closure $rewrite): void
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archivePath));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $index = array_search('ehi', array_column($manifest['artifacts'], 'kind'), true);
        $this->assertIsInt($index);
        $artifact = $manifest['artifacts'][$index];
        [$replacement, $bytes] = $rewrite($artifact);
        $this->assertTrue($zip->deleteName((string) $artifact['path']));
        if ($replacement === null) {
            unset($manifest['artifacts'][$index]);
            $manifest['artifacts'] = array_values($manifest['artifacts']);
        } else {
            $manifest['artifacts'][$index] = $replacement;
            $this->assertTrue($zip->addFromString((string) $artifact['path'], (string) $bytes));
        }
        $this->assertTrue($zip->deleteName('manifest.json'));
        $this->assertTrue($zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR)));
        $zip->close();
    }

    /** @param list<string> $tables */
    private function removeTables(string $archivePath, array $tables): void
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archivePath));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($tables as $table) {
            $this->assertTrue($zip->deleteName((string) $manifest['tables'][$table]['path']));
            unset($manifest['tables'][$table]);
        }
        $this->assertTrue($zip->deleteName('manifest.json'));
        $this->assertTrue($zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR)));
        $zip->close();
    }

    private function assertPreviewFails(string $archivePath, int $ownerId, string $category): void
    {
        $attempt = app(PhrNativeRestoreService::class)->createPreview($this->upload($archivePath), $ownerId, false);
        try {
            app(PhrNativeRestoreService::class)->preview($attempt);
            $this->fail("Preview must fail closed with {$category}.");
        } catch (NativeRestoreException $exception) {
            $this->assertSame($category, $exception->failureCategory);
        }
        $this->assertSame($category, $attempt->refresh()->failure_category);
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

    private function metadataOnlyDocument(PhrPatient $patient, int $ownerId, string $title): void
    {
        PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $ownerId,
            'title' => $title,
            'document_type' => 'other',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'byte_size' => 0,
            'source' => 'manual_upload',
        ]);
    }

    private function labWithRange(PhrPatient $patient, int $ownerId, string $analyte, string $range): void
    {
        DB::table('phr_lab_results')->insert([
            'patient_id' => $patient->id,
            'user_id' => $ownerId,
            'test_name' => 'Synthetic panel',
            'analyte' => $analyte,
            'value' => '1',
            'reference_range_text' => $range,
            'review_status' => PhrReviewStatus::CONFIRMED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

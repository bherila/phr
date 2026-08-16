<?php

namespace Tests\Feature\PHR;

use App\Jobs\PHR\CleanupDeletedPhrPatientArtifactsJob;
use App\Models\PhrDicomUpload;
use App\Models\PhrDocument;
use App\Models\PhrExport;
use App\Models\PhrNativeBackup;
use App\Models\PhrNativeBackupAudit;
use App\Models\PhrPatient;
use App\Models\PhrPatientDeletion;
use App\Models\PhrPatientDeletionArtifact;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use App\Services\PHR\DataHub\PhrPatientDeletionCleanupService;
use App\Services\PHR\NativeBackup\PhrNativeBackupCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PhrPatientDeletionTest extends TestCase
{
    public function test_owner_can_preview_and_commit_a_retryable_aggregate_deletion(): void
    {
        Queue::fake();
        Storage::fake('phr_documents');
        Storage::fake('phr_dicom');
        Storage::fake('phr_exports');
        [$owner, $patient] = $this->ownerAndPatient();
        $bytes = 'synthetic deletion document';
        $key = "patients/{$patient->id}/documents/synthetic/document.pdf";
        Storage::disk('phr_documents')->put($key, $bytes);
        $document = $this->document($owner, $patient, $key, $bytes);

        $preview = $this->actingAs($owner)
            ->getJson("/api/phr/data-hub/patients/{$patient->id}/deletion-preview")
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('deletion_preview.patient_id', $patient->id)
            ->assertJsonPath('deletion_preview.record_counts.phr_documents', 1)
            ->assertJsonPath('deletion_preview.artifact_count', 1)
            ->assertJsonPath('deletion_preview.artifact_bytes', strlen($bytes))
            ->assertJsonPath('deletion_preview.active_share_count', 0)
            ->assertJsonPath('deletion_preview.confirmation_text', 'DELETE')
            ->assertJsonMissing(['storage_path' => $key]);
        $digest = (string) $preview->json('deletion_preview.preview_digest');

        $response = $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patient->id}", [
            'confirmation' => 'DELETE',
            'preview_digest' => $digest,
        ])->assertAccepted()->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $deletionId = (int) $response->json('deletion.id');
        $this->assertDatabaseMissing('phr_patients', ['id' => $patient->id]);
        $this->assertDatabaseMissing('phr_documents', ['id' => $document->id]);
        $this->assertDatabaseHas('phr_patient_deletions', [
            'id' => $deletionId,
            'actor_user_id' => $owner->id,
            'patient_root_id' => $patient->id,
            'status' => PhrPatientDeletion::STATUS_PENDING,
            'failure_category' => null,
        ]);
        $this->assertDatabaseHas('phr_patient_deletion_artifacts', [
            'deletion_id' => $deletionId,
            'storage_disk' => 'phr_documents',
            'storage_key' => $key,
        ]);
        Storage::disk('phr_documents')->assertExists($key);
        Queue::assertPushed(CleanupDeletedPhrPatientArtifactsJob::class, fn ($job): bool => $job->deletionId === $deletionId);
        $this->actingAs($owner)
            ->getJson('/api/phr/data-hub/deletions')
            ->assertOk()
            ->assertJsonCount(1, 'deletions')
            ->assertJsonPath('deletions.0.id', $deletionId);
        $this->actingAs($this->createUser())
            ->getJson('/api/phr/data-hub/deletions')
            ->assertOk()
            ->assertJsonCount(0, 'deletions');

        app(PhrPatientDeletionCleanupService::class)->cleanup($deletionId);

        Storage::disk('phr_documents')->assertMissing($key);
        $this->assertDatabaseMissing('phr_patient_deletion_artifacts', ['deletion_id' => $deletionId]);
        $this->assertDatabaseHas('phr_patient_deletions', [
            'id' => $deletionId,
            'status' => PhrPatientDeletion::STATUS_COMPLETED,
            'failure_category' => null,
        ]);
        $this->actingAs($owner)
            ->getJson('/api/phr/data-hub/deletions')
            ->assertOk()
            ->assertJsonCount(0, 'deletions');
        $this->actingAs($owner)
            ->getJson("/api/phr/data-hub/deletions/{$deletionId}")
            ->assertOk()
            ->assertJsonPath('deletion.status', PhrPatientDeletion::STATUS_COMPLETED);
        $this->actingAs($this->createUser())
            ->getJson("/api/phr/data-hub/deletions/{$deletionId}")
            ->assertNotFound();

        // A retry after an ambiguous client response returns the durable result.
        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patient->id}", [
            'confirmation' => 'DELETE',
            'preview_digest' => $digest,
        ])->assertAccepted()->assertJsonPath('deletion.id', $deletionId);
    }

    public function test_preview_and_delete_are_owner_only_and_confirmation_validates_after_authorization(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $manager = $this->createUser();
        $unrelated = $this->createUser();
        PhrPatientUserAccess::create([
            'patient_id' => $patient->id,
            'user_id' => $manager->id,
            'access_level' => PhrPatientUserAccess::LEVEL_MANAGER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);

        $this->actingAs($manager)
            ->getJson("/api/phr/data-hub/patients/{$patient->id}/deletion-preview")
            ->assertForbidden();
        $this->actingAs($unrelated)
            ->getJson("/api/phr/data-hub/patients/{$patient->id}/deletion-preview")
            ->assertNotFound();
        $this->actingAs($manager)->deleteJson("/api/phr/patients/{$patient->id}")->assertForbidden();
        $this->actingAs($unrelated)->deleteJson("/api/phr/patients/{$patient->id}")->assertNotFound();
        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patient->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirmation', 'preview_digest']);
    }

    public function test_active_shares_require_separate_acknowledgement(): void
    {
        Queue::fake();
        [$owner, $patient] = $this->ownerAndPatient();
        $viewer = $this->createUser();
        PhrPatientUserAccess::create([
            'patient_id' => $patient->id,
            'user_id' => $viewer->id,
            'access_level' => PhrPatientUserAccess::LEVEL_VIEWER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);
        $digest = (string) $this->actingAs($owner)
            ->getJson("/api/phr/data-hub/patients/{$patient->id}/deletion-preview")
            ->assertJsonPath('deletion_preview.active_share_count', 1)
            ->json('deletion_preview.preview_digest');

        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patient->id}", [
            'confirmation' => 'DELETE',
            'preview_digest' => $digest,
        ])->assertConflict()->assertJsonPath('error', 'active_shares_unacknowledged');
        $this->assertDatabaseHas('phr_patients', ['id' => $patient->id]);

        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patient->id}", [
            'confirmation' => 'DELETE',
            'preview_digest' => $digest,
            'acknowledge_active_shares' => true,
        ])->assertAccepted();
        $this->assertDatabaseMissing('phr_patient_user_access', ['patient_id' => $patient->id]);
    }

    public function test_changed_preview_and_active_backup_block_without_mutation(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $digest = (string) $this->actingAs($owner)
            ->getJson("/api/phr/data-hub/patients/{$patient->id}/deletion-preview")
            ->json('deletion_preview.preview_digest');
        DB::table('phr_allergies')->insert([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'substance' => 'Synthetic allergen',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patient->id}", [
            'confirmation' => 'DELETE',
            'preview_digest' => $digest,
        ])->assertConflict()->assertJsonPath('error', 'preview_changed');
        $this->assertDatabaseHas('phr_patients', ['id' => $patient->id]);

        $backup = PhrNativeBackup::query()->create([
            'patient_id' => $patient->id,
            'requested_by_user_id' => $owner->id,
            'status' => PhrNativeBackup::STATUS_PROCESSING,
            'schema_version' => PhrNativeBackupCatalog::SCHEMA_VERSION,
            'storage_disk' => 'phr_exports',
        ]);
        DB::table('phr_native_backups')->where('id', $backup->id)->update(['updated_at' => null]);
        $blocked = $this->actingAs($owner)
            ->getJson("/api/phr/data-hub/patients/{$patient->id}/deletion-preview")
            ->assertJsonPath('deletion_preview.blockers.0', 'native_backup_in_progress');
        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patient->id}", [
            'confirmation' => 'DELETE',
            'preview_digest' => (string) $blocked->json('deletion_preview.preview_digest'),
        ])->assertConflict()->assertJsonPath('error', 'native_backup_in_progress');
        $this->assertDatabaseHas('phr_native_backups', ['id' => $backup->id]);
    }

    public function test_storage_failure_is_durable_and_does_not_restore_deleted_database_rows(): void
    {
        Queue::fake();
        Storage::fake('phr_documents');
        [$owner, $patient] = $this->ownerAndPatient();
        $bytes = 'synthetic retained cleanup bytes';
        $key = "patients/{$patient->id}/documents/synthetic/retry.pdf";
        Storage::disk('phr_documents')->put($key, $bytes);
        $this->document($owner, $patient, $key, $bytes);
        $digest = (string) $this->actingAs($owner)
            ->getJson("/api/phr/data-hub/patients/{$patient->id}/deletion-preview")
            ->json('deletion_preview.preview_digest');
        $deletionId = (int) $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patient->id}", [
            'confirmation' => 'DELETE',
            'preview_digest' => $digest,
        ])->json('deletion.id');

        $disk = new class($key) extends FilesystemAdapter
        {
            public function __construct(private readonly string $expectedKey) {}

            public function exists($path): bool
            {
                return (string) $path === $this->expectedKey;
            }

            /** @param string|list<string> $paths */
            public function delete($paths): bool
            {
                return false;
            }
        };
        Storage::set('phr_documents', $disk);

        $this->expectException(RuntimeException::class);
        try {
            app(PhrPatientDeletionCleanupService::class)->cleanup($deletionId);
        } finally {
            $this->assertDatabaseMissing('phr_patients', ['id' => $patient->id]);
            $this->assertDatabaseHas('phr_patient_deletions', [
                'id' => $deletionId,
                'status' => PhrPatientDeletion::STATUS_FAILED,
                'failure_category' => 'storage_cleanup_failed',
            ]);
            $this->assertDatabaseHas('phr_patient_deletion_artifacts', [
                'deletion_id' => $deletionId,
                'status' => 'failed',
                'attempt_count' => 1,
            ]);
            app(PhrPatientDeletionCleanupService::class)->markQueueFailure($deletionId);
            $this->assertDatabaseHas('phr_patient_deletions', [
                'id' => $deletionId,
                'failure_category' => 'storage_cleanup_failed',
            ]);
            $this->actingAs($this->createUser())
                ->postJson("/api/phr/data-hub/deletions/{$deletionId}/retry")
                ->assertNotFound();
            $this->actingAs($owner)
                ->postJson("/api/phr/data-hub/deletions/{$deletionId}/retry")
                ->assertAccepted()
                ->assertJsonPath('deletion.failure_category', 'storage_cleanup_failed');
            Queue::assertPushed(CleanupDeletedPhrPatientArtifactsJob::class, 2);
        }
    }

    public function test_pending_dicom_upload_blocks_until_the_upload_is_finalized(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $upload = PhrDicomUpload::query()->create([
            'patient_id' => $patient->id,
            'uploaded_by_user_id' => $owner->id,
            'status' => PhrDicomUpload::STATUS_PENDING,
            'r2_prefix' => "patients/{$patient->id}/imaging/dicom/uploads/00000000-0000-4000-8000-000000000001",
        ]);

        $this->actingAs($owner)
            ->getJson("/api/phr/data-hub/patients/{$patient->id}/deletion-preview")
            ->assertOk()
            ->assertJsonPath('deletion_preview.blockers.0', 'dicom_upload_in_progress');

        $upload->update(['status' => PhrDicomUpload::STATUS_FAILED]);
        $this->actingAs($owner)
            ->getJson("/api/phr/data-hub/patients/{$patient->id}/deletion-preview")
            ->assertOk()
            ->assertJsonCount(0, 'deletion_preview.blockers');
    }

    public function test_active_clinical_export_blocks_until_its_writer_lease_expires(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $export = PhrExport::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'requested_by_user_id' => $owner->id,
            'format' => 'ccda',
            'formats_json' => ['ccda'],
            'status' => PhrExport::STATUS_PROCESSING,
            'storage_disk' => 'phr_exports',
        ]);

        $this->actingAs($owner)
            ->getJson("/api/phr/data-hub/patients/{$patient->id}/deletion-preview")
            ->assertOk()
            ->assertJsonPath('deletion_preview.blockers.0', 'clinical_export_in_progress');

        DB::table('phr_exports')->where('id', $export->id)->update([
            'updated_at' => now()->subMinutes(16),
        ]);
        $this->actingAs($owner)
            ->getJson("/api/phr/data-hub/patients/{$patient->id}/deletion-preview")
            ->assertOk()
            ->assertJsonCount(0, 'deletion_preview.blockers');
    }

    public function test_artifact_write_guard_never_invokes_a_writer_after_patient_deletion(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $patient->delete();
        $writerCalled = false;

        try {
            app(PhrPatientArtifactWriteGuard::class)->run((int) $patient->id, function () use (&$writerCalled): void {
                $writerCalled = true;
            });
            $this->fail('A deleted aggregate must not enter its durable artifact writer.');
        } catch (ModelNotFoundException) {
            $this->assertFalse($writerCalled);
            $this->assertDatabaseMissing('phr_patients', ['id' => $patient->id]);
            $this->assertDatabaseHas('users', ['id' => $owner->id]);
        }
    }

    public function test_cleanup_fails_closed_when_a_key_is_referenced_again_or_its_work_row_is_tampered(): void
    {
        Queue::fake();
        Storage::fake('phr_documents');
        [$owner, $patient] = $this->ownerAndPatient();
        $bytes = 'synthetic race-safe cleanup bytes';
        $key = "patients/{$patient->id}/documents/synthetic/race.pdf";
        Storage::disk('phr_documents')->put($key, $bytes);
        $this->document($owner, $patient, $key, $bytes);
        $digest = (string) $this->actingAs($owner)
            ->getJson("/api/phr/data-hub/patients/{$patient->id}/deletion-preview")
            ->json('deletion_preview.preview_digest');
        $deletionId = (int) $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patient->id}", [
            'confirmation' => 'DELETE',
            'preview_digest' => $digest,
        ])->json('deletion.id');

        [$secondOwner, $secondPatient] = $this->ownerAndPatient();
        $liveReference = $this->document($secondOwner, $secondPatient, $key, $bytes);
        try {
            app(PhrPatientDeletionCleanupService::class)->cleanup($deletionId);
            $this->fail('A newly live storage reference must block cleanup.');
        } catch (RuntimeException) {
            Storage::disk('phr_documents')->assertExists($key);
        }

        $liveReference->forceDelete();
        PhrPatientDeletionArtifact::query()->where('deletion_id', $deletionId)->update([
            'storage_key_hash' => str_repeat('0', 64),
        ]);
        try {
            app(PhrPatientDeletionCleanupService::class)->cleanup($deletionId);
            $this->fail('A modified durable cleanup row must fail closed.');
        } catch (RuntimeException) {
            Storage::disk('phr_documents')->assertExists($key);
        }

        PhrPatientDeletionArtifact::query()->where('deletion_id', $deletionId)->update([
            'storage_key_hash' => hash('sha256', $key),
        ]);
        app(PhrPatientDeletionCleanupService::class)->cleanup($deletionId);

        Storage::disk('phr_documents')->assertMissing($key);
        $this->assertDatabaseHas('phr_patient_deletions', [
            'id' => $deletionId,
            'status' => PhrPatientDeletion::STATUS_COMPLETED,
        ]);
    }

    public function test_audit_retention_preserves_pending_cleanup_and_account_deletion_anonymizes_actor(): void
    {
        $owner = $this->createUser();
        $old = now()->subDays(3000);
        $recent = now()->subDay();
        $backupSuccess = PhrNativeBackupAudit::query()->create([
            'actor_user_id' => $owner->id,
            'patient_root_id' => 101,
            'operation' => 'backup',
            'schema_version' => 1,
            'outcome' => 'succeeded',
        ]);
        $backupFailure = PhrNativeBackupAudit::query()->create([
            'actor_user_id' => $owner->id,
            'patient_root_id' => 102,
            'operation' => 'backup',
            'schema_version' => 1,
            'outcome' => 'failed',
            'failure_category' => 'synthetic_failure',
        ]);
        $recentBackup = PhrNativeBackupAudit::query()->create([
            'actor_user_id' => $owner->id,
            'patient_root_id' => 103,
            'operation' => 'backup',
            'schema_version' => 1,
            'outcome' => 'succeeded',
        ]);
        DB::table('phr_native_backup_audits')->whereIn('id', [$backupSuccess->id, $backupFailure->id])->update([
            'created_at' => $old,
            'updated_at' => $old,
        ]);
        DB::table('phr_native_backup_audits')->where('id', $recentBackup->id)->update([
            'created_at' => $recent,
            'updated_at' => $recent,
        ]);

        $completed = $this->deletionAudit($owner, 201, PhrPatientDeletion::STATUS_COMPLETED, $old);
        $failed = $this->deletionAudit($owner, 202, PhrPatientDeletion::STATUS_FAILED, $old);
        $pendingWork = $this->deletionAudit($owner, 203, PhrPatientDeletion::STATUS_FAILED, $old);
        PhrPatientDeletionArtifact::query()->create([
            'deletion_id' => $pendingWork->id,
            'storage_disk' => 'phr_documents',
            'storage_key' => 'patients/203/documents/synthetic/pending.pdf',
            'storage_key_hash' => hash('sha256', 'patients/203/documents/synthetic/pending.pdf'),
            'status' => PhrPatientDeletionArtifact::STATUS_FAILED,
        ]);

        $this->artisan('phr:data-hub:prune-audits', ['--dry-run' => true])
            ->expectsOutputToContain('backup_success=1 backup_failure=1 deletion_success=1 deletion_failure=1')
            ->assertSuccessful();
        $this->assertDatabaseHas('phr_patient_deletions', ['id' => $completed->id]);

        $this->artisan('phr:data-hub:prune-audits')->assertSuccessful();
        $this->assertDatabaseMissing('phr_native_backup_audits', ['id' => $backupSuccess->id]);
        $this->assertDatabaseMissing('phr_native_backup_audits', ['id' => $backupFailure->id]);
        $this->assertDatabaseHas('phr_native_backup_audits', ['id' => $recentBackup->id]);
        $this->assertDatabaseMissing('phr_patient_deletions', ['id' => $completed->id]);
        $this->assertDatabaseMissing('phr_patient_deletions', ['id' => $failed->id]);
        $this->assertDatabaseHas('phr_patient_deletions', ['id' => $pendingWork->id]);

        $owner->delete();
        $this->assertDatabaseHas('phr_native_backup_audits', ['id' => $recentBackup->id, 'actor_user_id' => null]);
        $this->assertDatabaseHas('phr_patient_deletions', ['id' => $pendingWork->id, 'actor_user_id' => null]);
    }

    /** @return array{User, PhrPatient} */
    private function ownerAndPatient(): array
    {
        $owner = $this->createUser();
        $patient = PhrPatient::create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Synthetic Deletion Patient',
            'relationship' => 'self',
        ]);
        PhrPatientUserAccess::create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);

        return [$owner, $patient];
    }

    private function document(User $owner, PhrPatient $patient, string $key, string $bytes): PhrDocument
    {
        return PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
            'title' => 'Synthetic deletion document',
            'document_type' => 'other',
            'original_filename' => basename($key),
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => $key,
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'file_hash' => hash('sha256', $bytes),
        ]);
    }

    private function deletionAudit(User $owner, int $patientRootId, string $status, \DateTimeInterface $createdAt): PhrPatientDeletion
    {
        $deletion = PhrPatientDeletion::query()->create([
            'actor_user_id' => $owner->id,
            'patient_root_id' => $patientRootId,
            'preview_digest' => str_repeat('a', 64),
            'record_counts_json' => ['phr_patients' => 1],
            'active_share_count' => 0,
            'artifact_count' => 0,
            'artifact_bytes' => 0,
            'status' => $status,
            'failure_category' => $status === PhrPatientDeletion::STATUS_FAILED ? 'synthetic_failure' : null,
            'deleted_at' => $createdAt,
            'completed_at' => $status === PhrPatientDeletion::STATUS_COMPLETED ? $createdAt : null,
        ]);
        DB::table('phr_patient_deletions')->where('id', $deletion->id)->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $deletion;
    }
}

<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDicomFile;
use App\Models\PhrDicomUpload;
use App\Models\PhrDocument;
use App\Models\PhrHealthLog;
use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\PhrRespiratoryEvent;
use App\Models\User;
use App\Services\PHR\DataHub\PhrDataInventoryService;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Tests\TestCase;

class PhrDataHubTest extends TestCase
{
    public function test_data_hub_page_and_inventory_require_authentication_and_disable_caching(): void
    {
        $this->get('/phr/data-hub')->assertRedirect('/login');
        $this->getJson('/api/phr/data-hub')->assertUnauthorized();

        $user = $this->createUser();
        $this->actingAs($user)->get('/phr/data-hub')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertSee('data-active-section="data-hub"', false);
        $this->actingAs($user)->getJson('/api/phr/data-hub')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache');
    }

    public function test_owner_inventory_counts_authoritative_rows_storage_and_active_shares(): void
    {
        $logger = Log::spy();
        self::assertInstanceOf(MockInterface::class, $logger);
        $owner = $this->createUser();
        $manager = $this->createUser();
        $patient = $this->createPatient($owner, 'Owned synthetic profile');
        $this->grant($patient, $owner, PhrPatientUserAccess::LEVEL_OWNER);
        $this->grant($patient, $manager, PhrPatientUserAccess::LEVEL_MANAGER, $owner);

        PhrLabResult::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'test_name' => 'Synthetic analyte',
        ]);
        PhrHealthLog::factory()->for($patient, 'patient')->create([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
        ]);
        PhrRespiratoryEvent::factory()->for($patient, 'patient')->create();

        $document = PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
            'document_type' => 'other',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => 'synthetic/current',
            'byte_size' => 125,
        ]);
        PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
            'document_type' => 'other',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => 'synthetic/deleted',
            'byte_size' => 999,
        ])->delete();

        $upload = PhrDicomUpload::query()->create([
            'patient_id' => $patient->id,
            'uploaded_by_user_id' => $owner->id,
            'r2_prefix' => 'synthetic/',
        ]);
        $this->dicomFile($patient, $upload, PhrDicomFile::KIND_DICOM, 250, 'original');
        $this->dicomFile($patient, $upload, PhrDicomFile::KIND_DERIVED_VOLUME, 1000, 'derived');

        $response = $this->actingAs($owner)->getJson('/api/phr/data-hub')->assertOk();
        $response->assertJsonCount(1, 'owned_patients')
            ->assertJsonPath('owned_patients.0.id', $patient->id)
            ->assertJsonPath('owned_patients.0.record_counts.lab_results', 1)
            ->assertJsonPath('owned_patients.0.record_counts.health_logs', 1)
            ->assertJsonPath('owned_patients.0.record_counts.respiratory_events', 1)
            ->assertJsonPath('owned_patients.0.record_counts.documents', 1)
            ->assertJsonPath('owned_patients.0.record_counts.original_dicom_files', 1)
            ->assertJsonPath('owned_patients.0.storage_bytes.documents', 125)
            ->assertJsonPath('owned_patients.0.storage_bytes.original_dicom', 250)
            ->assertJsonPath('owned_patients.0.storage_bytes.total', 375)
            ->assertJsonPath('owned_patients.0.active_share_count', 1)
            ->assertJsonPath('owned_patients.0.operations.clinical_export.status', 'available')
            ->assertJsonPath('owned_patients.0.operations.native_backup.status', 'planned')
            ->assertJsonPath('owned_patients.0.operations.restore.status', 'planned')
            ->assertJsonPath('owned_patients.0.operations.aggregate_delete.status', 'planned');

        $this->assertSame(
            array_keys(PhrDataInventoryService::CATEGORIES),
            array_keys($response->json('owned_patients.0.record_counts')),
        );
        $this->assertNotNull($response->json('owned_patients.0.last_updated_at'));
        $this->assertTrue($document->exists);
        $logger->shouldNotHaveReceived('debug');
        $logger->shouldNotHaveReceived('info');
        $logger->shouldNotHaveReceived('notice');
        $logger->shouldNotHaveReceived('warning');
        $logger->shouldNotHaveReceived('error');
    }

    public function test_shared_patients_are_read_only_and_other_patients_are_not_disclosed(): void
    {
        $viewer = $this->createUser();
        $owner = $this->createUser();
        $unrelatedOwner = $this->createUser();
        $shared = $this->createPatient($owner, 'Shared synthetic profile');
        $unrelated = $this->createPatient($unrelatedOwner, 'Undisclosed synthetic profile');
        $this->grant($shared, $viewer, PhrPatientUserAccess::LEVEL_VIEWER, $owner);

        PhrLabResult::query()->create([
            'patient_id' => $shared->id,
            'user_id' => $owner->id,
            'test_name' => 'Must not be counted for viewer',
        ]);

        $response = $this->actingAs($viewer)->getJson('/api/phr/data-hub')->assertOk();
        $response->assertJsonCount(0, 'owned_patients')
            ->assertJsonCount(1, 'shared_patients')
            ->assertJsonPath('shared_patients.0.id', $shared->id)
            ->assertJsonPath('shared_patients.0.access_level', 'viewer')
            ->assertJsonPath('shared_patients.0.operations.clinical_export.status', 'owner_only')
            ->assertJsonMissingPath('shared_patients.0.record_counts')
            ->assertJsonMissingPath('shared_patients.0.storage_bytes');
        $this->assertStringNotContainsString((string) $unrelated->id, $response->getContent());
        $this->assertStringNotContainsString('Undisclosed synthetic profile', $response->getContent());
        $this->assertStringNotContainsString('Must not be counted for viewer', $response->getContent());
    }

    private function createPatient(User $owner, string $displayName): PhrPatient
    {
        return PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => $displayName,
            'relationship' => 'self',
        ]);
    }

    private function grant(PhrPatient $patient, User $user, string $level, ?User $grantedBy = null): void
    {
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'access_level' => $level,
            'granted_by_user_id' => ($grantedBy ?? $user)->id,
            'granted_at' => now(),
        ]);
    }

    private function dicomFile(PhrPatient $patient, PhrDicomUpload $upload, string $kind, int $bytes, string $suffix): void
    {
        PhrDicomFile::query()->create([
            'patient_id' => $patient->id,
            'upload_id' => $upload->id,
            'file_kind' => $kind,
            'r2_key' => "synthetic/{$suffix}",
            'original_relative_path' => "{$suffix}.dcm",
            'original_path_hash' => hash('sha256', "path-{$suffix}"),
            'original_filename' => "{$suffix}.dcm",
            'file_size_bytes' => $bytes,
            'sha256' => hash('sha256', "content-{$suffix}"),
        ]);
    }
}

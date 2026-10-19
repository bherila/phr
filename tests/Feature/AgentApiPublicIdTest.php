<?php

namespace Tests\Feature;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrNativeRecordIdentity;
use App\Models\PhrNativeRestoreAttempt;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\NativeBackup\PhrNativeBackupService;
use App\Services\PHR\NativeBackup\PhrNativeRestoreService;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * Public ids for patients, documents and EOBs: opaque, globally unique per
 * record type, created when the record is written (or, for a row written
 * without model events, on its first API read), and stable across a native
 * restore that renumbers every integer id. Integer ids stay routing locators.
 */
final class AgentApiPublicIdTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Storage::fake(PhrDocument::STORAGE_DISK);
        Storage::fake('phr_dicom');
        Storage::fake('phr_exports');
        Queue::fake();
    }

    public function test_patients_documents_and_eobs_get_a_public_id_when_they_are_written(): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $document = $this->document($patient, $owner, 'synthetic-written');
        $eob = $this->eob($patient, $owner, $document);

        foreach ([['phr_patients', $patient->id], ['phr_documents', $document->id], ['phr_eobs', $eob->id]] as [$table, $id]) {
            $identity = PhrNativeRecordIdentity::query()
                ->where('patient_id', $patient->id)->where('record_table', $table)->where('record_id', $id)
                ->sole();
            $this->assertTrue(Str::isUuid($identity->native_id), $table);
        }

        Passport::actingAs($owner, [AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ, AgentApiScopes::DOCUMENTS_READ]);
        $patientPublicId = $this->identity($patient->id, 'phr_patients', $patient->id);
        $documentPublicId = $this->identity($patient->id, 'phr_documents', $document->id);
        $eobPublicId = $this->identity($patient->id, 'phr_eobs', $eob->id);

        $this->getJson("/api/v1/patients/{$patient->id}")->assertOk()->assertJsonPath('data.public_id', $patientPublicId);
        $this->getJson('/api/v1/patients')->assertOk()->assertJsonPath('data.0.public_id', $patientPublicId);
        $this->getJson("/api/v1/patients/{$patient->id}/documents/{$document->id}")->assertOk()
            ->assertJsonPath('data.public_id', $documentPublicId)
            ->assertJsonPath('data.patient_public_id', $patientPublicId);
        $this->getJson("/api/v1/patients/{$patient->id}/documents")->assertOk()
            ->assertJsonPath('data.0.public_id', $documentPublicId);
        $this->getJson("/api/v1/patients/{$patient->id}/eobs/{$eob->id}")->assertOk()
            ->assertJsonPath('data.public_id', $eobPublicId)
            ->assertJsonPath('data.patient_public_id', $patientPublicId)
            ->assertJsonPath('data.source_document_public_id', $documentPublicId);
        $this->getJson("/api/v1/patients/{$patient->id}/eobs")->assertOk()
            ->assertJsonPath('data.0.public_id', $eobPublicId)
            ->assertJsonPath('data.0.source_document_public_id', $documentPublicId);
        $this->postJson("/api/v1/patients/{$patient->id}/documents/{$document->id}/download-access")->assertOk()
            ->assertJsonPath('patient_public_id', $patientPublicId)
            ->assertJsonPath('document_public_id', $documentPublicId);
    }

    public function test_a_row_written_without_model_events_gets_its_public_id_on_first_api_read(): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $eobId = (int) DB::table('phr_eobs')->insertGetId([
            'patient_id' => $patient->id, 'user_id' => $owner->id,
            'import_source' => 'synthetic-raw', 'external_id' => 'synthetic-raw-claim',
            'claim_type' => 'medical', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertFalse(PhrNativeRecordIdentity::query()->where('record_table', 'phr_eobs')->where('record_id', $eobId)->exists());

        Passport::actingAs($owner, [AgentApiScopes::CLINICAL_READ]);
        $first = $this->getJson("/api/v1/patients/{$patient->id}/eobs")->assertOk()->json('data.0.public_id');
        $this->assertIsString($first);
        $this->assertTrue(Str::isUuid($first));
        $this->assertSame($first, $this->identity($patient->id, 'phr_eobs', $eobId));
        $this->getJson("/api/v1/patients/{$patient->id}/eobs/{$eobId}")->assertOk()->assertJsonPath('data.public_id', $first);
        $this->getJson("/api/v1/patients/{$patient->id}/eobs")->assertOk()->assertJsonPath('data.0.public_id', $first);
        $this->assertSame(1, PhrNativeRecordIdentity::query()->where('record_table', 'phr_eobs')->where('record_id', $eobId)->count());
    }

    public function test_a_public_id_is_unique_across_patients_not_only_within_one(): void
    {
        $owner = $this->createUser();
        $first = $this->patient($owner);
        $second = $this->patient($owner);
        $document = $this->document($first, $owner, 'synthetic-unique');
        $publicId = $this->identity($first->id, 'phr_documents', $document->id);

        $this->expectException(UniqueConstraintViolationException::class);
        PhrNativeRecordIdentity::query()->create([
            'patient_id' => $second->id,
            'record_table' => 'phr_documents',
            'record_id' => $document->id + 1000,
            'native_id' => $publicId,
        ]);
    }

    /** @return array<string, array{list<string>}> */
    public static function readerScopes(): array
    {
        return [
            'full read scopes' => [[AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ, AgentApiScopes::DOCUMENTS_READ]],
            'finance projection' => [[AgentApiScopes::BILLING_READ]],
        ];
    }

    /** @param list<string> $scopes */
    #[DataProvider('readerScopes')]
    public function test_a_native_restore_renumbers_integer_ids_but_keeps_public_ids_and_the_referenced_document_bytes(array $scopes): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $bytes = 'synthetic explanation of benefits source bytes';
        $document = $this->document($patient, $owner, 'synthetic-restore', $bytes);
        $eob = $this->eob($patient, $owner, $document);
        // Rows another patient owns take the original integer ids' successors,
        // so the restored rows cannot land on the same integers by accident.
        $this->eob($this->patient($owner), $owner, null);

        Passport::actingAs($owner, $scopes);
        $before = $this->getJson("/api/v1/patients/{$patient->id}/eobs/{$eob->id}")->assertOk()->json('data');
        $originalHash = hash('sha256', $bytes);

        $archivePath = $this->archive($patient, $owner);
        DB::table('phr_patients')->where('id', $patient->id)->delete();
        $this->restore($archivePath, $owner);
        @unlink($archivePath);

        Auth::forgetGuards();
        Passport::actingAs($owner, $scopes);
        $restoredPatient = collect($this->getJson('/api/v1/patients')->assertOk()->json('data'))
            ->firstWhere('public_id', $before['patient_public_id']);
        $this->assertIsArray($restoredPatient);
        $this->assertNotSame($patient->id, $restoredPatient['id']);

        $restoredEob = collect($this->getJson("/api/v1/patients/{$restoredPatient['id']}/eobs")->assertOk()->json('data'))
            ->firstWhere('public_id', $before['public_id']);
        $this->assertIsArray($restoredEob);
        $this->assertNotSame($eob->id, $restoredEob['id']);
        $this->assertNotSame($document->id, $restoredEob['source_document_id']);
        $this->assertSame($before['patient_public_id'], $restoredEob['patient_public_id']);
        $this->assertSame($before['source_document_public_id'], $restoredEob['source_document_public_id']);

        $access = $this->postJson("/api/v1/patients/{$restoredPatient['id']}/documents/{$restoredEob['source_document_id']}/download-access")
            ->assertOk()
            ->assertJsonPath('patient_public_id', $before['patient_public_id'])
            ->assertJsonPath('document_public_id', $before['source_document_public_id'])
            ->json();
        $downloaded = $this->get($access['download_url'])->assertOk()->streamedContent();
        $this->assertSame($originalHash, hash('sha256', $downloaded));
    }

    public function test_records_with_public_ids_created_after_a_backup_stay_outside_its_restore_conflict_set(): void
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $patient = $this->patient($owner);
        $archivePath = $this->archive($patient, $owner);

        // Written after the backup, by a person the archive has no identity for:
        // its own public id must not pull it into the archive's conflict set.
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id, 'user_id' => $manager->id,
            'access_level' => PhrPatientUserAccess::LEVEL_MANAGER,
            'granted_by_user_id' => $owner->id, 'granted_at' => now(),
        ]);
        $later = $this->document($patient, $manager, 'synthetic-later');
        $this->eob($patient, $manager, $later);

        $attempt = app(PhrNativeRestoreService::class)->createPreview($this->upload($archivePath), (int) $owner->id, false);
        $attempt = app(PhrNativeRestoreService::class)->preview($attempt);
        @unlink($archivePath);

        $this->assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $attempt->status);
        $this->assertSame([], $attempt->plan_counts_json['blockers']);
        $this->assertSame(0, $attempt->plan_counts_json['tables']['phr_documents']['create']);
    }

    private function identity(int $patientId, string $table, int $recordId): string
    {
        return (string) PhrNativeRecordIdentity::query()
            ->where('patient_id', $patientId)->where('record_table', $table)->where('record_id', $recordId)
            ->value('native_id');
    }

    private function patient(User $owner): PhrPatient
    {
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id, 'display_name' => 'Synthetic Public Id Patient',
            'relationship' => 'self', 'birth_date' => '2000-01-01', 'sex_at_birth' => 'unknown',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id,
            'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $owner->id, 'granted_at' => now(),
        ]);

        return $patient;
    }

    private function document(PhrPatient $patient, User $uploader, string $name, string $bytes = 'synthetic bytes'): PhrDocument
    {
        $path = "fixtures/{$name}.pdf";
        Storage::disk(PhrDocument::STORAGE_DISK)->put($path, $bytes);

        return PhrDocument::query()->create([
            'patient_id' => $patient->id, 'user_id' => $uploader->id, 'uploaded_by_user_id' => $uploader->id,
            'title' => 'Synthetic EOB source', 'document_type' => 'other',
            'original_filename' => "{$name}.pdf", 'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => $path, 'mime_type' => 'application/pdf', 'byte_size' => strlen($bytes),
            'file_hash' => hash('sha256', $bytes), 'source' => 'manual_upload',
        ]);
    }

    private function eob(PhrPatient $patient, User $owner, ?PhrDocument $document): PhrEob
    {
        return PhrEob::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id,
            'source_document_id' => $document?->id, 'import_source' => 'synthetic-eob',
            'external_id' => 'synthetic-claim-'.Str::random(8), 'claim_type' => 'medical',
            'claim_number' => 'SYNTHETIC-CLAIM', 'processed_date' => '2026-08-12',
            'total_patient_responsibility' => '42.50',
        ]);
    }

    private function archive(PhrPatient $patient, User $owner): string
    {
        $service = app(PhrNativeBackupService::class);
        $backup = $service->generate($service->createQueuedBackup($patient, (int) $owner->id));
        $copy = tempnam(sys_get_temp_dir(), 'phr-public-id-test-');
        $this->assertIsString($copy);
        $this->assertTrue(copy(Storage::disk('phr_exports')->path((string) $backup->storage_path), $copy));

        return $copy;
    }

    private function restore(string $archivePath, User $owner): void
    {
        $service = app(PhrNativeRestoreService::class);
        $attempt = $service->preview($service->createPreview($this->upload($archivePath), (int) $owner->id, false));
        $this->assertSame([], $attempt->plan_counts_json['blockers']);
        $attempt->update(['status' => PhrNativeRestoreAttempt::STATUS_PENDING]);
        $this->assertSame(PhrNativeRestoreAttempt::STATUS_COMPLETED, $service->apply($attempt)->status);
    }

    private function upload(string $path): UploadedFile
    {
        return new UploadedFile($path, 'synthetic-native.zip', 'application/zip', null, true);
    }
}

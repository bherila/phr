<?php

namespace Tests\Feature\Storage;

use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\User;
use App\Support\Storage\PhrStorageMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhrBlobCleanupTest extends TestCase
{
    public function test_cleanup_is_expiry_gated_dry_run_by_default_and_preserves_canonical_bytes(): void
    {
        Storage::fake('phr_documents');
        [$owner, $patient] = $this->ownerAndPatient();
        [$document, $legacyKey, $bytes] = $this->migratedDocument($owner, $patient, 'expiry');
        $canonicalKey = (string) $document->refresh()->storage_path;

        $this->artisan('phr:storage:cleanup-legacy-keys', ['--artifact' => 'documents', '--apply' => true])
            ->expectsOutputToContain("reference=phr_documents#{$document->id} status=retained")
            ->assertSuccessful();
        Storage::disk('phr_documents')->assertExists($legacyKey);

        DB::table('phr_blob_migrations')->where('reference_id', $document->id)->update([
            'retain_until' => now()->subSecond(),
        ]);
        $this->artisan('phr:storage:cleanup-legacy-keys', ['--artifact' => 'documents'])
            ->expectsOutputToContain("reference=phr_documents#{$document->id} status=planned")
            ->doesntExpectOutputToContain('expiry.pdf')
            ->assertSuccessful();
        Storage::disk('phr_documents')->assertExists($legacyKey);
        $this->assertDatabaseHas('phr_blob_migrations', [
            'reference_id' => $document->id,
            'legacy_deleted_at' => null,
        ]);

        $this->artisan('phr:storage:cleanup-legacy-keys', ['--artifact' => 'documents', '--apply' => true])
            ->expectsOutputToContain("reference=phr_documents#{$document->id} status=deleted")
            ->assertSuccessful();

        Storage::disk('phr_documents')->assertMissing($legacyKey);
        Storage::disk('phr_documents')->assertExists($canonicalKey);
        $this->assertSame($bytes, Storage::disk('phr_documents')->get($canonicalKey));
        $this->assertNotNull(DB::table('phr_blob_migrations')->where('reference_id', $document->id)->value('legacy_deleted_at'));
        $references = PhrStorageMap::references()->referencedKeys();
        $this->assertArrayNotHasKey($legacyKey, $references);
        $this->assertArrayHasKey($canonicalKey, $references);
    }

    public function test_cleanup_fails_closed_for_a_bad_destination_or_stale_reference(): void
    {
        Storage::fake('phr_documents');
        [$owner, $patient] = $this->ownerAndPatient();
        [$badDestination, $badSource] = $this->migratedDocument($owner, $patient, 'bad-destination');
        [$staleReference, $staleSource] = $this->migratedDocument($owner, $patient, 'stale-reference');
        DB::table('phr_blob_migrations')->whereIn('reference_id', [$badDestination->id, $staleReference->id])->update([
            'retain_until' => now()->subSecond(),
        ]);

        Storage::disk('phr_documents')->put((string) $badDestination->refresh()->storage_path, 'different synthetic bytes');
        $staleReference->refresh()->update(['storage_path' => $staleSource]);

        $this->artisan('phr:storage:cleanup-legacy-keys', ['--artifact' => 'documents', '--apply' => true])
            ->expectsOutputToContain("reference=phr_documents#{$badDestination->id} status=destination_mismatch")
            ->expectsOutputToContain("reference=phr_documents#{$staleReference->id} status=stale_reference")
            ->doesntExpectOutputToContain('bad-destination.pdf')
            ->doesntExpectOutputToContain('stale-reference.pdf')
            ->assertFailed();

        Storage::disk('phr_documents')->assertExists($badSource);
        Storage::disk('phr_documents')->assertExists($staleSource);
        $this->assertDatabaseHas('phr_blob_migrations', [
            'reference_id' => $badDestination->id,
            'legacy_deleted_at' => null,
        ]);
        $this->assertDatabaseHas('phr_blob_migrations', [
            'reference_id' => $staleReference->id,
            'legacy_deleted_at' => null,
        ]);
    }

    public function test_cleanup_can_close_a_verified_ledger_when_the_legacy_copy_is_already_absent(): void
    {
        Storage::fake('phr_documents');
        [$owner, $patient] = $this->ownerAndPatient();
        [$document, $legacyKey] = $this->migratedDocument($owner, $patient, 'already-absent');
        DB::table('phr_blob_migrations')->where('reference_id', $document->id)->update([
            'retain_until' => now()->subSecond(),
        ]);
        Storage::disk('phr_documents')->delete($legacyKey);

        $this->artisan('phr:storage:cleanup-legacy-keys', ['--artifact' => 'documents', '--apply' => true])
            ->expectsOutputToContain("reference=phr_documents#{$document->id} status=already_deleted")
            ->assertSuccessful();

        $this->assertNotNull(DB::table('phr_blob_migrations')->where('reference_id', $document->id)->value('legacy_deleted_at'));
        Storage::disk('phr_documents')->assertExists((string) $document->refresh()->storage_path);
    }

    public function test_cleanup_rejects_invalid_or_incompatible_scopes(): void
    {
        $this->artisan('phr:storage:cleanup-legacy-keys', ['--artifact' => 'unknown'])
            ->assertExitCode(2);
        $this->artisan('phr:storage:cleanup-legacy-keys', [
            '--disk' => 'phr_documents',
            '--artifact' => 'exports',
        ])->assertExitCode(2);
        $this->artisan('phr:storage:cleanup-legacy-keys', ['--patient' => '0'])
            ->assertExitCode(2);
    }

    /** @return array{User, PhrPatient} */
    private function ownerAndPatient(): array
    {
        $owner = $this->createUser();
        $patient = PhrPatient::create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Synthetic Cleanup Patient',
            'relationship' => 'self',
        ]);

        return [$owner, $patient];
    }

    /** @return array{PhrDocument, string, string} */
    private function migratedDocument(User $owner, PhrPatient $patient, string $token): array
    {
        $bytes = "synthetic {$token} bytes";
        $legacyKey = "phr/documents/patients/{$patient->id}/legacy/{$token}.pdf";
        Storage::disk('phr_documents')->put($legacyKey, $bytes);
        $document = PhrDocument::create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
            'title' => 'Synthetic cleanup document',
            'document_type' => 'other',
            'original_filename' => "{$token}.pdf",
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => $legacyKey,
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'file_hash' => hash('sha256', $bytes),
        ]);
        $this->artisan('phr:storage:migrate-keys', ['--artifact' => 'documents', '--apply' => true])
            ->assertSuccessful();

        return [$document, $legacyKey, $bytes];
    }
}

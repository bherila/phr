<?php

namespace Tests\Feature\Storage;

use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\User;
use App\Support\Storage\PhrSourceReconciliationService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhrSourceReconciliationTest extends TestCase
{
    private string $sourceDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDirectory = sys_get_temp_dir().'/phr-source-reconcile-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->sourceDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sourceDirectory);

        parent::tearDown();
    }

    public function test_report_matches_verified_hashes_without_emitting_paths_or_keys(): void
    {
        Storage::fake('phr_documents');
        [$owner, $patient] = $this->ownerAndPatient();
        $matchedBytes = 'synthetic matched evidence bytes';
        $unmatchedSourceBytes = 'synthetic source-only bytes';
        $unmatchedDocumentBytes = 'synthetic document-only bytes';
        file_put_contents($this->sourceDirectory.'/sensitive-looking-source.pdf', $matchedBytes);
        file_put_contents($this->sourceDirectory.'/source-only.pdf', $unmatchedSourceBytes);
        $matched = $this->document($owner, $patient, 'private/matched.pdf', $matchedBytes);
        $unmatched = $this->document($owner, $patient, 'private/document-only.pdf', $unmatchedDocumentBytes);

        $summary = app(PhrSourceReconciliationService::class)->run($patient->id, $this->sourceDirectory, ['pdf']);
        $this->assertSame(1, $summary->sourceMatched);
        $this->assertSame(1, $summary->sourceUnmatched);

        $this->artisan('phr:storage:reconcile-source-evidence', [
            '--patient' => (string) $patient->id,
            '--source' => $this->sourceDirectory,
            '--extension' => ['pdf'],
        ])
            ->expectsOutputToContain("reference=phr_documents#{$matched->id} status=matched")
            ->expectsOutputToContain("reference=phr_documents#{$unmatched->id} status=unmatched")
            ->expectsOutputToContain('source_files=2')
            ->doesntExpectOutputToContain('sensitive-looking-source.pdf')
            ->doesntExpectOutputToContain('private/matched.pdf')
            ->assertFailed();
    }

    public function test_report_succeeds_for_a_complete_match_and_can_filter_extensions(): void
    {
        Storage::fake('phr_documents');
        [$owner, $patient] = $this->ownerAndPatient();
        $bytes = 'synthetic complete evidence';
        file_put_contents($this->sourceDirectory.'/evidence.pdf', $bytes);
        file_put_contents($this->sourceDirectory.'/operator-notes.txt', 'not part of the selected evidence set');
        $document = $this->document($owner, $patient, 'patients/1/documents/synthetic/evidence.pdf', $bytes);

        $summary = app(PhrSourceReconciliationService::class)->run($patient->id, $this->sourceDirectory, ['.PDF']);
        $this->assertSame(1, $summary->sourceMatched);
        $this->assertSame(0, $summary->sourceUnmatched);

        $this->artisan('phr:storage:reconcile-source-evidence', [
            '--patient' => (string) $patient->id,
            '--source' => $this->sourceDirectory,
            '--extension' => ['.PDF'],
        ])
            ->expectsOutputToContain("reference=phr_documents#{$document->id} status=matched")
            ->expectsOutputToContain('source_files=1')
            ->assertSuccessful();
    }

    public function test_report_fails_closed_for_bad_document_metadata_and_source_symlinks(): void
    {
        Storage::fake('phr_documents');
        [$owner, $patient] = $this->ownerAndPatient();
        $bytes = 'synthetic metadata evidence';
        file_put_contents($this->sourceDirectory.'/evidence.pdf', $bytes);
        $document = $this->document($owner, $patient, 'private/metadata.pdf', $bytes);
        $document->update(['file_hash' => hash('sha256', 'different synthetic bytes')]);

        $this->artisan('phr:storage:reconcile-source-evidence', [
            '--patient' => (string) $patient->id,
            '--source' => $this->sourceDirectory,
        ])
            ->expectsOutputToContain("reference=phr_documents#{$document->id} status=metadata_mismatch")
            ->expectsOutputToContain('document_failures=1')
            ->doesntExpectOutputToContain('private/metadata.pdf')
            ->assertFailed();

        symlink($this->sourceDirectory.'/evidence.pdf', $this->sourceDirectory.'/sensitive-link.pdf');
        $this->artisan('phr:storage:reconcile-source-evidence', [
            '--patient' => (string) $patient->id,
            '--source' => $this->sourceDirectory,
        ])
            ->expectsOutputToContain('Source evidence reconciliation failed; no source paths or object keys were logged.')
            ->doesntExpectOutputToContain('sensitive-link.pdf')
            ->assertFailed();
    }

    public function test_report_validates_patient_source_and_extension_arguments(): void
    {
        $this->artisan('phr:storage:reconcile-source-evidence', [
            '--patient' => '0',
            '--source' => $this->sourceDirectory,
        ])->assertExitCode(2);
        $this->artisan('phr:storage:reconcile-source-evidence', [
            '--patient' => '999999',
            '--source' => $this->sourceDirectory,
        ])->assertExitCode(2);
        $this->artisan('phr:storage:reconcile-source-evidence', [
            '--patient' => '1',
            '--source' => $this->sourceDirectory,
            '--extension' => ['../pdf'],
        ])->assertExitCode(2);
    }

    /** @return array{User, PhrPatient} */
    private function ownerAndPatient(): array
    {
        $owner = $this->createUser();
        $patient = PhrPatient::create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Synthetic Reconciliation Patient',
            'relationship' => 'self',
        ]);

        return [$owner, $patient];
    }

    private function document(User $owner, PhrPatient $patient, string $key, string $bytes): PhrDocument
    {
        Storage::disk('phr_documents')->put($key, $bytes);

        return PhrDocument::create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
            'title' => 'Synthetic reconciliation document',
            'document_type' => 'other',
            'original_filename' => 'synthetic.pdf',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => $key,
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'file_hash' => hash('sha256', $bytes),
        ]);
    }
}

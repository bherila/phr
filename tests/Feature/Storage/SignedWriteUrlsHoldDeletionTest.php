<?php

namespace Tests\Feature\Storage;

use App\Models\PhrDicomUpload;
use App\Models\PhrEhiUpload;
use App\Models\PhrPatient;
use App\Models\PhrPatientDeletion;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\DataHub\PhrPatientDeletionCleanupService;
use App\Services\PHR\DataHub\PhrPatientDeletionService;
use App\Services\PHR\Documents\SignedDocumentUploadService;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Tests\Support\NoLocalPathDisk;
use Tests\Support\UsesObjectStoreDisks;
use Tests\TestCase;

/**
 * A patient's deletion is only complete once no signed write URL can still land that patient's
 * bytes. The store honours a URL until it expires, whatever the application has decided since,
 * so every place that issues one records how long it lives, and deletion waits for that.
 */
class SignedWriteUrlsHoldDeletionTest extends TestCase
{
    use RefreshDatabase;
    use UsesObjectStoreDisks;

    /** Every place that issues a signed write URL, and what holds deletion back while it lives. */
    private const array ISSUERS = [
        'app/Services/PHR/DICOM/DicomUploadProcessor.php' => 'dicom_upload_in_progress',
        'app/Services/PHR/Documents/SignedDocumentUploadService.php' => 'document_upload_in_progress',
        'app/Services/PHR/Import/Ehi/EhiUploadService.php' => 'ehi_upload_in_progress',
    ];

    private User $owner;

    private PhrPatient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        $this->useObjectStoreDisks();
        $this->useDirectUploads();
        $this->owner = $this->createUser();
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->owner->id, 'display_name' => 'Synthetic Patient', 'relationship' => 'self']);
        PhrPatientUserAccess::query()->create(['patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $this->owner->id, 'granted_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->tearDownObjectStoreDisks();
        parent::tearDown();
    }

    private function dicomBase(): string
    {
        return "/api/phr/patients/{$this->patient->id}/dicom/uploads";
    }

    private function ehiBase(): string
    {
        return "/api/phr/patients/{$this->patient->id}/ehi/uploads";
    }

    /** @return array{0: int, 1: array<string, mixed>} a session, and a signed URL for 10 bytes in it */
    private function dicomUrl(): array
    {
        $uploadId = (int) $this->actingAs($this->owner)->postJson($this->dicomBase(), [])->assertCreated()->json('upload.id');
        $authorization = $this->actingAs($this->owner)->postJson("{$this->dicomBase()}/{$uploadId}/files/authorize", [
            'relative_path' => 'CT/IM0001', 'filename' => 'IM0001', 'byte_size' => 10,
        ])->assertOk()->json('authorization');

        return [$uploadId, $authorization];
    }

    /** @return array{0: int, 1: array<string, mixed>} an upload, and a signed URL for its second part */
    private function ehiPartUrl(): array
    {
        $uploadId = (int) $this->actingAs($this->owner)->postJson($this->ehiBase(), ['file_size_bytes' => 9 * 1024 * 1024])->assertCreated()->json('upload.id');
        $part = $this->actingAs($this->owner)->postJson("{$this->ehiBase()}/{$uploadId}/parts", ['part_number' => 2])->assertOk()->json('part');

        return [$uploadId, $part];
    }

    /** @return list<string> */
    private function blockers(): array
    {
        return app(PhrPatientDeletionService::class)->preview($this->patient)->blockers;
    }

    private function deletePatient(): PhrPatientDeletion
    {
        $deletions = app(PhrPatientDeletionService::class);

        return $deletions->delete($this->patient, (int) $this->owner->id, $deletions->preview($this->patient)->digest, false);
    }

    /** @return list<string> everything left for this patient on a disk */
    private function left(string $disk = 'phr_dicom'): array
    {
        return Storage::disk($disk)->allFiles("patients/{$this->patient->id}");
    }

    public function test_every_place_that_issues_a_signed_write_url_is_listed_here(): void
    {
        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (str_ends_with($file->getPathname(), 'app/Services/PHR/Documents/UploadUrlSigner.php')) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(base_path()) + 1);
            // Either through the signer, whatever the variable is called, or around it.
            if ((str_contains($source, 'UploadUrlSigner') && preg_match('/->(?:sign|signPart)\(/', $source) === 1)
                || preg_match('/->(?:temporaryUploadUrl|createPresignedRequest|presign)\(/', $source) === 1) {
                $found[] = $relative;
            }
        }
        sort($found);

        $this->assertSame(array_keys(self::ISSUERS), $found, 'A new signed write URL has to hold patient deletion back while it lives. Record its expiry, add a blocker, and list it here.');
    }

    public function test_a_live_dicom_url_holds_deletion_back_after_the_session_is_cancelled(): void
    {
        [$uploadId] = $this->dicomUrl();
        $this->actingAs($this->owner)->postJson("{$this->dicomBase()}/{$uploadId}/cancel")->assertOk();
        $this->assertSame(PhrDicomUpload::STATUS_FAILED, PhrDicomUpload::query()->findOrFail($uploadId)->status);

        $this->assertContains(self::ISSUERS['app/Services/PHR/DICOM/DicomUploadProcessor.php'], $this->blockers());

        $this->travel(16)->minutes();
        $this->assertSame([], $this->blockers());
    }

    public function test_a_live_dicom_url_holds_deletion_back_after_the_session_is_finalized(): void
    {
        [$uploadId] = $this->dicomUrl();
        PhrDicomUpload::query()->whereKey($uploadId)->update(['status' => PhrDicomUpload::STATUS_PROCESSED]);

        $this->assertContains('dicom_upload_in_progress', $this->blockers());

        $this->travel(16)->minutes();
        $this->assertSame([], $this->blockers());
    }

    public function test_deletion_refuses_while_a_dicom_url_lives_and_then_leaves_nothing_a_late_put_landed(): void
    {
        [$uploadId, $authorization] = $this->dicomUrl();
        $this->actingAs($this->owner)->postJson("{$this->dicomBase()}/{$uploadId}/cancel")->assertOk();

        // The URL outlives the cancel: this lands at the store whatever the session says.
        $this->assertSame(200, $this->putToStore($authorization, str_repeat('x', 10))['status']);
        $this->assertCount(1, $this->left());

        $this->actingAs($this->owner)->getJson("/api/phr/data-hub/patients/{$this->patient->id}/deletion-preview")->assertOk()
            ->assertJsonPath('deletion_preview.blockers.0', 'dicom_upload_in_progress');

        $this->travel(16)->minutes();
        $deletion = $this->deletePatient();
        app(PhrPatientDeletionCleanupService::class)->cleanup($deletion->id);

        $this->assertSame(PhrPatientDeletion::STATUS_COMPLETED, $deletion->refresh()->status);
        $this->assertSame([], $this->left(), 'Deletion reported complete with this patient’s bytes still in the store.');
    }

    public function test_a_session_with_a_live_url_can_still_be_finalized_and_its_url_still_counts(): void
    {
        [$uploadId, $authorization] = $this->dicomUrl();
        $key = (string) json_decode(Crypt::decryptString($authorization['token']), true)['key'];
        PhrDicomUpload::query()->whereKey($uploadId)->update(['status' => PhrDicomUpload::STATUS_PROCESSED]);

        // Late bytes after finalize, cleaned by the same final sweep.
        $this->assertSame(200, $this->putToStore($authorization, str_repeat('x', 10))['status']);
        $this->assertTrue(Storage::disk('phr_dicom')->exists($key));

        $this->travel(16)->minutes();
        $deletion = $this->deletePatient();
        app(PhrPatientDeletionCleanupService::class)->cleanup($deletion->id);

        $this->assertSame([], $this->left());
    }

    public function test_deletion_stays_retryable_when_the_final_sweep_cannot_remove_staging(): void
    {
        [$uploadId, $authorization] = $this->dicomUrl();
        $disk = Storage::disk('phr_dicom');
        if (! $disk instanceof NoLocalPathDisk) {
            $this->markTestSkipped('Injects a storage failure, which only the default disk can do.');
        }
        $this->actingAs($this->owner)->postJson("{$this->dicomBase()}/{$uploadId}/cancel")->assertOk();
        $this->putToStore($authorization, str_repeat('x', 10));
        $this->travel(16)->minutes();
        $deletion = $this->deletePatient();
        $disk->objectStore()->readOnly = true;

        try {
            app(PhrPatientDeletionCleanupService::class)->cleanup($deletion->id);
            $this->fail('The cleanup should report the failure so the job retries.');
        } catch (RuntimeException) {
            $this->assertSame(PhrPatientDeletion::STATUS_FAILED, $deletion->refresh()->status);
        }

        $disk->objectStore()->readOnly = false;
        app(PhrPatientDeletionCleanupService::class)->cleanup($deletion->id);
        $this->assertSame(PhrPatientDeletion::STATUS_COMPLETED, $deletion->refresh()->status);
        $this->assertSame([], $this->left());
    }

    public function test_the_same_url_after_a_cancel_with_no_deletion_is_reaped_by_the_collector(): void
    {
        [$uploadId, $authorization] = $this->dicomUrl();
        $this->actingAs($this->owner)->postJson("{$this->dicomBase()}/{$uploadId}/cancel")->assertOk();
        $this->assertSame([], $this->left(), 'the cancel removed what had arrived');

        $this->assertSame(200, $this->putToStore($authorization, str_repeat('x', 10))['status']);
        $this->assertCount(1, $this->left(), 'the URL still works, which is the point of this test');

        // Reserved until the URL can land nothing more, so an earlier sweep would be repeated.
        $this->travel(16)->minutes();
        $this->artisan('phr:dicom:gc')->assertSuccessful();

        $this->assertSame([], $this->left());
    }

    public function test_a_live_part_url_holds_deletion_back_until_it_expires(): void
    {
        $this->ehiPartUrl();

        $this->assertContains(self::ISSUERS['app/Services/PHR/Import/Ehi/EhiUploadService.php'], $this->blockers());

        $this->travel(16)->minutes();
        $this->assertSame([], $this->blockers());
    }

    public function test_deleting_a_patient_ends_a_part_urls_ability_to_land_bytes(): void
    {
        [, $part] = $this->ehiPartUrl();
        $this->assertSame(200, $this->putToStore($part, str_repeat('x', (int) $part['byte_size']))['status']);

        $this->travel(16)->minutes();
        $deletion = $this->deletePatient();
        app(PhrPatientDeletionCleanupService::class)->cleanup($deletion->id);

        $this->assertSame(PhrPatientDeletion::STATUS_COMPLETED, $deletion->refresh()->status);
        $this->assertSame([], $this->openUploads('phr_documents', "patients/{$this->patient->id}/"));
        // A live store's clock has not moved with the test's, so this URL is still signed validly,
        // and the store refuses it only because the upload is gone.
        $this->assertGreaterThanOrEqual(400, $this->putToStore($part, str_repeat('x', (int) $part['byte_size']))['status']);
        $this->assertSame([], $this->left('phr_documents'));
    }

    public function test_a_part_url_stops_holding_deletion_back_once_its_upload_is_finished(): void
    {
        $bytes = str_repeat('x', 1024);
        $uploadId = (int) $this->actingAs($this->owner)->postJson($this->ehiBase(), ['file_size_bytes' => strlen($bytes)])->assertCreated()->json('upload.id');
        $part = $this->actingAs($this->owner)->postJson("{$this->ehiBase()}/{$uploadId}/parts", ['part_number' => 1])->assertOk()->json('part');
        $sent = $this->putToStore($part, $bytes);

        $this->actingAs($this->owner)->postJson("{$this->ehiBase()}/{$uploadId}/intake", ['parts' => [['part_number' => 1, 'etag' => (string) $sent['etag']]]])->assertStatus(202);

        $this->assertNotContains('ehi_upload_in_progress', $this->blockers());
        $this->assertGreaterThanOrEqual(400, $this->putToStore($part, $bytes)['status'], 'a finished upload takes no more parts');
        $this->assertNull(PhrEhiUpload::query()->findOrFail($uploadId)->part_url_expires_at);
    }

    public function test_a_part_url_stops_holding_deletion_back_once_the_purge_aborts_its_upload(): void
    {
        [$uploadId, $part] = $this->ehiPartUrl();
        PhrEhiUpload::query()->whereKey($uploadId)->update(['expires_at' => now()->subMinute()]);

        $this->artisan('phr:ehi-uploads:purge')->assertSuccessful();

        $this->assertNotContains('ehi_upload_in_progress', $this->blockers());
        $this->assertGreaterThanOrEqual(400, $this->putToStore($part, str_repeat('x', (int) $part['byte_size']))['status']);
    }

    public function test_a_live_document_url_holds_deletion_back_until_it_expires(): void
    {
        $service = app(SignedDocumentUploadService::class);
        $upload = $service->open($this->patient, (int) $this->owner->id, 'synthetic', ['kind' => 'other', 'filename' => 'note.txt', 'content_type' => 'text/plain', 'byte_size' => 4]);
        $service->authorization($upload);
        $service->cancel($this->patient, 'synthetic', $upload->public_id);

        $this->assertContains(self::ISSUERS['app/Services/PHR/Documents/SignedDocumentUploadService.php'], $this->blockers());

        $this->travel(16)->minutes();
        $this->assertSame([], $this->blockers());
    }

    public function test_a_session_that_never_signed_anything_holds_nothing_back_once_closed(): void
    {
        $uploadId = (int) $this->actingAs($this->owner)->postJson($this->dicomBase(), [])->assertCreated()->json('upload.id');
        $this->actingAs($this->owner)->postJson("{$this->dicomBase()}/{$uploadId}/cancel")->assertOk();

        $this->assertSame([], $this->blockers());
    }

    public function test_a_url_is_not_issued_for_a_patient_already_deleted(): void
    {
        $uploadId = (int) $this->actingAs($this->owner)->postJson($this->dicomBase(), [])->assertCreated()->json('upload.id');
        $this->actingAs($this->owner)->postJson("{$this->dicomBase()}/{$uploadId}/cancel")->assertOk();
        $this->deletePatient();

        $this->actingAs($this->owner)->postJson("{$this->dicomBase()}/{$uploadId}/files/authorize", [
            'relative_path' => 'CT/IM0001', 'filename' => 'IM0001', 'byte_size' => 10,
        ])->assertNotFound();
    }
}

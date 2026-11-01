<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\DataHub\PhrPatientDeletionService;
use App\Services\PHR\Documents\SignedDocumentUploadService;
use App\Services\PHR\Documents\UploadUrlSigner;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\TestCase;

class DirectSignedDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private PhrPatient $patient;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('phr_documents');
        Storage::fake('phr_uploads');
        config(['filesystems.disks.phr_uploads.driver' => 's3']);
        $this->owner = User::factory()->create();
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->owner->id, 'display_name' => 'Synthetic Patient', 'relationship' => 'self']);
    }

    public function test_direct_upload_finalizes_from_a_remote_stream_and_late_puts_cannot_change_the_document(): void
    {
        $this->mock(UploadUrlSigner::class)->shouldReceive('sign')->once()->andReturn(['url' => 'https://objects.example.test/synthetic', 'headers' => ['Content-Type' => 'text/plain']]);
        $service = app(SignedDocumentUploadService::class);
        $bytes = 'Synthetic source text.';
        $upload = $service->open($this->patient, $this->owner->id, 'synthetic', ['kind' => 'other', 'filename' => 'source.txt', 'content_type' => 'text/plain', 'byte_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        $this->assertSame('https://objects.example.test/synthetic', $service->authorization($upload)['url']);
        Storage::disk('phr_uploads')->put($upload->staging_path, $bytes);
        // Already authorized work can finish after an admin lowers the limit.
        DB::table('phr_storage_settings')->insert(['id' => 1, 'global_limit_bytes' => 0]);
        $result = $service->finalize($this->patient, 'synthetic', $upload->public_id);
        $document = $result['document'];
        $this->assertSame($bytes, Storage::disk('phr_documents')->get($document->storage_path));
        Storage::disk('phr_uploads')->put($upload->staging_path, 'Late overwrite');
        $service->finalize($this->patient, 'synthetic', $upload->public_id);
        $this->assertSame($bytes, Storage::disk('phr_documents')->get($document->storage_path));
        $this->travel(16)->minutes();
        $service->expireStale();
        Storage::disk('phr_uploads')->assertMissing($upload->staging_path);
        $this->assertNull($upload->refresh()->staging_path);
        $this->assertDatabaseCount('phr_documents', 1);
    }

    public function test_patient_deletion_waits_for_direct_url_expiry_and_keeps_the_correct_disk(): void
    {
        $service = app(SignedDocumentUploadService::class);
        $upload = $service->open($this->patient, $this->owner->id, 'synthetic', ['kind' => 'other', 'filename' => 'source.txt', 'content_type' => 'text/plain', 'byte_size' => 4]);
        $service->cancel($this->patient, 'synthetic', $upload->public_id);
        $deletions = app(PhrPatientDeletionService::class);
        $this->assertContains('document_upload_in_progress', $deletions->preview($this->patient)->blockers);
        $this->travel(16)->minutes();
        $plan = $deletions->preview($this->patient);
        $this->assertNotContains('document_upload_in_progress', $plan->blockers);
        $this->assertNotContains('invalid_storage_reference', $plan->blockers);
        $this->assertSame('phr_uploads', $plan->artifacts[0]['disk']);
        $this->assertSame($upload->staging_path, $plan->artifacts[0]['key']);
    }

    public function test_cancel_cannot_release_the_allowance_of_a_still_usable_direct_url(): void
    {
        DB::table('phr_storage_settings')->insert(['id' => 1, 'global_limit_bytes' => 4]);
        $service = app(SignedDocumentUploadService::class);
        $declared = ['kind' => 'other', 'filename' => 'source.txt', 'content_type' => 'text/plain', 'byte_size' => 4];
        $upload = $service->open($this->patient, $this->owner->id, 'synthetic', $declared);
        $service->cancel($this->patient, 'synthetic', $upload->public_id);
        try {
            $service->open($this->patient, $this->owner->id, 'synthetic', $declared);
            $this->fail('Cancelling does not revoke the outstanding S3 capability.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('phr_document_uploads', 1);
        }
        $this->travel(16)->minutes();
        $service->open($this->patient, $this->owner->id, 'synthetic', $declared);
        $this->assertDatabaseCount('phr_document_uploads', 2);
    }

    public function test_bad_remote_bytes_are_rejected_before_publication(): void
    {
        $service = app(SignedDocumentUploadService::class);
        $upload = $service->open($this->patient, $this->owner->id, 'synthetic', ['kind' => 'other', 'filename' => 'source.txt', 'content_type' => 'text/plain', 'byte_size' => 4, 'sha256' => hash('sha256', 'good')]);
        Storage::disk('phr_uploads')->put($upload->staging_path, 'evil');
        try {
            $service->finalize($this->patient, 'synthetic', $upload->public_id);
            $this->fail('A different same-sized file must not be published.');
        } catch (UnprocessableEntityHttpException) {
            $this->assertSame(0, PhrDocument::query()->count());
        }
    }

    public function test_failed_remote_cleanup_keeps_its_key_for_a_later_retry(): void
    {
        $service = app(SignedDocumentUploadService::class);
        $upload = $service->open($this->patient, $this->owner->id, 'synthetic', ['kind' => 'other', 'filename' => 'source.txt', 'content_type' => 'text/plain', 'byte_size' => 4]);
        $path = $upload->staging_path;
        $service->cancel($this->patient, 'synthetic', $upload->public_id);
        $this->travel(16)->minutes();
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->once()->with($path)->andThrow(new \RuntimeException('Synthetic storage failure'));
        $disk->shouldReceive('delete')->once()->with($path)->andReturn(true);
        Storage::shouldReceive('disk')->with('phr_uploads')->andReturn($disk);
        $service->expireStale();
        $this->assertSame($path, $upload->refresh()->staging_path);
        $service->expireStale();
        $this->assertNull($upload->refresh()->staging_path);
    }

    public function test_cancelled_direct_uploads_are_swept_again_after_the_url_expires(): void
    {
        $service = app(SignedDocumentUploadService::class);
        $upload = $service->open($this->patient, $this->owner->id, 'synthetic', ['kind' => 'other', 'filename' => 'source.txt', 'content_type' => 'text/plain', 'byte_size' => 4]);
        $path = $upload->staging_path;
        $service->cancel($this->patient, 'synthetic', $upload->public_id);
        Storage::disk('phr_uploads')->put($path, 'late');
        $this->travel(16)->minutes();
        $service->expireStale();
        Storage::disk('phr_uploads')->assertMissing($path);
    }
}

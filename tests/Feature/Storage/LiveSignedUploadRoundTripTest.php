<?php

namespace Tests\Feature\Storage;

use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\Documents\SignedDocumentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LiveObjectStore;
use Tests\TestCase;

/**
 * #308's direct staging against a real S3-compatible server: nothing here can run on a
 * fake, because the store itself is what refuses a wrong-length PUT. Skipped unless
 * PHR_TEST_S3_ENDPOINT selects a local store.
 */
class LiveSignedUploadRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private const string BYTES = 'Synthetic source text for a signed PUT.';

    private PhrPatient $patient;

    private User $owner;

    private SignedDocumentUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (! LiveObjectStore::configured()) {
            $this->markTestSkipped('Set PHR_TEST_S3_ENDPOINT to run against a local S3-compatible store.');
        }
        Cache::flush();
        Storage::fake('phr_documents');
        LiveObjectStore::useDisk('phr_uploads', 'phr-uploads');
        config(['filesystems.disks.phr_uploads.throw' => true]);
        Storage::forgetDisk('phr_uploads');
        $this->owner = User::factory()->create();
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->owner->id, 'display_name' => 'Synthetic Patient', 'relationship' => 'self']);
        $this->service = app(SignedDocumentUploadService::class);
    }

    private function open(string $contentType = 'text/plain', ?string $bytes = null): array
    {
        $bytes ??= self::BYTES;
        $upload = $this->service->open($this->patient, $this->owner->id, 'synthetic-live', ['kind' => 'other', 'filename' => 'source.txt', 'content_type' => $contentType, 'byte_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);

        return [$upload, $this->service->authorization($upload)];
    }

    /** @param array{url: string, headers: array<string, string>} $authorization */
    private function putSigned(array $authorization, string $body, array $headerOverrides = []): Response
    {
        return Http::withHeaders([...$authorization['headers'], ...$headerOverrides])
            ->withBody($body, $headerOverrides['Content-Type'] ?? $authorization['headers']['Content-Type'])
            ->put($authorization['url']);
    }

    public function test_a_signed_put_lands_and_finalizes_into_a_document(): void
    {
        [$upload, $authorization] = $this->open();
        $this->assertTrue($this->putSigned($authorization, self::BYTES)->successful());
        $this->assertSame(self::BYTES, Storage::disk('phr_uploads')->get($upload->staging_path));

        $result = $this->service->finalize($this->patient, 'synthetic-live', $upload->public_id);

        $this->assertSame(self::BYTES, Storage::disk('phr_documents')->get($result['document']->storage_path));
    }

    public function test_a_signed_put_on_a_disk_with_no_root_lands_where_the_disk_reads(): void
    {
        config(['filesystems.disks.phr_uploads.root' => '']);
        Storage::forgetDisk('phr_uploads');
        [$upload, $authorization] = $this->open();
        $this->assertTrue($this->putSigned($authorization, self::BYTES)->successful());

        $this->assertSame(self::BYTES, Storage::disk('phr_uploads')->get($upload->staging_path));
        Storage::disk('phr_uploads')->delete($upload->staging_path);
    }

    public function test_the_store_refuses_a_put_of_any_other_length(): void
    {
        [$upload, $authorization] = $this->open();

        $this->assertSame(403, $this->putSigned($authorization, self::BYTES.'-longer')->status());
        $this->assertSame(403, $this->putSigned($authorization, substr(self::BYTES, 1))->status());
        $this->assertFalse(Storage::disk('phr_uploads')->exists($upload->staging_path));
    }

    public function test_the_store_refuses_a_put_of_any_other_type(): void
    {
        [$upload, $authorization] = $this->open();

        $this->assertSame(403, $this->putSigned($authorization, self::BYTES, ['Content-Type' => 'application/pdf'])->status());
        $this->assertFalse(Storage::disk('phr_uploads')->exists($upload->staging_path));
    }

    public function test_the_store_refuses_a_put_after_the_url_expired(): void
    {
        [$upload] = $this->open();
        $upload->forceFill(['expires_at' => now()->addSecond()])->save();
        $authorization = $this->service->authorization($upload);
        sleep(3);

        // S3 answers 403 and Garage 400; what matters is that the store refused it.
        $this->assertTrue($this->putSigned($authorization, self::BYTES)->clientError());
        $this->assertFalse(Storage::disk('phr_uploads')->exists($upload->staging_path));
    }

    public function test_expiry_cleanup_deletes_bytes_that_were_never_finalized(): void
    {
        [$upload, $authorization] = $this->open();
        $this->assertTrue($this->putSigned($authorization, self::BYTES)->successful());
        $this->assertTrue(Storage::disk('phr_uploads')->exists($upload->staging_path));

        $this->travel(16)->minutes();
        $this->artisan('phr:document-uploads:gc')->assertSuccessful();

        $this->assertFalse(Storage::disk('phr_uploads')->exists($upload->staging_path));
        $this->assertNull($upload->refresh()->staging_path);
        $this->assertSame('expired', $upload->status);
    }

    public function test_a_late_put_after_finalize_is_reaped_when_the_url_expires(): void
    {
        [$upload, $authorization] = $this->open();
        $this->assertTrue($this->putSigned($authorization, self::BYTES)->successful());
        $result = $this->service->finalize($this->patient, 'synthetic-live', $upload->public_id);

        // The URL still works until it expires, and the row keeps the key for this reason.
        $this->assertTrue($this->putSigned($authorization, self::BYTES)->successful());
        $this->assertTrue(Storage::disk('phr_uploads')->exists($upload->staging_path));
        $this->assertSame(self::BYTES, Storage::disk('phr_documents')->get($result['document']->storage_path));

        $this->travel(16)->minutes();
        $this->artisan('phr:document-uploads:gc')->assertSuccessful();

        $this->assertFalse(Storage::disk('phr_uploads')->exists($upload->staging_path));
        $this->assertNull($upload->refresh()->staging_path);
    }
}

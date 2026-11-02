<?php

namespace Tests\Feature\Storage;

use App\Models\PhrDicomUpload;
use App\Models\PhrEhiUpload;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\DataHub\PhrPatientDeletionCleanupService;
use App\Services\PHR\DataHub\PhrPatientDeletionService;
use App\Services\PHR\Documents\UploadUrlSigner;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * With PHR_STORAGE unset or local, a browser keeps today's transport, and nothing ever talks
 * to a store about uploads: the signer here throws on any call that would. That includes an
 * unset installation whose DICOM disk is on a bucket: it never chose direct uploads, and its
 * credentials may not allow the calls.
 */
class DirectUploadsOffTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private PhrPatient $patient;

    /** @return array<string, array{string}> */
    public static function choices(): array
    {
        return ['unset' => [''], 'local' => ['local']];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('phr_documents');
        Storage::fake('phr_dicom');
        Storage::fake('phr_exports');
        $this->app->instance(UploadUrlSigner::class, new class extends UploadUrlSigner
        {
            public function sign(string $disk, string $path, string $contentType, int $bytes, Carbon $expires): array
            {
                throw new LogicException('Nothing may sign a direct upload here.');
            }

            public function signPart(string $disk, string $path, string $uploadId, int $partNumber, int $bytes, Carbon $expires): array
            {
                throw new LogicException('Nothing may sign a direct upload here.');
            }

            public function createMultipartUpload(string $disk, string $path, string $contentType): string
            {
                throw new LogicException('Nothing may open a multipart upload here.');
            }

            public function listParts(string $disk, string $path, string $uploadId): array
            {
                throw new LogicException('Nothing may list parts here.');
            }

            public function abortMultipartUpload(string $disk, string $path, string $uploadId): void
            {
                throw new LogicException('Nothing may abort a multipart upload here.');
            }

            public function abortIncompleteUploads(string $disk, string $prefix, ?CarbonInterface $startedBefore = null, ?callable $keep = null): int
            {
                throw new LogicException('Nothing may list multipart uploads here.');
            }
        });
        $this->owner = $this->createUser();
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->owner->id, 'display_name' => 'Synthetic Patient', 'relationship' => 'self']);
        PhrPatientUserAccess::query()->create(['patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $this->owner->id, 'granted_at' => now()]);
    }

    private function ehi(): string
    {
        return "/api/phr/patients/{$this->patient->id}/ehi/uploads";
    }

    private function dicom(): string
    {
        return "/api/phr/patients/{$this->patient->id}/dicom/uploads";
    }

    #[DataProvider('choices')]
    public function test_an_ehi_upload_is_sent_in_chunks_as_always(string $choice): void
    {
        config(['phr.storage' => $choice]);

        $upload = $this->actingAs($this->owner)->postJson($this->ehi(), ['file_size_bytes' => 4])->assertCreated()
            ->assertJsonPath('upload.transport', 'chunks')->assertJsonPath('upload.part_size_bytes', null)->json('upload');
        $this->actingAs($this->owner)->withHeader('Accept', 'application/json')->post("{$this->ehi()}/{$upload['id']}/chunks", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'abcd'),
            'offset' => 0,
        ])->assertOk()->assertJsonPath('upload.uploaded_bytes', 4);

        $this->actingAs($this->owner)->postJson("{$this->ehi()}/{$upload['id']}/parts", ['part_number' => 1])->assertStatus(409);
        $this->actingAs($this->owner)->getJson("{$this->ehi()}/{$upload['id']}")->assertOk()->assertJsonPath('upload.uploaded_bytes', 4);
        $row = PhrEhiUpload::query()->findOrFail($upload['id']);
        $this->assertNull($row->multipart_upload_id);
        $this->assertSame('abcd', Storage::disk('phr_documents')->get((string) $row->storage_path));
    }

    #[DataProvider('choices')]
    public function test_a_dicom_session_takes_posts_and_signs_nothing(string $choice): void
    {
        config(['phr.storage' => $choice]);

        $opened = $this->actingAs($this->owner)->postJson($this->dicom(), [])->assertCreated()->assertJsonPath('transport', 'post');
        $this->assertSame(204_800 * 1024, $opened->json('limits.max_file_bytes'), 'the limit a POST has');
        $id = $opened->json('upload.id');

        $this->actingAs($this->owner)->postJson("{$this->dicom()}/{$id}/files/authorize", ['filename' => 'IM0001', 'byte_size' => 10])->assertStatus(409);
    }

    #[DataProvider('choices')]
    public function test_a_dicom_disk_on_a_bucket_with_nothing_chosen_keeps_posting(string $choice): void
    {
        config(['phr.storage' => $choice, 'filesystems.disks.phr_dicom.driver' => 's3']);

        $this->actingAs($this->owner)->postJson($this->dicom(), [])->assertCreated()->assertJsonPath('transport', 'post');
    }

    #[DataProvider('choices')]
    public function test_the_sweeps_and_patient_deletion_ask_the_store_nothing(string $choice): void
    {
        config(['phr.storage' => $choice]);
        $upload = $this->actingAs($this->owner)->postJson($this->ehi(), ['file_size_bytes' => 4])->assertCreated()->json('upload');
        $this->travel((int) config('phr.ehi_upload_retention_hours') + 3)->hours();

        $this->artisan('phr:dicom:gc')->assertSuccessful();
        $this->artisan('phr:ehi-uploads:purge')->assertSuccessful();
        $this->assertNull(PhrEhiUpload::query()->findOrFail($upload['id'])->storage_path);

        $deletions = app(PhrPatientDeletionService::class);
        $deletion = $deletions->delete($this->patient, (int) $this->owner->id, $deletions->preview($this->patient)->digest, false);
        app(PhrPatientDeletionCleanupService::class)->cleanup($deletion->id);
        $this->assertSame('completed', $deletion->refresh()->status);
    }

    public function test_the_collector_still_reaps_an_orphan_under_a_pending_upload_here(): void
    {
        config(['phr.storage' => '']);
        $opened = $this->actingAs($this->owner)->postJson($this->dicom(), [])->assertCreated()->json('upload.id');
        $prefix = (string) PhrDicomUpload::query()->findOrFail($opened)->r2_prefix;
        Storage::disk('phr_dicom')->put("{$prefix}/orphan", 'bytes');

        $this->artisan('phr:dicom:gc')->assertSuccessful();

        $this->assertFalse(Storage::disk('phr_dicom')->exists("{$prefix}/orphan"), 'as it always has');
    }
}

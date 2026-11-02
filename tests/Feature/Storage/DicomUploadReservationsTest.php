<?php

namespace Tests\Feature\Storage;

use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\DataHub\PhrPatientDeletionCleanupService;
use App\Services\PHR\DataHub\PhrPatientDeletionService;
use App\Services\PHR\Storage\BlobStorageQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\UsesObjectStoreDisks;
use Tests\TestCase;

/**
 * Authorized DICOM bytes count against storage limits from the moment a URL exists until they
 * are confirmed or reclaimed. A store accepts what a URL allows whether or not the application
 * is ever told, so authorizing is what has to be admitted, and repeating it can't pass a limit.
 */
class DicomUploadReservationsTest extends TestCase
{
    use RefreshDatabase;
    use UsesObjectStoreDisks;

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

    private function limitTo(int $bytes): void
    {
        DB::table('phr_storage_settings')->insert(['id' => 1, 'global_limit_bytes' => $bytes]);
        Cache::flush();
    }

    private function base(): string
    {
        return "/api/phr/patients/{$this->patient->id}/dicom/uploads";
    }

    private function open(): int
    {
        return (int) $this->actingAs($this->owner)->postJson($this->base(), [])->assertCreated()->assertJsonPath('transport', 's3')->json('upload.id');
    }

    /** @return TestResponse<Response> */
    private function authorize(int $uploadId, int $bytes, string $name = 'IM0001'): TestResponse
    {
        return $this->actingAs($this->owner)->postJson("{$this->base()}/{$uploadId}/files/authorize", [
            'relative_path' => "CT/{$name}", 'filename' => $name, 'byte_size' => $bytes,
        ]);
    }

    /** @return TestResponse<Response> */
    private function confirm(int $uploadId, string $token): TestResponse
    {
        return $this->actingAs($this->owner)->postJson("{$this->base()}/{$uploadId}/files/confirm", ['token' => $token]);
    }

    private function used(): int
    {
        return app(BlobStorageQuota::class)->usedBytes((int) $this->owner->id);
    }

    /** @return list<string> */
    private function left(): array
    {
        return Storage::disk('phr_dicom')->allFiles("patients/{$this->patient->id}");
    }

    public function test_repeated_authorizations_cannot_pass_a_limit_without_confirming_anything(): void
    {
        $this->limitTo(100);
        $uploadId = $this->open();

        $first = $this->authorize($uploadId, 60)->assertOk()->json('authorization');
        $this->authorize($uploadId, 60, 'IM0002')->assertStatus(422)->assertJsonValidationErrors('storage');
        $this->authorize($uploadId, 40, 'IM0003')->assertOk();
        $this->authorize($uploadId, 1, 'IM0004')->assertStatus(422)->assertJsonValidationErrors('storage');

        $this->assertSame(200, $this->putToStore($first, str_repeat('x', 60))['status']);
        $this->assertSame(100, $this->used());
    }

    public function test_a_file_that_exactly_fits_is_not_refused_at_confirm_for_its_own_reservation(): void
    {
        $bytes = $this->dicomBytes();
        $this->limitTo(strlen($bytes));
        $uploadId = $this->open();
        $authorization = $this->authorize($uploadId, strlen($bytes))->assertOk()->json('authorization');
        $this->putToStore($authorization, $bytes);

        $this->confirm($uploadId, $authorization['token'])->assertOk()->assertJsonPath('result.stored', true);

        $this->assertSame(strlen($bytes), $this->used(), 'counted once, as a stored file');
        $this->authorize($uploadId, 1, 'IM0002')->assertStatus(422)->assertJsonValidationErrors('storage');
    }

    public function test_confirming_a_stored_file_releases_its_reservation(): void
    {
        $bytes = $this->dicomBytes();
        $uploadId = $this->open();
        $authorization = $this->authorize($uploadId, strlen($bytes))->assertOk()->json('authorization');
        $this->assertSame(strlen($bytes), $this->used());
        $this->putToStore($authorization, $bytes);

        $this->confirm($uploadId, $authorization['token'])->assertOk();

        $this->assertSame(0, DB::table('phr_dicom_upload_reservations')->count());
        $this->assertSame(strlen($bytes), $this->used());
    }

    public function test_a_skipped_file_stays_reserved_until_its_url_expires_and_its_bytes_are_gone(): void
    {
        $uploadId = $this->open();
        $authorization = $this->authorize($uploadId, 13)->assertOk()->json('authorization');
        $this->putToStore($authorization, 'not a dicom!!');
        $this->confirm($uploadId, $authorization['token'])->assertOk()->assertJsonPath('result.stored', false);

        // The URL can still land these bytes again, in a session that stays open.
        $this->assertSame(200, $this->putToStore($authorization, 'not a dicom!!')['status']);
        $this->assertCount(1, $this->left());
        $this->assertSame(13, $this->used());
        $this->artisan('phr:dicom:gc')->assertSuccessful();
        $this->assertSame(13, $this->used(), 'the URL still lives');

        $this->travel(16)->minutes();
        $this->artisan('phr:dicom:gc')->assertSuccessful();

        $this->assertSame([], $this->left());
        $this->assertSame(0, $this->used());
    }

    public function test_a_cancel_holds_the_reservation_until_the_url_expires_and_the_collector_reclaims(): void
    {
        $uploadId = $this->open();
        $authorization = $this->authorize($uploadId, 10)->assertOk()->json('authorization');
        $this->actingAs($this->owner)->postJson("{$this->base()}/{$uploadId}/cancel")->assertOk();
        $this->assertSame(200, $this->putToStore($authorization, str_repeat('x', 10))['status']);

        $this->assertSame(10, $this->used(), 'the URL can still land these bytes');
        $this->artisan('phr:dicom:gc')->assertSuccessful();
        $this->assertSame(10, $this->used());

        $this->travel(16)->minutes();
        $this->artisan('phr:dicom:gc')->assertSuccessful();

        $this->assertSame(0, $this->used());
        $this->assertSame([], $this->left());
    }

    public function test_finalizing_with_a_file_never_confirmed_holds_then_releases_the_same_way(): void
    {
        $bytes = $this->dicomBytes();
        $uploadId = $this->open();
        $stored = $this->authorize($uploadId, strlen($bytes))->assertOk()->json('authorization');
        $this->putToStore($stored, $bytes);
        $this->confirm($uploadId, $stored['token'])->assertOk();
        $this->authorize($uploadId, 10, 'IM0002')->assertOk();
        $this->actingAs($this->owner)->postJson("{$this->base()}/{$uploadId}/finalize")->assertOk();
        $this->assertSame(strlen($bytes) + 10, $this->used());

        $this->travel(16)->minutes();
        $this->artisan('phr:dicom:gc')->assertSuccessful();

        $this->assertSame(strlen($bytes), $this->used());
    }

    public function test_an_abandoned_session_is_failed_by_the_collector_and_its_reservations_released(): void
    {
        $uploadId = $this->open();
        $authorization = $this->authorize($uploadId, 10)->assertOk()->json('authorization');
        $this->putToStore($authorization, str_repeat('x', 10));
        $this->artisan('phr:dicom:gc')->assertSuccessful();
        $this->assertSame(10, $this->used(), 'a pending session can still confirm it');
        $this->assertCount(1, $this->left());

        $this->travel(7)->hours();
        DB::table('phr_dicom_uploads')->where('id', $uploadId)->update(['created_at' => now()->subHours(7), 'updated_at' => now()->subHours(7)]);
        $this->artisan('phr:dicom:gc')->assertSuccessful();

        $this->assertSame(0, $this->used());
        $this->assertSame([], $this->left());
    }

    public function test_patient_deletion_releases_every_reservation(): void
    {
        $uploadId = $this->open();
        $this->authorize($uploadId, 10)->assertOk();
        $this->actingAs($this->owner)->postJson("{$this->base()}/{$uploadId}/cancel")->assertOk();
        $this->travel(16)->minutes();

        $deletions = app(PhrPatientDeletionService::class);
        $deletion = $deletions->delete($this->patient, (int) $this->owner->id, $deletions->preview($this->patient)->digest, false);
        app(PhrPatientDeletionCleanupService::class)->cleanup($deletion->id);

        $this->assertSame(0, DB::table('phr_dicom_upload_reservations')->count());
        $this->assertSame(0, $this->used());
    }

    public function test_a_late_put_after_a_stored_file_is_reaped_while_its_session_stays_open(): void
    {
        $bytes = $this->dicomBytes();
        $uploadId = $this->open();
        $authorization = $this->authorize($uploadId, strlen($bytes))->assertOk()->json('authorization');
        $this->putToStore($authorization, $bytes);
        $this->confirm($uploadId, $authorization['token'])->assertOk();
        $this->assertSame(200, $this->putToStore($authorization, $bytes)['status']);
        $this->assertCount(2, $this->left());

        $this->artisan('phr:dicom:gc')->assertSuccessful();

        $this->assertCount(1, $this->left(), 'nothing reserved it, so nothing protects it');
    }

    public function test_a_confirm_after_its_reservation_is_gone_is_refused(): void
    {
        $uploadId = $this->open();
        $authorization = $this->authorize($uploadId, 13)->assertOk()->json('authorization');
        $this->putToStore($authorization, 'not a dicom!!');
        $this->confirm($uploadId, $authorization['token'])->assertOk();
        $this->assertSame(200, $this->putToStore($authorization, 'not a dicom!!')['status']);

        $this->confirm($uploadId, $authorization['token'])->assertStatus(422);
    }

    /** A minimal instance PHR's own parser accepts; see PhrDicomTest. */
    private function dicomBytes(): string
    {
        $element = static function (int $group, int $element, string $vr, string $value): string {
            if (strlen($value) % 2 === 1) {
                $value .= $vr === 'UI' ? "\0" : ' ';
            }

            return pack('v', $group).pack('v', $element).$vr.pack('v', strlen($value)).$value;
        };

        return str_repeat("\0", 128).'DICM'
            .$element(0x0002, 0x0010, 'UI', '1.2.840.10008.1.2.1')
            .$element(0x0008, 0x0016, 'UI', '1.2.840.10008.5.1.4.1.1.2')
            .$element(0x0008, 0x0018, 'UI', '1.2.840.113619.2.55.3.604688437.20260517.1.1.1')
            .$element(0x0008, 0x0020, 'DA', '20260517')
            .$element(0x0008, 0x0030, 'TM', '101112')
            .$element(0x0008, 0x0060, 'CS', 'CT')
            .$element(0x0008, 0x1030, 'LO', 'Cardiac CT')
            .$element(0x0010, 0x0010, 'PN', 'Primary^Patient')
            .$element(0x0020, 0x000D, 'UI', '1.2.840.113619.2.55.3.604688437.20260517.1')
            .$element(0x0020, 0x000E, 'UI', '1.2.840.113619.2.55.3.604688437.20260517.1.1')
            .$element(0x0020, 0x0011, 'IS', '1')
            .$element(0x0020, 0x0013, 'IS', '1')
            .$element(0x0020, 0x0032, 'DS', '0\\0\\0')
            .$element(0x0020, 0x0037, 'DS', '1\\0\\0\\0\\1\\0')
            .$element(0x0020, 0x0052, 'UI', '1.2.3.4.5')
            .$element(0x0028, 0x0002, 'US', pack('v', 1))
            .$element(0x0028, 0x0004, 'CS', 'MONOCHROME2')
            .$element(0x0028, 0x0010, 'US', pack('v', 512))
            .$element(0x0028, 0x0011, 'US', pack('v', 512))
            .$element(0x0028, 0x0030, 'DS', '0.7\\0.7')
            .$element(0x0028, 0x0100, 'US', pack('v', 16))
            .$element(0x0028, 0x0101, 'US', pack('v', 12))
            .$element(0x0028, 0x0102, 'US', pack('v', 11))
            .$element(0x0028, 0x0103, 'US', pack('v', 0));
    }
}

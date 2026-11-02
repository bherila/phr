<?php

namespace Tests\Feature\Storage;

use App\Jobs\PHR\IntakeEhiUploadJob;
use App\Models\PhrDicomFile;
use App\Models\PhrDicomUpload;
use App\Models\PhrEhiBlob;
use App\Models\PhrEhiUpload;
use App\Models\PhrPatient;
use App\Models\PhrPatientDeletion;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\DataHub\PhrPatientDeletionCleanupService;
use App\Services\PHR\DataHub\PhrPatientDeletionService;
use App\Services\PHR\Documents\UploadUrlSigner;
use App\Services\PHR\Import\Ehi\EhiUploadService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\Storage\PhrStorageKey;
use App\Support\Storage\PhrStorageVerifier;
use App\Support\Storage\StagedUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiIntakeFixtures;
use Tests\Support\FakeObjectStoreUploads;
use Tests\Support\UsesObjectStoreDisks;
use Tests\TestCase;
use ZipArchive;

/**
 * Bytes sent straight to the store (#241), through the routes the browser calls. On the
 * default disk FakeObjectStoreUploads plays the store; with PHR_TEST_S3_ENDPOINT these are
 * real signed PUTs and real multipart uploads against a real server.
 */
class DirectUploadsTest extends TestCase
{
    use ConfiguresPassportKeys;
    use EhiIntakeFixtures;
    use RefreshDatabase;
    use UsesObjectStoreDisks;

    private const int MIB = 1024 * 1024;

    private User $owner;

    private PhrPatient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
        Cache::flush();
        Queue::fake();
        $this->useObjectStoreDisks();
        $this->useDirectUploads();
        // The smallest parts the store allows, so a test's file needs only a few megabytes.
        config(['phr.ehi_upload_chunk_bytes' => 5 * self::MIB]);
        $this->owner = $this->createUser();
        $this->patient = $this->ehiPatient($this->owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $this->owner->id, 'granted_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->tearDownObjectStoreDisks();
        $this->tearDownEhi();
        parent::tearDown();
    }

    private function ehiBase(): string
    {
        return "/api/phr/patients/{$this->patient->id}/ehi/uploads";
    }

    private function dicomBase(): string
    {
        return "/api/phr/patients/{$this->patient->id}/dicom";
    }

    /** An EHI export over one part, whatever the part size: incompressible padding, stored. */
    private function largeEhiZip(int $paddingBytes = 6 * self::MIB): string
    {
        $package = EhiClinicalPackage::standard()->build()->with('EHITables/padding.bin', random_bytes($paddingBytes));

        return $package->writeZip($this->ehiRoot.'/'.bin2hex(random_bytes(4)).'.zip', ZipArchive::CM_STORE);
    }

    private function myChartZip(int $paddingBytes): string
    {
        $path = $this->ehiRoot.'/'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('Records/bundle.json', (string) json_encode(['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            ['resource' => ['resourceType' => 'Condition', 'id' => 'condition-1', 'code' => ['text' => 'Synthetic condition']]],
        ]]));
        $zip->addFromString('padding.bin', random_bytes($paddingBytes));
        $zip->setCompressionName('padding.bin', ZipArchive::CM_STORE);
        $zip->close();

        return $path;
    }

    /** @return array{id: int, part_size_bytes: int} */
    private function startEhi(int $bytes): array
    {
        $upload = $this->actingAs($this->owner)->postJson($this->ehiBase(), ['file_size_bytes' => $bytes])->assertCreated()->json('upload');
        $this->assertSame('s3', $upload['transport']);

        return $upload;
    }

    /** @return array<string, mixed> */
    private function signPart(int $id, int $partNumber): array
    {
        return $this->actingAs($this->owner)->postJson("{$this->ehiBase()}/{$id}/parts", ['part_number' => $partNumber])->assertOk()->json('part');
    }

    /** @return list<array{part_number: int, etag: string}> */
    private function sendAllParts(int $id, string $bytes, int $partSize): array
    {
        $parts = [];
        foreach (str_split($bytes, $partSize) as $index => $body) {
            $signed = $this->signPart($id, $index + 1);
            $this->assertSame(strlen($body), $signed['byte_size']);
            $sent = $this->putToStore($signed, $body);
            $this->assertSame(200, $sent['status']);
            $this->assertNotNull($sent['etag']);
            $parts[] = ['part_number' => $index + 1, 'etag' => (string) $sent['etag']];
        }

        return $parts;
    }

    private function runIntake(int $id): PhrEhiUpload
    {
        (new IntakeEhiUploadJob($id))->handle(app(EhiUploadService::class));

        return PhrEhiUpload::query()->findOrFail($id);
    }

    public function test_an_ehi_export_goes_straight_to_the_store_in_parts_and_is_taken_in(): void
    {
        $bytes = (string) file_get_contents($this->largeEhiZip());
        $upload = $this->startEhi(strlen($bytes));
        $this->assertSame(5 * self::MIB, $upload['part_size_bytes']);
        $row = PhrEhiUpload::query()->findOrFail($upload['id']);
        $this->assertFalse(Storage::disk(StagedUpload::SPOOL_DISK)->exists((string) $row->storage_path), 'nothing is spooled');
        $this->assertStringStartsWith("patients/{$this->patient->id}/ehi-uploads/", (string) $row->storage_path, 'the same key as a chunked upload');
        $this->assertSame([(string) $row->multipart_upload_id], $this->openUploads('phr_documents', "patients/{$this->patient->id}/"));

        $parts = $this->sendAllParts($upload['id'], $bytes, 5 * self::MIB);
        $this->assertCount(2, $parts);

        $this->actingAs($this->owner)->postJson("{$this->ehiBase()}/{$upload['id']}/intake", ['parts' => $parts])->assertStatus(202)->assertJsonPath('upload.status', 'queued');
        Queue::assertPushed(IntakeEhiUploadJob::class);
        $row->refresh();
        $this->assertNull($row->multipart_upload_id);
        $this->assertSame(strlen($bytes), $row->uploaded_bytes);
        $this->assertSame($bytes, Storage::disk('phr_documents')->get((string) $row->storage_path));
        $this->assertSame([], $this->openUploads('phr_documents', "patients/{$this->patient->id}/"));

        $staged = (string) $row->storage_path;
        $finished = $this->runIntake($upload['id']);

        $this->assertSame(PhrEhiUpload::STATUS_COMPLETED, $finished->status, (string) $finished->failure_reason);
        $this->assertSame(PhrEhiUpload::FORMAT_EHI, $finished->format);
        $this->assertFalse(Storage::disk('phr_documents')->exists($staged), 'the archive goes once taken in');
        $this->assertGreaterThan(0, PhrEhiBlob::query()->where('patient_id', $this->patient->id)->count());
    }

    public function test_a_mychart_export_goes_the_same_way(): void
    {
        $bytes = (string) file_get_contents($this->myChartZip(6 * self::MIB));
        $upload = $this->startEhi(strlen($bytes));
        $parts = $this->sendAllParts($upload['id'], $bytes, 5 * self::MIB);

        $this->actingAs($this->owner)->postJson("{$this->ehiBase()}/{$upload['id']}/intake", ['parts' => $parts])->assertStatus(202);
        $finished = $this->runIntake($upload['id']);

        $this->assertSame(PhrEhiUpload::STATUS_COMPLETED, $finished->status, (string) $finished->failure_reason);
        $this->assertSame(PhrEhiUpload::FORMAT_MYCHART, $finished->format);
    }

    public function test_a_small_file_is_one_short_part(): void
    {
        $bytes = (string) file_get_contents($this->myChartZip(1024));
        $upload = $this->startEhi(strlen($bytes));

        $parts = $this->sendAllParts($upload['id'], $bytes, 5 * self::MIB);

        $this->assertCount(1, $parts);
        $this->actingAs($this->owner)->postJson("{$this->ehiBase()}/{$upload['id']}/intake", ['parts' => $parts])->assertStatus(202);
        $this->assertSame(PhrEhiUpload::STATUS_COMPLETED, $this->runIntake($upload['id'])->status);
    }

    public function test_the_store_refuses_a_part_of_any_other_length(): void
    {
        $upload = $this->startEhi(6 * self::MIB);

        $signed = $this->signPart($upload['id'], 2);
        $this->assertSame(self::MIB, $signed['byte_size'], 'the last part is what is left');

        $this->assertSame(403, $this->putToStore($signed, str_repeat('x', self::MIB - 1))['status']);
        $this->assertSame(403, $this->putToStore($signed, str_repeat('x', self::MIB + 1))['status']);
        $this->assertSame(200, $this->putToStore($signed, str_repeat('x', self::MIB))['status']);
    }

    public function test_a_part_the_plan_does_not_have_is_not_signed(): void
    {
        $upload = $this->startEhi(6 * self::MIB);

        $this->actingAs($this->owner)->postJson("{$this->ehiBase()}/{$upload['id']}/parts", ['part_number' => 3])->assertStatus(409)->assertJsonPath('error', 'upload_state_invalid');
        $this->actingAs($this->owner)->postJson("{$this->ehiBase()}/{$upload['id']}/parts", ['part_number' => 0])->assertStatus(422);
        $this->actingAs($this->owner)->postJson("{$this->ehiBase()}/{$upload['id']}/parts", ['part_number' => 10_001])->assertStatus(422);
    }

    public function test_someone_without_write_access_gets_no_signature(): void
    {
        $upload = $this->startEhi(6 * self::MIB);

        $this->actingAs($this->createUser())->postJson("{$this->ehiBase()}/{$upload['id']}/parts", ['part_number' => 1])->assertNotFound();
    }

    public function test_finishing_needs_every_part_at_its_length_with_the_etag_the_browser_saw(): void
    {
        $bytes = (string) file_get_contents($this->largeEhiZip());
        $upload = $this->startEhi(strlen($bytes));
        $parts = $this->sendAllParts($upload['id'], $bytes, 5 * self::MIB);
        $intake = "{$this->ehiBase()}/{$upload['id']}/intake";

        $this->actingAs($this->owner)->postJson($intake, ['parts' => [$parts[0]]])->assertStatus(409)->assertJsonPath('error', 'upload_incomplete');
        $wrong = [$parts[0], ['part_number' => 2, 'etag' => '"synthetic-wrong"']];
        $this->actingAs($this->owner)->postJson($intake, ['parts' => $wrong])->assertStatus(409)->assertJsonPath('error', 'upload_incomplete');
        $this->assertSame('uploading', PhrEhiUpload::query()->findOrFail($upload['id'])->status, 'still open, so the browser can try again');

        $this->actingAs($this->owner)->postJson($intake, ['parts' => $parts])->assertStatus(202);
    }

    public function test_a_part_held_at_the_wrong_length_is_refused(): void
    {
        $upload = $this->startEhi(6 * self::MIB);
        $row = PhrEhiUpload::query()->findOrFail($upload['id']);
        // A short URL signed before the plan: what a tampered row, or a bug, would leave.
        $signed = app(UploadUrlSigner::class)->signPart('phr_documents', (string) $row->storage_path, (string) $row->multipart_upload_id, 1, 5 * self::MIB + 1, now()->addMinutes(5));
        $first = $this->putToStore($signed, str_repeat('x', 5 * self::MIB + 1));
        $second = $this->putToStore($this->signPart($upload['id'], 2), str_repeat('x', self::MIB));

        $this->actingAs($this->owner)->postJson("{$this->ehiBase()}/{$upload['id']}/intake", ['parts' => [
            ['part_number' => 1, 'etag' => (string) $first['etag']],
            ['part_number' => 2, 'etag' => (string) $second['etag']],
        ]])->assertStatus(409)->assertJsonPath('error', 'upload_incomplete');
    }

    public function test_a_finish_that_failed_can_be_retried_with_no_etags_and_no_new_upload(): void
    {
        $bytes = (string) file_get_contents($this->largeEhiZip());
        $upload = $this->startEhi(strlen($bytes));
        $this->sendAllParts($upload['id'], $bytes, 5 * self::MIB);
        $row = PhrEhiUpload::query()->findOrFail($upload['id']);

        // As after a reload: the browser's ETags are gone, and the server asks the store.
        $this->actingAs($this->owner)->postJson("{$this->ehiBase()}/{$upload['id']}/intake", [])->assertStatus(202)->assertJsonPath('upload.status', 'queued');

        $row->refresh();
        $this->assertNull($row->multipart_upload_id);
        $this->assertSame(strlen($bytes), $row->uploaded_bytes);
        $this->assertSame($bytes, Storage::disk('phr_documents')->get((string) $row->storage_path));
        $this->assertSame(PhrEhiUpload::STATUS_COMPLETED, $this->runIntake($upload['id'])->status);
    }

    public function test_a_retry_is_refused_while_any_part_is_missing_and_stays_open(): void
    {
        $bytes = (string) file_get_contents($this->largeEhiZip());
        $upload = $this->startEhi(strlen($bytes));
        $first = substr($bytes, 0, 5 * self::MIB);
        $this->assertSame(200, $this->putToStore($this->signPart($upload['id'], 1), $first)['status']);
        $intake = "{$this->ehiBase()}/{$upload['id']}/intake";

        $this->actingAs($this->owner)->postJson($intake, [])->assertStatus(409)->assertJsonPath('error', 'upload_incomplete');

        $row = PhrEhiUpload::query()->findOrFail($upload['id']);
        $this->assertSame('uploading', $row->status);
        $this->assertNotNull($row->multipart_upload_id, 'nothing was completed');

        $this->assertSame(200, $this->putToStore($this->signPart($upload['id'], 2), substr($bytes, 5 * self::MIB))['status']);
        $this->actingAs($this->owner)->postJson($intake, [])->assertStatus(202);
    }

    public function test_a_retry_checks_every_part_at_the_length_the_plan_says(): void
    {
        $upload = $this->startEhi(6 * self::MIB);
        $row = PhrEhiUpload::query()->findOrFail($upload['id']);
        $wrong = app(UploadUrlSigner::class)->signPart('phr_documents', (string) $row->storage_path, (string) $row->multipart_upload_id, 1, 5 * self::MIB + 1, now()->addMinutes(5));
        $this->assertSame(200, $this->putToStore($wrong, str_repeat('x', 5 * self::MIB + 1))['status']);
        $this->assertSame(200, $this->putToStore($this->signPart($upload['id'], 2), str_repeat('x', self::MIB))['status']);

        $this->actingAs($this->owner)->postJson("{$this->ehiBase()}/{$upload['id']}/intake", [])->assertStatus(409)->assertJsonPath('error', 'upload_incomplete');

        $this->assertNotNull($row->refresh()->multipart_upload_id);
    }

    public function test_an_expired_upload_cannot_be_retried(): void
    {
        $bytes = (string) file_get_contents($this->myChartZip(1024));
        $upload = $this->startEhi(strlen($bytes));
        $this->sendAllParts($upload['id'], $bytes, 5 * self::MIB);
        $this->travel((int) config('phr.ehi_upload_retention_hours') + 1)->hours();

        $this->actingAs($this->owner)->postJson("{$this->ehiBase()}/{$upload['id']}/intake", [])->assertStatus(409)->assertJsonPath('error', 'upload_expired');
    }

    public function test_reading_an_upload_says_what_has_really_reached_the_store(): void
    {
        $bytes = (string) file_get_contents($this->largeEhiZip());
        $upload = $this->startEhi(strlen($bytes));
        $url = "{$this->ehiBase()}/{$upload['id']}";
        $this->assertSame(0, $this->actingAs($this->owner)->getJson($url)->assertOk()->json('upload.uploaded_bytes'));

        $this->assertSame(200, $this->putToStore($this->signPart($upload['id'], 1), substr($bytes, 0, 5 * self::MIB))['status']);
        $this->assertSame(5 * self::MIB, $this->actingAs($this->owner)->getJson($url)->assertOk()->json('upload.uploaded_bytes'));

        $last = $this->signPart($upload['id'], 2);
        $before = PhrEhiUpload::query()->findOrFail($upload['id'])->uploaded_bytes;
        $this->assertSame(5 * self::MIB, $this->actingAs($this->owner)->getJson($url)->assertOk()->json('upload.uploaded_bytes'), 'signing a part is not its arrival');

        $this->assertSame(200, $this->putToStore($last, substr($bytes, 5 * self::MIB))['status']);
        $this->assertSame(strlen($bytes), $this->actingAs($this->owner)->getJson($url)->assertOk()->json('upload.uploaded_bytes'), 'the page can now offer to finish');
        $this->assertSame($before, PhrEhiUpload::query()->findOrFail($upload['id'])->uploaded_bytes, 'a read writes nothing');
    }

    public function test_reading_an_upload_with_a_part_at_the_wrong_length_does_not_call_it_whole(): void
    {
        $upload = $this->startEhi(6 * self::MIB);
        $row = PhrEhiUpload::query()->findOrFail($upload['id']);
        $wrong = app(UploadUrlSigner::class)->signPart('phr_documents', (string) $row->storage_path, (string) $row->multipart_upload_id, 2, self::MIB - 1, now()->addMinutes(5));
        $this->assertSame(200, $this->putToStore($this->signPart($upload['id'], 1), str_repeat('x', 5 * self::MIB))['status']);
        $this->assertSame(200, $this->putToStore($wrong, str_repeat('x', self::MIB - 1))['status']);

        $this->assertSame(5 * self::MIB, $this->actingAs($this->owner)->getJson("{$this->ehiBase()}/{$upload['id']}")->assertOk()->json('upload.uploaded_bytes'));
    }

    public function test_reading_an_upload_still_answers_when_the_store_will_not(): void
    {
        $store = app(UploadUrlSigner::class);
        if (! $store instanceof FakeObjectStoreUploads) {
            $this->markTestSkipped('Injects a storage failure, which only the default disk can do.');
        }
        $upload = $this->startEhi(6 * self::MIB);
        $this->signPart($upload['id'], 2);
        $store->failLists = true;

        $this->assertSame(5 * self::MIB, $this->actingAs($this->owner)->getJson("{$this->ehiBase()}/{$upload['id']}")->assertOk()->json('upload.uploaded_bytes'));
        $this->actingAs($this->owner)->postJson("{$this->ehiBase()}/{$upload['id']}/intake", [])->assertStatus(503);
    }

    public function test_an_upload_the_store_finished_but_the_row_missed_can_still_be_finished(): void
    {
        $bytes = (string) file_get_contents($this->largeEhiZip());
        $upload = $this->startEhi(strlen($bytes));
        $parts = $this->sendAllParts($upload['id'], $bytes, 5 * self::MIB);
        $row = PhrEhiUpload::query()->findOrFail($upload['id']);
        // The store finished, then the request died before the row was updated.
        app(UploadUrlSigner::class)->completeMultipartUpload('phr_documents', (string) $row->storage_path, (string) $row->multipart_upload_id, array_column($parts, 'etag', 'part_number'));
        $this->assertNotNull($row->refresh()->multipart_upload_id);
        $url = "{$this->ehiBase()}/{$upload['id']}";

        $this->assertSame(strlen($bytes), $this->actingAs($this->owner)->getJson($url)->assertOk()->json('upload.uploaded_bytes'), 'the page offers to finish, not to start over');
        $this->assertNotSame(strlen($bytes), $row->refresh()->uploaded_bytes, 'a read writes nothing');

        $this->actingAs($this->owner)->postJson("{$url}/intake", [])->assertStatus(202)->assertJsonPath('upload.status', 'queued');

        $this->assertNull($row->refresh()->multipart_upload_id);
        $this->assertSame(PhrEhiUpload::STATUS_COMPLETED, $this->runIntake($upload['id'])->status);
    }

    public function test_an_upload_gone_from_the_store_with_no_object_reports_what_the_row_holds(): void
    {
        $upload = $this->startEhi(6 * self::MIB);
        $this->signPart($upload['id'], 2);
        $row = PhrEhiUpload::query()->findOrFail($upload['id']);
        app(UploadUrlSigner::class)->abortMultipartUpload('phr_documents', (string) $row->storage_path, (string) $row->multipart_upload_id);

        $this->assertSame(5 * self::MIB, $this->actingAs($this->owner)->getJson("{$this->ehiBase()}/{$upload['id']}")->assertOk()->json('upload.uploaded_bytes'));
    }

    public function test_an_object_of_another_size_at_the_key_does_not_count_as_the_finished_upload(): void
    {
        $upload = $this->startEhi(6 * self::MIB);
        $this->signPart($upload['id'], 2);
        $row = PhrEhiUpload::query()->findOrFail($upload['id']);
        app(UploadUrlSigner::class)->abortMultipartUpload('phr_documents', (string) $row->storage_path, (string) $row->multipart_upload_id);
        Storage::disk('phr_documents')->put((string) $row->storage_path, 'short');

        $this->assertSame(5 * self::MIB, $this->actingAs($this->owner)->getJson("{$this->ehiBase()}/{$upload['id']}")->assertOk()->json('upload.uploaded_bytes'));
    }

    public function test_reading_an_upload_answers_with_the_row_when_the_store_refuses_both_questions(): void
    {
        $store = app(UploadUrlSigner::class);
        if (! $store instanceof FakeObjectStoreUploads) {
            $this->markTestSkipped('Injects a storage failure, which only the default disk can do.');
        }
        $upload = $this->startEhi(6 * self::MIB);
        $this->signPart($upload['id'], 2);
        $store->failLists = true;
        $store->failHeads = true;

        $this->assertSame(5 * self::MIB, $this->actingAs($this->owner)->getJson("{$this->ehiBase()}/{$upload['id']}")->assertOk()->json('upload.uploaded_bytes'));
    }

    public function test_finishing_twice_queues_once(): void
    {
        $bytes = (string) file_get_contents($this->myChartZip(1024));
        $upload = $this->startEhi(strlen($bytes));
        $parts = $this->sendAllParts($upload['id'], $bytes, 5 * self::MIB);
        $intake = "{$this->ehiBase()}/{$upload['id']}/intake";

        $this->actingAs($this->owner)->postJson($intake, ['parts' => $parts])->assertStatus(202);
        $this->actingAs($this->owner)->postJson($intake, ['parts' => $parts])->assertStatus(409)->assertJsonPath('error', 'not_queueable');
    }

    public function test_chunks_are_refused_on_an_upload_that_goes_to_the_store(): void
    {
        $upload = $this->startEhi(10);

        $this->actingAs($this->owner)->withHeader('Accept', 'application/json')->post("{$this->ehiBase()}/{$upload['id']}/chunks", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'x'),
            'offset' => 0,
        ])->assertStatus(409)->assertJsonPath('error', 'upload_state_invalid');
    }

    public function test_an_expired_upload_gets_no_more_signatures_and_the_purge_aborts_it(): void
    {
        $upload = $this->startEhi(6 * self::MIB);
        $signed = $this->signPart($upload['id'], 2);
        $this->assertSame(200, $this->putToStore($signed, str_repeat('x', self::MIB))['status']);
        $this->assertCount(1, $this->openUploads('phr_documents'));
        $this->travel((int) config('phr.ehi_upload_retention_hours') + 1)->hours();

        $this->actingAs($this->owner)->postJson("{$this->ehiBase()}/{$upload['id']}/parts", ['part_number' => 1])->assertStatus(409)->assertJsonPath('error', 'upload_expired');

        $this->artisan('phr:ehi-uploads:purge')->assertSuccessful();

        $this->assertSame([], $this->openUploads('phr_documents'));
        $row = PhrEhiUpload::query()->findOrFail($upload['id']);
        $this->assertNull($row->multipart_upload_id);
        $this->assertNull($row->storage_path);
        $this->assertSame('failed', $row->status);
    }

    public function test_the_purge_aborts_an_upload_no_row_knows_once_it_is_old_enough_and_no_other(): void
    {
        $signer = app(UploadUrlSigner::class);
        $forgotten = $signer->createMultipartUpload('phr_documents', 'patients/1/ehi-uploads/forgotten/package.zip', 'application/zip');
        $upload = $this->startEhi(6 * self::MIB);
        $live = (string) PhrEhiUpload::query()->findOrFail($upload['id'])->multipart_upload_id;

        $this->artisan('phr:ehi-uploads:purge')->assertSuccessful();
        $this->assertEqualsCanonicalizing([$forgotten, $live], $this->openUploads('phr_documents'), 'too recent to be sure of');

        // Keep the row's upload alive past the window, to prove a row protects it.
        PhrEhiUpload::query()->whereKey($upload['id'])->update(['expires_at' => now()->addDays(30)]);
        $this->travel((int) config('phr.ehi_upload_retention_hours') + 3)->hours();
        $this->artisan('phr:ehi-uploads:purge')->assertSuccessful();

        $this->assertSame([$live], $this->openUploads('phr_documents'));
    }

    public function test_a_purge_that_cannot_abort_keeps_the_row_for_the_next_run(): void
    {
        $store = app(UploadUrlSigner::class);
        if (! $store instanceof FakeObjectStoreUploads) {
            $this->markTestSkipped('Injects a storage failure, which only the default disk can do.');
        }
        $upload = $this->startEhi(6 * self::MIB);
        $this->travel((int) config('phr.ehi_upload_retention_hours') + 1)->hours();
        $store->failAborts = true;

        $this->artisan('phr:ehi-uploads:purge')->assertFailed();

        $this->assertNotNull(PhrEhiUpload::query()->findOrFail($upload['id'])->multipart_upload_id);

        $store->failAborts = false;
        $this->artisan('phr:ehi-uploads:purge')->assertSuccessful();
        $this->assertNull(PhrEhiUpload::query()->findOrFail($upload['id'])->storage_path);
    }

    public function test_patient_deletion_aborts_an_open_upload_and_its_part_urls_stop_working(): void
    {
        $upload = $this->startEhi(6 * self::MIB);
        $signed = $this->signPart($upload['id'], 2);
        $this->assertSame(200, $this->putToStore($signed, str_repeat('x', self::MIB))['status']);
        $other = $this->ehiPatient($this->createUser());
        $otherUpload = app(UploadUrlSigner::class)->createMultipartUpload('phr_documents', "patients/{$other->id}/ehi-uploads/other/package.zip", 'application/zip');

        // Deletion waits for the part URL. See SignedWriteUrlsHoldDeletionTest.
        $this->travel(16)->minutes();
        $deletions = app(PhrPatientDeletionService::class);
        $plan = $deletions->preview($this->patient);
        $this->assertSame([], $plan->blockers);
        $deletion = $deletions->delete($this->patient, (int) $this->owner->id, $plan->digest, false);
        app(PhrPatientDeletionCleanupService::class)->cleanup($deletion->id);

        $this->assertSame([], $this->openUploads('phr_documents', "patients/{$this->patient->id}/"));
        $this->assertSame([$otherUpload], $this->openUploads('phr_documents', 'patients/'), 'another patient’s upload is left alone');
        $this->assertSame('completed', $deletion->refresh()->status);
        $this->assertGreaterThanOrEqual(400, $this->putToStore($signed, str_repeat('x', self::MIB))['status']);
    }

    public function test_patient_deletion_stays_retryable_when_it_cannot_abort(): void
    {
        $store = app(UploadUrlSigner::class);
        if (! $store instanceof FakeObjectStoreUploads) {
            $this->markTestSkipped('Injects a storage failure, which only the default disk can do.');
        }
        $this->startEhi(6 * self::MIB);
        $deletions = app(PhrPatientDeletionService::class);
        $deletion = $deletions->delete($this->patient, (int) $this->owner->id, $deletions->preview($this->patient)->digest, false);
        $store->failAborts = true;

        try {
            app(PhrPatientDeletionCleanupService::class)->cleanup($deletion->id);
            $this->fail('The cleanup should report the failure so the job retries.');
        } catch (\RuntimeException) {
            $this->assertSame(PhrPatientDeletion::STATUS_FAILED, $deletion->refresh()->status);
        }

        $store->failAborts = false;
        app(PhrPatientDeletionCleanupService::class)->cleanup($deletion->id);
        $this->assertSame('completed', $deletion->refresh()->status);
        $this->assertSame([], $this->openUploads('phr_documents'));
    }

    public function test_the_dicom_collector_aborts_an_incomplete_upload_only_once_it_is_old(): void
    {
        app(UploadUrlSigner::class)->createMultipartUpload('phr_dicom', 'patients/1/imaging/dicom/uploads/left/IM0001', 'application/dicom');

        $this->artisan('phr:dicom:gc')->assertSuccessful();
        $this->assertCount(1, $this->openUploads('phr_dicom'), 'it could still be in progress');

        $this->travel(7)->hours();
        $this->artisan('phr:dicom:gc', ['--dry-run' => true])->assertSuccessful();
        $this->assertCount(1, $this->openUploads('phr_dicom'), 'a dry run touches nothing');

        $this->artisan('phr:dicom:gc')->assertSuccessful();
        $this->assertSame([], $this->openUploads('phr_dicom'));
    }

    /** @return array{0: int, 1: array<string, mixed>} the session id, and what opening it answered */
    private function openDicom(): array
    {
        $opened = $this->actingAs($this->owner)->postJson("{$this->dicomBase()}/uploads", ['root_name' => 'CT'])->assertCreated();
        $opened->assertJsonPath('transport', 's3');

        return [(int) $opened->json('upload.id'), $opened->json()];
    }

    /** @return TestResponse<Response> */
    private function authorizeDicom(int $uploadId, string $relativePath, int $bytes, string $name = 'IM0001'): TestResponse
    {
        return $this->actingAs($this->owner)->postJson("{$this->dicomBase()}/uploads/{$uploadId}/files/authorize", [
            'relative_path' => $relativePath, 'filename' => $name, 'byte_size' => $bytes, 'content_type' => 'application/dicom',
        ]);
    }

    /** @return TestResponse<Response> */
    private function confirmDicom(int $uploadId, string $token): TestResponse
    {
        return $this->actingAs($this->owner)->postJson("{$this->dicomBase()}/uploads/{$uploadId}/files/confirm", ['token' => $token]);
    }

    public function test_a_dicom_folder_goes_straight_to_the_store_one_file_at_a_time_and_lands_at_the_same_keys(): void
    {
        $bytes = $this->dicomBytes();
        [$uploadId, $opened] = $this->openDicom();
        $this->assertSame(1_073_741_824, $opened['limits']['max_file_bytes'], 'no request size applies to a PUT to the store');

        $authorization = $this->authorizeDicom($uploadId, 'CT/ST1/IM0001', strlen($bytes))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('authorization');
        $this->assertSame(0, PhrDicomFile::query()->where('upload_id', $uploadId)->count(), 'nothing is recorded until the bytes are there');
        $this->assertSame(200, $this->putToStore($authorization, $bytes)['status']);

        $confirmed = $this->confirmDicom($uploadId, $authorization['token'])->assertOk();
        $confirmed->assertJsonPath('result.stored', true)->assertJsonPath('result.relative_path', 'CT/ST1/IM0001');

        $this->actingAs($this->owner)->postJson("{$this->dicomBase()}/uploads/{$uploadId}/finalize")->assertOk()->assertJsonPath('upload.status', PhrDicomUpload::STATUS_PROCESSED);

        $upload = PhrDicomUpload::query()->findOrFail($uploadId);
        $file = PhrDicomFile::query()->where('upload_id', $uploadId)->sole();
        $this->assertSame($upload->r2_prefix.'/CT/ST1/IM0001', $file->r2_key, 'the key a POSTed file gets');
        $this->assertSame(hash('sha256', $bytes), $file->sha256);
        $this->assertSame(strlen($bytes), (int) $file->file_size_bytes);
        $this->assertSame([$file->r2_key], Storage::disk('phr_dicom')->allFiles("patients/{$this->patient->id}/imaging/dicom"), 'and nothing else is left for the patient');
        $instance = DB::table('phr_dicom_instances')->where('upload_id', $uploadId)->value('id');
        $this->assertSame($bytes, $this->actingAs($this->owner)->get("{$this->dicomBase()}/instances/{$instance}/file")->assertOk()->streamedContent());
        $this->assertSame(0, app(PhrStorageVerifier::class)->verify(['phr_dicom'])['problem_count']);
    }

    public function test_the_store_refuses_a_dicom_file_of_any_other_length(): void
    {
        [$uploadId] = $this->openDicom();
        $authorization = $this->authorizeDicom($uploadId, 'CT/IM0001', 10)->assertOk()->json('authorization');

        $this->assertSame(403, $this->putToStore($authorization, str_repeat('x', 9))['status']);
        $this->assertSame(403, $this->putToStore($authorization, str_repeat('x', 11))['status']);
        $this->confirmDicom($uploadId, $authorization['token'])->assertStatus(422);
    }

    public function test_a_late_put_cannot_reach_a_stored_image(): void
    {
        $bytes = $this->dicomBytes();
        [$uploadId] = $this->openDicom();
        $authorization = $this->authorizeDicom($uploadId, 'CT/IM0001', strlen($bytes))->assertOk()->json('authorization');
        $this->putToStore($authorization, $bytes);
        $this->confirmDicom($uploadId, $authorization['token'])->assertOk();
        $file = PhrDicomFile::query()->where('upload_id', $uploadId)->sole();

        // The URL stays usable until it expires. It reaches staging, never the durable key.
        $late = strrev($bytes);
        $this->assertSame(200, $this->putToStore($authorization, $late)['status']);
        $this->assertSame($bytes, Storage::disk('phr_dicom')->get($file->r2_key));

        $this->actingAs($this->owner)->postJson("{$this->dicomBase()}/uploads/{$uploadId}/finalize")->assertOk();
        $this->artisan('phr:dicom:gc')->assertSuccessful();

        $this->assertSame([$file->r2_key], Storage::disk('phr_dicom')->allFiles("patients/{$this->patient->id}/imaging/dicom"), 'and the collector reaps what it left');
    }

    /** Every way a client might write a path at another file's staging key. */
    private function pathsAimedAtStaging(string $stagingKey, string $prefix): array
    {
        $relative = str_starts_with($stagingKey, $prefix.'/') ? substr($stagingKey, strlen($prefix) + 1) : '../../incoming/'.basename(dirname($stagingKey)).'/'.basename($stagingKey);
        $name = basename($stagingKey);

        return [
            'the staging path as given' => $relative,
            'the staging directory, by the name it had' => '.incoming/'.$name,
            'another case' => '.INCOMING/'.strtoupper($name),
            'dot segments' => '../../incoming/'.basename(dirname($stagingKey)).'/'.$name,
            'dot segments inside' => 'x/../.incoming/./'.$name,
            'backslashes' => '..\\..\\incoming\\'.basename(dirname($stagingKey)).'\\'.$name,
            'percent-encoded dots and slashes' => '%2e%2e%2f%2e%2e%2fincoming%2f'.basename(dirname($stagingKey)).'%2f'.$name,
            'double-encoded' => '%252e%252e/%252e%252e/incoming/'.$name,
            'the whole key' => $stagingKey,
            'the whole key, rooted' => '/'.$stagingKey,
            'a trailing dot' => '.incoming./'.$name,
            'a null byte' => ".inco\0ming/".$name,
        ];
    }

    private function assertLateStagingPutCannotReach(string $key, string $bytes, array $authorization): void
    {
        $upload = PhrDicomUpload::query()->whereHas('files', static fn ($query) => $query->where('r2_key', $key))->firstOrFail();
        $this->assertStringStartsWith($upload->r2_prefix.'/', $key);
        $this->assertSame(200, $this->putToStore($authorization, strrev($bytes))['status'], 'the signed URL is still usable');
        $this->assertSame($bytes, Storage::disk('phr_dicom')->get($key), "A signed PUT reached a recorded image at {$key}.");
    }

    public function test_no_path_a_browser_gives_a_direct_file_reaches_a_staging_key(): void
    {
        [$uploadId] = $this->openDicom();
        $prefix = (string) PhrDicomUpload::query()->findOrFail($uploadId)->r2_prefix;
        $aimed = $this->authorizeDicom($uploadId, 'CT/IM0000', 100)->assertOk()->json('authorization');
        $stagingKey = (string) json_decode(Crypt::decryptString($aimed['token']), true)['key'];
        $this->assertStringStartsNotWith($prefix.'/', $stagingKey, 'staging is outside everything a relative path can name');

        $sop = 10;
        foreach ($this->pathsAimedAtStaging($stagingKey, $prefix) as $case => $path) {
            $sop++;
            $bytes = $this->dicomBytes((string) $sop);
            $authorization = $this->authorizeDicom($uploadId, $path, strlen($bytes))->assertOk()->json('authorization');
            $this->assertSame(200, $this->putToStore($authorization, $bytes)['status'], $case);
            $this->confirmDicom($uploadId, $authorization['token'])->assertOk()->assertJsonPath('result.stored', true);
            $key = (string) PhrDicomFile::query()->where('upload_id', $uploadId)->latest('id')->value('r2_key');

            $this->assertNotSame($stagingKey, $key, $case);
            $this->assertLateStagingPutCannotReach($key, $bytes, $authorization);
        }
    }

    public function test_the_attack_from_the_review_cannot_replace_a_recorded_image(): void
    {
        [$uploadId] = $this->openDicom();
        $padded = str_pad('', strlen($this->dicomBytes('1')), 'x');
        $victim = $this->authorizeDicom($uploadId, 'CT/IM0000', strlen($padded))->assertOk()->json('authorization');
        $stagingKey = (string) json_decode(Crypt::decryptString($victim['token']), true)['key'];

        $bytes = $this->dicomBytes('1');
        $recorded = $this->authorizeDicom($uploadId, '.incoming/'.basename($stagingKey), strlen($bytes))->assertOk()->json('authorization');
        $this->putToStore($recorded, $bytes);
        $this->confirmDicom($uploadId, $recorded['token'])->assertOk()->assertJsonPath('result.stored', true);
        $key = (string) PhrDicomFile::query()->where('upload_id', $uploadId)->value('r2_key');

        $this->assertSame(200, $this->putToStore($victim, $padded)['status']);

        $this->assertSame($bytes, Storage::disk('phr_dicom')->get($key), 'The first URL overwrote an image recorded with another hash.');
        $this->assertNotSame($stagingKey, $key);
    }

    public function test_a_posted_file_cannot_reach_a_staging_key_either(): void
    {
        [$uploadId] = $this->openDicom();
        $prefix = (string) PhrDicomUpload::query()->findOrFail($uploadId)->r2_prefix;
        $aimed = $this->authorizeDicom($uploadId, 'CT/IM0000', 100)->assertOk()->json('authorization');
        $stagingKey = (string) json_decode(Crypt::decryptString($aimed['token']), true)['key'];

        $sop = 40;
        foreach ($this->pathsAimedAtStaging($stagingKey, $prefix) as $case => $path) {
            $sop++;
            $bytes = $this->dicomBytes((string) $sop);
            $this->actingAs($this->owner)->withHeader('Accept', 'application/json')->post("{$this->dicomBase()}/uploads/{$uploadId}/files", [
                'file' => UploadedFile::fake()->createWithContent('IM0001', $bytes),
                'relative_path' => $path,
            ])->assertOk()->assertJsonPath('result.stored', true);
            $key = (string) PhrDicomFile::query()->where('upload_id', $uploadId)->latest('id')->value('r2_key');

            $this->assertNotSame($stagingKey, $key, $case);
            $this->assertStringStartsWith($prefix.'/', $key, $case);
        }
    }

    public function test_an_agents_file_cannot_reach_a_staging_key_either(): void
    {
        $this->configurePassportKeys();
        [$uploadId] = $this->openDicom();
        $prefix = (string) PhrDicomUpload::query()->findOrFail($uploadId)->r2_prefix;
        $aimed = $this->authorizeDicom($uploadId, 'CT/IM0000', 100)->assertOk()->json('authorization');
        $stagingKey = (string) json_decode(Crypt::decryptString($aimed['token']), true)['key'];
        $client = Client::query()->create(['name' => 'Synthetic Agent', 'secret' => null, 'provider' => 'users', 'redirect_uris' => ['https://agent.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false]);
        Passport::actingAs($this->owner, [AgentApiScopes::CLINICAL_WRITE, AgentApiScopes::CLINICAL_READ, AgentApiScopes::DOCUMENTS_WRITE, AgentApiScopes::DOCUMENTS_READ], 'api', $client);

        $sop = 70;
        foreach ($this->pathsAimedAtStaging($stagingKey, $prefix) as $case => $path) {
            $sop++;
            $this->withHeader('Accept', 'application/json')->post(route('agent-api.v1.dicom-uploads.files.store', ['patient' => $this->patient->id, 'upload' => $uploadId]), [
                'file' => UploadedFile::fake()->createWithContent('IM0001', $this->dicomBytes((string) $sop)),
                'relative_path' => $path,
            ])->assertOk()->assertJsonPath('result.stored', true);
            $key = (string) PhrDicomFile::query()->where('upload_id', $uploadId)->latest('id')->value('r2_key');

            $this->assertNotSame($stagingKey, $key, $case);
            $this->assertStringStartsWith($prefix.'/', $key, $case);
        }
    }

    public function test_a_put_during_the_read_cannot_make_the_record_describe_other_bytes(): void
    {
        [$uploadId] = $this->openDicom();
        $original = $this->dicomBytes('1');
        // The same length, another image: its SOP instance ends in a different digit.
        $replacement = $this->dicomBytes('2');
        $this->assertSame(strlen($original), strlen($replacement));
        $authorization = $this->authorizeDicom($uploadId, 'CT/IM0001', strlen($original))->assertOk()->json('authorization');
        $this->assertSame(200, $this->putToStore($authorization, $original)['status']);

        // Replaces what is about to be read, through the URL that is still valid.
        $this->beforeNextRead('phr_dicom', function () use ($authorization, $replacement): void {
            $this->assertSame(200, $this->putToStore($authorization, $replacement)['status']);
        });
        $this->confirmDicom($uploadId, $authorization['token'])->assertOk()->assertJsonPath('result.stored', true);

        $file = PhrDicomFile::query()->where('upload_id', $uploadId)->sole();
        $stored = (string) Storage::disk('phr_dicom')->get($file->r2_key);
        $this->assertSame(hash('sha256', $stored), $file->sha256, 'The recorded hash describes bytes other than the stored image.');
        $this->assertSame($original, $stored, 'What was there when the file was confirmed is what is kept.');
        $instance = DB::table('phr_dicom_instances')->where('upload_id', $uploadId)->sole();
        $this->assertStringEndsWith('.1', (string) $instance->sop_instance_uid, 'The recorded metadata describes the stored image.');
    }

    public function test_a_file_that_never_arrived_is_not_recorded_and_can_be_sent_again(): void
    {
        $bytes = $this->dicomBytes();
        [$uploadId] = $this->openDicom();
        $authorization = $this->authorizeDicom($uploadId, 'CT/IM0001', strlen($bytes))->assertOk()->json('authorization');

        $this->confirmDicom($uploadId, $authorization['token'])->assertStatus(422);
        $this->assertSame(0, PhrDicomFile::query()->where('upload_id', $uploadId)->count());

        $this->putToStore($authorization, $bytes);
        $this->confirmDicom($uploadId, $authorization['token'])->assertOk()->assertJsonPath('result.stored', true);
    }

    public function test_confirming_one_file_twice_stores_it_once(): void
    {
        $bytes = $this->dicomBytes();
        [$uploadId] = $this->openDicom();
        $authorization = $this->authorizeDicom($uploadId, 'CT/IM0001', strlen($bytes))->assertOk()->json('authorization');
        $this->putToStore($authorization, $bytes);

        $this->confirmDicom($uploadId, $authorization['token'])->assertOk();
        $this->confirmDicom($uploadId, $authorization['token'])->assertStatus(422);

        $this->assertSame(1, PhrDicomFile::query()->where('upload_id', $uploadId)->count());
    }

    public function test_a_token_only_works_for_its_own_session(): void
    {
        [$uploadId] = $this->openDicom();
        [$otherId] = $this->openDicom();
        $authorization = $this->authorizeDicom($uploadId, 'CT/IM0001', 10)->assertOk()->json('authorization');

        $this->confirmDicom($otherId, $authorization['token'])->assertStatus(422);
        $this->confirmDicom($uploadId, 'not-a-token')->assertStatus(422);
    }

    public function test_a_file_that_is_not_dicom_is_skipped_and_its_bytes_removed(): void
    {
        [$uploadId] = $this->openDicom();
        $authorization = $this->authorizeDicom($uploadId, 'CT/notes', 13)->assertOk()->json('authorization');
        $this->putToStore($authorization, 'not a dicom!!');

        $this->confirmDicom($uploadId, $authorization['token'])->assertOk()
            ->assertJsonPath('result.stored', false)->assertJsonPath('result.skipped_reason', 'not_dicom');

        $this->assertSame([], Storage::disk('phr_dicom')->allFiles("patients/{$this->patient->id}/imaging/dicom"));
    }

    public function test_a_duplicate_image_is_skipped_and_its_bytes_removed(): void
    {
        $bytes = $this->dicomBytes();
        [$first] = $this->openDicom();
        $authorization = $this->authorizeDicom($first, 'CT/IM0001', strlen($bytes))->assertOk()->json('authorization');
        $this->putToStore($authorization, $bytes);
        $this->confirmDicom($first, $authorization['token'])->assertOk();
        $this->actingAs($this->owner)->postJson("{$this->dicomBase()}/uploads/{$first}/finalize")->assertOk();

        [$second] = $this->openDicom();
        $repeat = $this->authorizeDicom($second, 'CT/IM0001', strlen($bytes))->assertOk()->json('authorization');
        $this->putToStore($repeat, $bytes);

        $this->confirmDicom($second, $repeat['token'])->assertOk()->assertJsonPath('result.skipped_reason', 'duplicate_sop_instance');

        $upload = PhrDicomUpload::query()->findOrFail($second);
        $this->assertSame([], Storage::disk('phr_dicom')->allFiles($upload->r2_prefix));
        $this->assertSame([], Storage::disk('phr_dicom')->allFiles(PhrStorageKey::dicomIncomingPrefix((int) $this->patient->id, $second)));
    }

    public function test_two_files_at_one_path_keep_their_own_keys(): void
    {
        [$uploadId] = $this->openDicom();
        foreach (['1', '2'] as $sop) {
            $bytes = $this->dicomBytes($sop);
            $authorization = $this->authorizeDicom($uploadId, 'CT/IM0001', strlen($bytes))->assertOk()->json('authorization');
            $this->putToStore($authorization, $bytes);
            $this->confirmDicom($uploadId, $authorization['token'])->assertOk()->assertJsonPath('result.stored', true);
        }

        $keys = PhrDicomFile::query()->where('upload_id', $uploadId)->orderBy('id')->pluck('r2_key')->all();
        $this->assertCount(2, array_unique($keys));
    }

    public function test_a_file_the_server_would_skip_is_never_signed(): void
    {
        [$uploadId] = $this->openDicom();

        $this->authorizeDicom($uploadId, 'CT/VIEWER/SETUP.EXE', 100, 'SETUP.EXE')->assertOk()
            ->assertJsonMissingPath('authorization')->assertJsonPath('result.skipped_reason', 'auxiliary_file');
        $this->authorizeDicom($uploadId, 'CT/EMPTY', 0, 'EMPTY')->assertOk()
            ->assertJsonMissingPath('authorization')->assertJsonPath('result.skipped_reason', 'not_dicom');

        $this->assertSame(2, PhrDicomUpload::query()->findOrFail($uploadId)->skipped_files);
    }

    public function test_a_file_over_what_one_put_can_hold_is_refused(): void
    {
        config(['phr.dicom_max_file_bytes' => 6 * 1024 * self::MIB]);
        [$uploadId, $opened] = $this->openDicom();

        $this->assertSame(5 * 1024 * self::MIB, $opened['limits']['max_file_bytes']);
        $this->authorizeDicom($uploadId, 'CT/IM0001', 5 * 1024 * self::MIB + 1)->assertStatus(422)->assertJsonValidationErrors('byte_size');
    }

    public function test_a_part_number_past_the_plan_cannot_be_slipped_into_finishing(): void
    {
        $bytes = (string) file_get_contents($this->myChartZip(1024));
        $upload = $this->startEhi(strlen($bytes));
        $parts = $this->sendAllParts($upload['id'], $bytes, 5 * self::MIB);
        $parts[] = ['part_number' => 2, 'etag' => '"synthetic-extra"'];

        $this->actingAs($this->owner)->postJson("{$this->ehiBase()}/{$upload['id']}/intake", ['parts' => $parts])->assertStatus(202);
        $this->assertSame(strlen($bytes), Storage::disk('phr_documents')->size((string) PhrEhiUpload::query()->findOrFail($upload['id'])->storage_path));
    }

    public function test_size_and_quota_are_checked_before_signing(): void
    {
        [$uploadId] = $this->openDicom();
        config(['phr.dicom_max_file_bytes' => 100]);
        $this->authorizeDicom($uploadId, 'CT/IM0001', 101)->assertStatus(422)->assertJsonValidationErrors('byte_size');

        DB::table('phr_storage_settings')->insert(['id' => 1, 'global_limit_bytes' => 5]);
        Cache::flush();
        $this->authorizeDicom($uploadId, 'CT/IM0001', 6)->assertStatus(422)->assertJsonValidationErrors('storage');
    }

    public function test_a_closed_session_signs_nothing_and_cancel_removes_what_arrived(): void
    {
        $bytes = $this->dicomBytes();
        [$uploadId] = $this->openDicom();
        $authorization = $this->authorizeDicom($uploadId, 'CT/IM0001', strlen($bytes))->assertOk()->json('authorization');
        $this->putToStore($authorization, $bytes);
        $this->assertCount(1, Storage::disk('phr_dicom')->allFiles("patients/{$this->patient->id}/imaging/dicom"));

        $this->actingAs($this->owner)->postJson("{$this->dicomBase()}/uploads/{$uploadId}/cancel")->assertOk();

        $this->assertSame([], Storage::disk('phr_dicom')->allFiles("patients/{$this->patient->id}/imaging/dicom"));
        $this->authorizeDicom($uploadId, 'CT/IM0002', 10)->assertStatus(409);
    }

    public function test_the_collector_keeps_bytes_a_pending_upload_is_about_to_record(): void
    {
        $bytes = $this->dicomBytes();
        [$uploadId] = $this->openDicom();
        $authorization = $this->authorizeDicom($uploadId, 'CT/IM0001', strlen($bytes))->assertOk()->json('authorization');
        $this->putToStore($authorization, $bytes);

        $this->artisan('phr:dicom:gc')->assertSuccessful();

        $this->confirmDicom($uploadId, $authorization['token'])->assertOk()->assertJsonPath('result.stored', true);
    }

    public function test_a_post_still_works_on_an_object_store_for_a_tab_opened_before_a_deploy_and_for_agents(): void
    {
        [$uploadId] = $this->openDicom();

        $this->actingAs($this->owner)->withHeader('Accept', 'application/json')->post("{$this->dicomBase()}/uploads/{$uploadId}/files", [
            'file' => UploadedFile::fake()->createWithContent('IM0001', $this->dicomBytes()),
            'relative_path' => 'CT/IM0001',
        ])->assertOk()->assertJsonPath('result.stored', true);
    }

    /** A minimal instance PHR's own parser accepts; see PhrDicomTest. */
    private function dicomBytes(string $sop = '1'): string
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
            .$element(0x0008, 0x0018, 'UI', '1.2.840.113619.2.55.3.604688437.20260517.1.1.'.$sop)
            .$element(0x0008, 0x0020, 'DA', '20260517')
            .$element(0x0008, 0x0030, 'TM', '101112')
            .$element(0x0008, 0x0060, 'CS', 'CT')
            .$element(0x0008, 0x1030, 'LO', 'Cardiac CT')
            .$element(0x0010, 0x0010, 'PN', 'Primary^Patient')
            .$element(0x0020, 0x000D, 'UI', '1.2.840.113619.2.55.3.604688437.20260517.1')
            .$element(0x0020, 0x000E, 'UI', '1.2.840.113619.2.55.3.604688437.20260517.1.1')
            .$element(0x0020, 0x0011, 'IS', '1')
            .$element(0x0020, 0x0013, 'IS', $sop)
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

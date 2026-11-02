<?php

namespace Tests\Feature\Storage;

use App\DataTransferObjects\PHR\DocumentUploadData;
use App\Jobs\PHR\IntakeEhiUploadJob;
use App\Models\PhrDicomFile;
use App\Models\PhrDicomUpload;
use App\Models\PhrDocument;
use App\Models\PhrEhiBlob;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiUpload;
use App\Models\PhrEob;
use App\Models\PhrExport;
use App\Models\PhrNativeBackup;
use App\Models\PhrNativeRestoreAttempt;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\DataHub\PhrPatientDeletionCleanupService;
use App\Services\PHR\DataHub\PhrPatientDeletionService;
use App\Services\PHR\DICOM\DicomUploadProcessor;
use App\Services\PHR\Documents\PhrDocumentUploadService;
use App\Services\PHR\Documents\SignedDocumentUploadService;
use App\Services\PHR\Export\PhrExportService;
use App\Services\PHR\Import\Ehi\EhiUploadService;
use App\Services\PHR\Import\EobDocumentImportService;
use App\Services\PHR\Import\PhrDocumentImporter;
use App\Services\PHR\Import\PhrDocumentProcessingService;
use App\Services\PHR\Import\PhrStructuredDocumentImporter;
use App\Services\PHR\NativeBackup\PhrNativeBackupService;
use App\Services\PHR\NativeBackup\PhrNativeRestoreService;
use App\Support\Storage\LocalObjectFile;
use App\Support\Storage\StagedUpload;
use App\Support\Storage\StoragePruner;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiIntakeFixtures;
use Tests\Support\LiveObjectStore;
use Tests\Support\NoLocalPathDisk;
use Tests\Support\UsesObjectStoreDisks;
use Tests\TestCase;
use ZipArchive;

/**
 * Every path a PHR file takes, on an object store. See UsesObjectStoreDisks: these run on a
 * disk that fails on ->path() by default, and against a real server with PHR_TEST_S3_ENDPOINT.
 */
class PhrFilesOnObjectStoreTest extends TestCase
{
    use EhiIntakeFixtures;
    use RefreshDatabase;
    use UsesObjectStoreDisks;

    private User $owner;

    private PhrPatient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
        Cache::flush();
        $this->useObjectStoreDisks();
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

    private function documents(): FilesystemAdapter
    {
        $disk = Storage::disk(PhrDocument::STORAGE_DISK);
        assert($disk instanceof FilesystemAdapter);

        return $disk;
    }

    private function storedDocument(string $name, string $type, string $bytes): PhrDocument
    {
        $file = tempnam($this->ehiRoot, 'document-');
        file_put_contents((string) $file, $bytes);

        return app(PhrDocumentUploadService::class)->upload(
            $this->patient,
            (int) $this->owner->id,
            DocumentUploadData::fromValidated(new UploadedFile((string) $file, $name, $type, null, true), ['title' => $name, 'document_type' => 'other'], source: 'manual_upload'),
        )->document;
    }

    private function ehiZip(): string
    {
        return EhiClinicalPackage::standard()->build()->writeZip($this->ehiRoot.'/'.bin2hex(random_bytes(4)).'.zip');
    }

    private function myChartZip(): string
    {
        $path = $this->ehiRoot.'/'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('Records/bundle.json', (string) json_encode(['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            ['resource' => ['resourceType' => 'Condition', 'id' => 'condition-1', 'code' => ['text' => 'Synthetic condition']]],
        ]]));
        $zip->close();

        return $path;
    }

    private function chunkedUpload(string $zip, int $chunks = 3, bool $queue = true): PhrEhiUpload
    {
        $bytes = (string) file_get_contents($zip);
        $base = "/api/phr/patients/{$this->patient->id}/ehi";
        $id = $this->actingAs($this->owner)->postJson("{$base}/uploads", ['file_size_bytes' => strlen($bytes)])->assertCreated()->json('upload.id');
        $size = (int) ceil(strlen($bytes) / $chunks);
        for ($offset = 0; $offset < strlen($bytes); $offset += $size) {
            $this->actingAs($this->owner)->withHeader('Accept', 'application/json')->post("{$base}/uploads/{$id}/chunks", [
                'chunk' => UploadedFile::fake()->createWithContent('chunk', substr($bytes, $offset, $size)),
                'offset' => $offset,
            ])->assertOk();
        }
        if ($queue) {
            $this->actingAs($this->owner)->postJson("{$base}/uploads/{$id}/intake")->assertStatus(202);
        }

        return PhrEhiUpload::query()->findOrFail($id);
    }

    private function runIntake(PhrEhiUpload $upload): void
    {
        (new IntakeEhiUploadJob($upload->id))->handle(app(EhiUploadService::class));
    }

    private function noLocalPathDisk(string $disk): NoLocalPathDisk
    {
        if (LiveObjectStore::configured()) {
            $this->markTestSkipped('Injects a storage failure, which only the default disk can do.');
        }
        $filesystem = Storage::disk($disk);
        assert($filesystem instanceof NoLocalPathDisk);

        return $filesystem;
    }

    public function test_the_disks_here_really_are_not_local(): void
    {
        foreach (array_keys(self::OBJECT_STORE_DISKS) as $disk) {
            $this->assertFalse(LocalObjectFile::isLocal(Storage::disk($disk)), $disk);
        }
        if (LiveObjectStore::configured()) {
            $this->assertStringNotContainsString(DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR, $this->documents()->path('x'));

            return;
        }
        $this->expectException(LogicException::class);
        $this->documents()->path('x');
    }

    public function test_a_document_is_stored_and_read_back_through_the_browser_route(): void
    {
        $document = $this->storedDocument('note.txt', 'text/plain', 'Synthetic note.');

        $this->assertSame('Synthetic note.', $this->documents()->get($document->storage_path));
        $response = $this->actingAs($this->owner)->get("/api/phr/patients/{$this->patient->id}/documents/{$document->id}/file")->assertOk();
        $this->assertSame('Synthetic note.', $response->streamedContent());
    }

    public function test_a_local_transport_signed_upload_finalizes_without_a_local_path(): void
    {
        $bytes = 'Synthetic signed upload.';
        $service = app(SignedDocumentUploadService::class);
        config(['filesystems.disks.phr_uploads.driver' => 'local']);
        $upload = $service->open($this->patient, (int) $this->owner->id, 'synthetic', ['kind' => 'other', 'filename' => 'note.txt', 'content_type' => 'text/plain', 'byte_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        $this->assertSame('local', $upload->transport);
        $this->assertSame(PhrDocument::STORAGE_DISK, $upload->storage_disk);
        $service->receive($upload->public_id, Request::create('/', 'PUT', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => (string) strlen($bytes)], $bytes));

        $result = $service->finalize($this->patient, 'synthetic', $upload->public_id);

        $this->assertSame($bytes, $this->documents()->get($result['document']->storage_path));
        $this->assertFalse($this->documents()->exists((string) $upload->refresh()->staging_path ?: 'none'));
    }

    public function test_a_stored_claim_csv_is_imported_from_the_object_store(): void
    {
        $csv = <<<'CSV'
Claim Number,Date of Service,Submission Date,Member Name,Dentist,Phone,Procedure,Procedure Code,Accepted Fee,Claim Deductible,Delta Dental Pays,You Pay
20300102000001,2030-01-02,2030-01-03,Synthetic Patient,EXAMPLE DENTIST,(555) 010-0200,Periodic oral evaluation established patient,D0120,100.00,0.00,80.00,20.00
CSV;
        $document = $this->storedDocument('claims.txt', 'text/plain', $csv);

        $result = app(EobDocumentImportService::class)->import($this->patient, $document);

        $this->assertSame(EobDocumentImportService::CREATED, $result['outcome']);
        $this->assertSame(1, PhrEob::query()->where('patient_id', $this->patient->id)->count());
    }

    public function test_a_stored_pdf_with_no_text_layer_reaches_the_parser_and_is_reported_unrecognised(): void
    {
        $document = $this->storedDocument('scan.pdf', 'text/plain', '%PDF-1.4 synthetic');

        $this->assertSame(EobDocumentImportService::UNRECOGNISED, app(EobDocumentImportService::class)->import($this->patient, $document)['outcome']);
    }

    public function test_a_stored_ehi_export_is_staged_and_taken_in_through_a_local_temporary_copy(): void
    {
        Queue::fake();
        $document = $this->storedDocument('ehi.zip', 'application/zip', (string) file_get_contents($this->ehiZip()));

        $import = app(PhrStructuredDocumentImporter::class)->import($this->patient, (int) $this->owner->id, $document);

        $this->assertNotNull($import);
        $upload = PhrEhiUpload::query()->where('source_document_id', $document->id)->sole();
        $staged = (string) $upload->storage_path;
        $this->assertTrue($this->documents()->exists($staged), 'staged on the store, not locally');
        // Its own directory: the system's is shared with other test workers, whose copies come and go.
        $temporaryDirectory = sys_get_temp_dir().'/phr-object-test-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($temporaryDirectory));
        config(['phr.object_temp_dir' => $temporaryDirectory]);

        $this->runIntake($upload);

        $upload->refresh();
        $this->assertSame(PhrEhiUpload::STATUS_COMPLETED, $upload->status, (string) $upload->failure_reason);
        $this->assertSame(PhrEhiUpload::FORMAT_EHI, $upload->format);
        $this->assertFalse($this->documents()->exists($staged), 'the staged archive goes once intake finishes');
        $this->assertTrue($this->documents()->exists($document->storage_path), 'and the document it came from stays');
        $this->assertGreaterThan(0, PhrEhiBlob::query()->where('patient_id', $this->patient->id)->count());
        foreach (PhrEhiBlob::query()->where('patient_id', $this->patient->id)->get() as $blob) {
            $this->assertTrue($this->documents()->exists($blob->storage_path), 'each member lands on the store');
        }
        $this->assertSame([], glob($temporaryDirectory.'/*') ?: [], 'and the temporary copy is gone');
        $this->assertTrue(rmdir($temporaryDirectory));
    }

    public function test_a_stored_mychart_export_is_recognized_and_imported(): void
    {
        Queue::fake();
        $document = $this->storedDocument('mychart.zip', 'application/zip', (string) file_get_contents($this->myChartZip()));

        $this->assertNotNull(app(PhrStructuredDocumentImporter::class)->import($this->patient, (int) $this->owner->id, $document));
        $upload = PhrEhiUpload::query()->where('source_document_id', $document->id)->sole();
        $this->runIntake($upload);

        $upload->refresh();
        $this->assertSame(PhrEhiUpload::STATUS_COMPLETED, $upload->status, (string) $upload->failure_reason);
        $this->assertSame(PhrEhiUpload::FORMAT_MYCHART, $upload->format);
    }

    public function test_browser_chunks_wait_in_the_spool_and_become_one_object_before_intake(): void
    {
        Queue::fake();
        $zip = $this->ehiZip();
        $upload = $this->chunkedUpload($zip);
        $staged = (string) $upload->storage_path;

        $spool = Storage::disk(StagedUpload::SPOOL_DISK);
        $this->assertSame(filesize($zip), $spool->size($staged), 'all the chunks are in the spool');
        $this->assertFalse($this->documents()->exists($staged), 'and nothing on the store yet');

        $written = null;
        if (! LiveObjectStore::configured()) {
            $this->noLocalPathDisk(PhrDocument::STORAGE_DISK)->objectStore()->afterWrite = function (string $path) use (&$written, $staged, $zip): void {
                if ($path === $staged) {
                    $written = $this->documents()->size($staged) === filesize($zip);
                }
            };
        }
        $this->runIntake($upload);

        $upload->refresh();
        $this->assertSame(PhrEhiUpload::STATUS_COMPLETED, $upload->status, (string) $upload->failure_reason);
        $this->assertSame(PhrEhiUpload::FORMAT_EHI, $upload->format);
        $this->assertFalse($spool->exists($staged), 'the spool goes once published');
        $this->assertFalse($this->documents()->exists($staged), 'the archive once taken in');
        if (! LiveObjectStore::configured()) {
            $this->assertTrue($written, 'the object was written whole');
        }
    }

    public function test_a_chunk_that_does_not_continue_the_spool_is_refused(): void
    {
        Queue::fake();
        $upload = $this->chunkedUpload($this->ehiZip(), queue: false);
        $spool = Storage::disk(StagedUpload::SPOOL_DISK);
        $spool->delete((string) $upload->storage_path);

        $this->actingAs($this->owner)->withHeader('Accept', 'application/json')->post("/api/phr/patients/{$this->patient->id}/ehi/uploads/{$upload->id}/chunks", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'x'),
            'offset' => $upload->uploaded_bytes,
        ])->assertStatus(409);
    }

    public function test_a_mychart_zip_uploaded_in_chunks_is_imported(): void
    {
        Queue::fake();
        $upload = $this->chunkedUpload($this->myChartZip());

        $this->runIntake($upload);

        $upload->refresh();
        $this->assertSame(PhrEhiUpload::STATUS_COMPLETED, $upload->status, (string) $upload->failure_reason);
        $this->assertSame(PhrEhiUpload::FORMAT_MYCHART, $upload->format);
    }

    public function test_a_failed_publish_keeps_the_spool_so_intake_can_be_queued_again(): void
    {
        Queue::fake();
        $disk = $this->noLocalPathDisk(PhrDocument::STORAGE_DISK);
        $upload = $this->chunkedUpload($this->ehiZip());
        $staged = (string) $upload->storage_path;

        $disk->objectStore()->readOnly = true;
        $this->runIntake($upload);

        $upload->refresh();
        $this->assertSame(PhrEhiUpload::STATUS_FAILED, $upload->status);
        $this->assertSame('storage_failed', $upload->failure_reason);
        $this->assertTrue(Storage::disk(StagedUpload::SPOOL_DISK)->exists($staged));

        $disk->objectStore()->readOnly = false;
        $this->actingAs($this->owner)->postJson("/api/phr/patients/{$this->patient->id}/ehi/uploads/{$upload->id}/intake")->assertStatus(202);
        $this->runIntake($upload->refresh());

        $this->assertSame(PhrEhiUpload::STATUS_COMPLETED, $upload->refresh()->status, (string) $upload->failure_reason);
    }

    public function test_an_upload_let_go_of_while_it_was_publishing_leaves_no_object_behind(): void
    {
        Queue::fake();
        $disk = $this->noLocalPathDisk(PhrDocument::STORAGE_DISK);
        $upload = $this->chunkedUpload($this->ehiZip());
        $staged = (string) $upload->storage_path;
        $disk->objectStore()->afterWrite = function (string $path) use ($upload, $staged): void {
            if ($path === $staged) {
                PhrEhiUpload::query()->whereKey($upload->id)->update(['storage_path' => null]);
            }
        };

        $this->runIntake($upload);

        $this->assertFalse($disk->exists($staged));
        $this->assertSame(0, PhrEhiPackage::query()->count());
    }

    public function test_a_patient_deleted_while_an_upload_was_publishing_leaves_no_object_behind(): void
    {
        Queue::fake();
        $disk = $this->noLocalPathDisk(PhrDocument::STORAGE_DISK);
        $upload = $this->chunkedUpload($this->ehiZip());
        $staged = (string) $upload->storage_path;
        $disk->objectStore()->afterWrite = function (string $path) use ($staged): void {
            if ($path === $staged) {
                DB::table('phr_patients')->where('id', $this->patient->id)->delete();
            }
        };

        $this->runIntake($upload);

        $this->assertFalse($disk->exists($staged));
    }

    public function test_an_export_is_generated_downloaded_and_carries_the_stored_document(): void
    {
        $document = $this->storedDocument('note.txt', 'text/plain', 'Synthetic export source.');
        $service = app(PhrExportService::class);
        $export = $service->generate($service->createQueuedExport($this->patient, (int) $this->owner->id, ['zip']));
        $this->assertSame(PhrExport::STATUS_READY, $export->status);
        $this->assertTrue(Storage::disk('phr_exports')->exists($export->storage_path));

        $response = $this->actingAs($this->owner)->get(URL::temporarySignedRoute('phr.exports.download', now()->addMinutes(15), ['export' => $export->id]))->assertOk();
        $path = tempnam($this->ehiRoot, 'export-');
        file_put_contents((string) $path, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open((string) $path));
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $names[] = (string) $zip->getNameIndex($index);
        }
        $matches = array_filter($names, static fn (string $name): bool => str_ends_with($name, 'note.txt'));
        $this->assertCount(1, $matches, $document->storage_path);
        $this->assertSame('Synthetic export source.', $zip->getFromName(array_values($matches)[0]));
        $zip->close();
    }

    public function test_a_native_backup_round_trips_through_chunks_preview_and_restore(): void
    {
        $this->storedDocument('note.txt', 'text/plain', 'Synthetic backup source.');
        $backups = app(PhrNativeBackupService::class);
        $backup = $backups->generate($backups->createQueuedBackup($this->patient, (int) $this->owner->id));
        $this->assertSame(PhrNativeBackup::STATUS_READY, $backup->status);
        $archive = (string) Storage::disk('phr_exports')->get($backup->storage_path);
        $this->assertSame($backup->file_size_bytes, strlen($archive));
        $download = $this->actingAs($this->owner)->get(URL::temporarySignedRoute('phr.native-backups.download', now()->addMinutes(15), ['backup' => $backup->id]))->assertOk();
        $this->assertSame($archive, $download->streamedContent());

        Queue::fake();
        $restores = app(PhrNativeRestoreService::class);
        $attempt = $restores->startUpload((int) $this->owner->id, strlen($archive), false);
        $source = (string) $attempt->source_storage_path;
        $spool = Storage::disk(StagedUpload::SPOOL_DISK);
        $this->assertTrue($spool->exists($source));
        $size = (int) ceil(strlen($archive) / 2);
        for ($offset = 0; $offset < strlen($archive); $offset += $size) {
            $attempt = $restores->appendChunk($attempt, (int) $this->owner->id, UploadedFile::fake()->createWithContent('chunk', substr($archive, $offset, $size)), $offset);
        }
        $this->assertFalse(Storage::disk('phr_exports')->exists($source));

        $attempt = $restores->preview($restores->queuePreview($attempt, (int) $this->owner->id));

        $this->assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $attempt->status, (string) $attempt->failure_category);
        $this->assertFalse($spool->exists($source), 'published');
        $this->assertTrue(Storage::disk('phr_exports')->exists($source));

        $attempt = $restores->apply($restores->queue($attempt, (int) $this->owner->id, (string) $attempt->plan_digest, false));

        $this->assertSame(PhrNativeRestoreAttempt::STATUS_COMPLETED, $attempt->status, (string) $attempt->failure_category);
        $this->assertNull($attempt->source_storage_path);
        $this->assertFalse(Storage::disk('phr_exports')->exists($source), 'the source goes once restored');
    }

    public function test_quota_counts_stored_size_and_refuses_on_an_object_store(): void
    {
        $document = $this->storedDocument('note.txt', 'text/plain', 'Synthetic quota source.');
        DB::table('phr_storage_settings')->insert(['id' => 1, 'global_limit_bytes' => $document->byte_size + 1]);
        Cache::flush();
        $base = "/api/phr/patients/{$this->patient->id}/ehi/uploads";

        $this->actingAs($this->owner)->postJson($base, ['file_size_bytes' => 2])->assertStatus(422)->assertJsonValidationErrors('storage');
        $this->actingAs($this->owner)->postJson($base, ['file_size_bytes' => 1])->assertCreated();
    }

    public function test_patient_deletion_removes_every_object_including_a_spooled_upload(): void
    {
        Queue::fake();
        $document = $this->storedDocument('note.txt', 'text/plain', 'Synthetic deletion source.');
        $intake = $this->chunkedUpload($this->ehiZip());
        $this->runIntake($intake);
        $blobKey = (string) PhrEhiBlob::query()->where('patient_id', $this->patient->id)->value('storage_path');
        $spooled = $this->chunkedUpload($this->myChartZip(), queue: false);
        $spoolKey = (string) $spooled->storage_path;
        $exports = app(PhrExportService::class);
        $export = $exports->generate($exports->createQueuedExport($this->patient, (int) $this->owner->id, ['zip']));
        $this->assertTrue(Storage::disk(StagedUpload::SPOOL_DISK)->exists($spoolKey));

        $deletions = app(PhrPatientDeletionService::class);
        $plan = $deletions->preview($this->patient);
        $this->assertSame([], $plan->blockers);
        $this->assertContains(['disk' => StagedUpload::SPOOL_DISK, 'key' => $spoolKey], array_map(static fn (array $artifact): array => ['disk' => $artifact['disk'], 'key' => $artifact['key']], $plan->artifacts));
        $deletion = $deletions->delete($this->patient, (int) $this->owner->id, $plan->digest, false);
        app(PhrPatientDeletionCleanupService::class)->cleanup($deletion->id);

        $this->assertFalse($this->documents()->exists($document->storage_path));
        $this->assertFalse($this->documents()->exists($blobKey));
        $this->assertFalse(Storage::disk('phr_exports')->exists($export->storage_path));
        $this->assertFalse(Storage::disk(StagedUpload::SPOOL_DISK)->exists($spoolKey));
        $this->assertSame([], $this->documents()->allFiles("patients/{$this->patient->id}"));
    }

    public function test_the_pruner_quarantines_an_orphan_then_purges_it_and_leaves_referenced_objects_alone(): void
    {
        $document = $this->storedDocument('note.txt', 'text/plain', 'Synthetic pruned source.');
        $orphan = "patients/{$this->patient->id}/documents/orphan/left-behind.bin";
        $this->documents()->put($orphan, 'orphan bytes');
        // The same key on another disk of the same store is that disk's business, and is
        // not referenced either: only this disk's sweep may touch this disk's object.
        $this->travel(2)->days();

        $this->artisan('storage:prune', ['--max-orphan-ratio' => 1])->assertSuccessful();
        $this->assertTrue($this->documents()->exists($orphan), 'a dry run touches nothing');

        $this->artisan('storage:prune', ['--apply' => true, '--max-orphan-ratio' => 1])->assertSuccessful();
        $this->assertFalse($this->documents()->exists($orphan));
        $this->assertTrue($this->documents()->exists($document->storage_path));
        $quarantined = $this->documents()->allFiles(StoragePruner::QUARANTINE_ROOT);
        $this->assertCount(1, $quarantined);
        $this->assertStringEndsWith('/'.$orphan, $quarantined[0]);
        $this->assertSame('orphan bytes', $this->documents()->get($quarantined[0]), 'a mistake can still be undone');

        $this->artisan('storage:prune', ['--apply' => true, '--max-orphan-ratio' => 1])->assertSuccessful();
        $this->assertCount(1, $this->documents()->allFiles(StoragePruner::QUARANTINE_ROOT), 'quarantine is not swept again');

        $this->artisan('storage:prune', ['--apply' => true, '--purge-quarantine' => 30])->assertSuccessful();
        $this->assertCount(1, $this->documents()->allFiles(StoragePruner::QUARANTINE_ROOT), 'the hold has not passed');

        $this->artisan('storage:prune', ['--purge-quarantine' => 1])->assertSuccessful();
        $this->assertCount(1, $this->documents()->allFiles(StoragePruner::QUARANTINE_ROOT), 'a dry-run purge deletes nothing');

        $this->travel(2)->days();
        $this->artisan('storage:prune', ['--apply' => true, '--purge-quarantine' => 1])->assertSuccessful();
        $this->assertSame([], $this->documents()->allFiles(StoragePruner::QUARANTINE_ROOT));
        $this->assertTrue($this->documents()->exists($document->storage_path));
    }

    public function test_each_disk_sees_only_its_own_objects_so_a_sweep_cannot_reach_another(): void
    {
        $key = "patients/{$this->patient->id}/imaging/dicom/uploads/shared-key";
        Storage::disk('phr_documents')->put($key, 'a document that shares a key shape with imaging');
        $this->assertSame([], Storage::disk('phr_dicom')->allFiles('patients'));
        Storage::disk('phr_dicom')->put($key, 'an orphan imaging object');
        $this->travel(2)->days();

        $this->artisan('phr:dicom:gc')->assertSuccessful();

        $this->assertFalse(Storage::disk('phr_dicom')->exists($key), 'the hourly collector reaps its own orphan');
        $this->assertSame('a document that shares a key shape with imaging', Storage::disk('phr_documents')->get($key), 'and not the document');
    }

    public function test_dicom_is_uploaded_read_downloaded_and_reaped_on_an_object_store(): void
    {
        $bytes = $this->dicomBytes();
        $base = "/api/phr/patients/{$this->patient->id}/dicom";
        $uploadId = (int) $this->actingAs($this->owner)->postJson("{$base}/uploads", ['root_name' => 'CT'])->assertCreated()->json('upload.id');
        $this->actingAs($this->owner)->withHeader('Accept', 'application/json')
            ->post("{$base}/uploads/{$uploadId}/files", ['file' => UploadedFile::fake()->createWithContent('IM0001', $bytes), 'relative_path' => 'CT/IM0001'])
            ->assertOk()->assertJsonPath('result.stored', true);
        $finalized = $this->actingAs($this->owner)->postJson("{$base}/uploads/{$uploadId}/finalize");
        $this->assertSame(200, $finalized->status(), (string) $finalized->getContent());

        $file = PhrDicomFile::query()->where('upload_id', $uploadId)->sole();
        $this->assertSame($bytes, Storage::disk('phr_dicom')->get($file->r2_key));

        $instance = DB::table('phr_dicom_instances')->where('upload_id', $uploadId)->value('id');
        $served = $this->actingAs($this->owner)->get("{$base}/instances/{$instance}/file")->assertOk();
        $this->assertSame($bytes, $served->streamedContent());

        $study = DB::table('phr_dicom_studies')->where('patient_id', $this->patient->id)->value('id');
        $download = $this->actingAs($this->owner)->get("{$base}/studies/{$study}/download")->assertOk();
        $this->assertStringStartsWith('PK', $download->streamedContent());

        if (LiveObjectStore::configured()) {
            $signed = app(DicomUploadProcessor::class)->temporaryViewerUrl($file);
            $this->assertSame($bytes, Http::get($signed)->throw()->body(), 'a signed viewer URL reads the same object');
        }

        $this->artisan('phr:dicom:gc')->assertSuccessful();
        $this->assertTrue(Storage::disk('phr_dicom')->exists($file->r2_key), 'a referenced instance survives the collector');
    }

    public function test_a_stale_dicom_upload_is_failed_and_its_objects_removed(): void
    {
        $prefix = "patients/{$this->patient->id}/imaging/dicom/uploads/".Str::uuid();
        $upload = PhrDicomUpload::query()->create(['patient_id' => $this->patient->id, 'uploaded_by_user_id' => $this->owner->id, 'status' => PhrDicomUpload::STATUS_PENDING, 'r2_prefix' => $prefix]);
        Storage::disk('phr_dicom')->put("{$prefix}/IM0001", 'stale');
        DB::table('phr_dicom_uploads')->where('id', $upload->id)->update(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);

        $this->artisan('phr:dicom:gc')->assertSuccessful();

        $this->assertSame(PhrDicomUpload::STATUS_FAILED, $upload->refresh()->status);
        $this->assertSame([], Storage::disk('phr_dicom')->allFiles($prefix));
    }

    public function test_the_expired_export_and_native_backup_purges_delete_the_objects(): void
    {
        $exports = app(PhrExportService::class);
        $export = $exports->generate($exports->createQueuedExport($this->patient, (int) $this->owner->id, ['zip']));
        $backups = app(PhrNativeBackupService::class);
        $backup = $backups->generate($backups->createQueuedBackup($this->patient, (int) $this->owner->id));
        $this->travel(60)->days();

        $this->artisan('phr:exports:purge')->assertSuccessful();
        $this->artisan('phr:native-backups:purge')->assertSuccessful();

        $this->assertFalse(Storage::disk('phr_exports')->exists($export->storage_path));
        $this->assertFalse(Storage::disk('phr_exports')->exists($backup->storage_path));
        $this->assertNull(PhrExport::query()->find($export->id));
    }

    public function test_the_export_purge_keeps_the_row_when_the_object_cannot_be_deleted(): void
    {
        $disk = $this->noLocalPathDisk('phr_exports');
        $exports = app(PhrExportService::class);
        $export = $exports->generate($exports->createQueuedExport($this->patient, (int) $this->owner->id, ['zip']));
        $this->travel(60)->days();

        $disk->objectStore()->readOnly = true;
        $this->artisan('phr:exports:purge')->assertSuccessful();
        $this->assertNotNull(PhrExport::query()->find($export->id), 'the row is the only way back to the object');

        $disk->objectStore()->readOnly = false;
        $this->artisan('phr:exports:purge')->assertSuccessful();
        $this->assertNull(PhrExport::query()->find($export->id));
        $this->assertFalse($disk->exists($export->storage_path));
    }

    public function test_the_ehi_upload_purge_deletes_an_expired_spool_and_an_expired_object(): void
    {
        Queue::fake();
        $spooled = $this->chunkedUpload($this->ehiZip(), queue: false);
        $spoolKey = (string) $spooled->storage_path;
        $document = $this->storedDocument('ehi.zip', 'application/zip', (string) file_get_contents($this->ehiZip()));
        [$staged] = app(EhiUploadService::class)->stageDocument($this->patient, (int) $this->owner->id, $document);
        $stagedKey = (string) $staged->storage_path;
        $this->assertTrue($this->documents()->exists($stagedKey));
        DB::table('phr_ehi_uploads')->update(['expires_at' => now()->subHour(), 'status' => PhrEhiUpload::STATUS_UPLOADING]);

        $this->artisan('phr:ehi-uploads:purge')->assertSuccessful();

        $this->assertFalse(Storage::disk(StagedUpload::SPOOL_DISK)->exists($spoolKey));
        $this->assertFalse($this->documents()->exists($stagedKey));
        $this->assertNull($spooled->refresh()->storage_path);
    }

    public function test_the_native_restore_purge_deletes_an_expired_spool_and_an_expired_object(): void
    {
        Queue::fake();
        $restores = app(PhrNativeRestoreService::class);
        $spooled = $restores->startUpload((int) $this->owner->id, 2, false);
        $published = $restores->startUpload((int) $this->owner->id, 2, false);
        $published = $restores->appendChunk($published, (int) $this->owner->id, UploadedFile::fake()->createWithContent('chunk', 'ab'), 0);
        StagedUpload::publish('phr_exports', (string) $published->source_storage_path, 2);
        $this->assertTrue(Storage::disk('phr_exports')->exists((string) $published->source_storage_path));
        DB::table('phr_native_restore_attempts')->update(['expires_at' => now()->subHour()]);

        $this->artisan('phr:native-restores:purge')->assertSuccessful();

        $this->assertFalse(Storage::disk(StagedUpload::SPOOL_DISK)->exists((string) $spooled->source_storage_path));
        $this->assertFalse(Storage::disk('phr_exports')->exists((string) $published->source_storage_path));
    }

    public function test_the_document_upload_collector_deletes_expired_staged_bytes(): void
    {
        $bytes = 'Synthetic signed upload.';
        $service = app(SignedDocumentUploadService::class);
        config(['filesystems.disks.phr_uploads.driver' => 'local']);
        $upload = $service->open($this->patient, (int) $this->owner->id, 'synthetic', ['kind' => 'other', 'filename' => 'note.txt', 'content_type' => 'text/plain', 'byte_size' => strlen($bytes)]);
        $service->receive($upload->public_id, Request::create('/', 'PUT', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => (string) strlen($bytes)], $bytes));
        $staged = (string) $upload->refresh()->staging_path;
        $this->assertTrue($this->documents()->exists($staged));
        $this->travel(16)->minutes();

        $this->artisan('phr:document-uploads:gc')->assertSuccessful();

        $this->assertFalse($this->documents()->exists($staged));
    }

    public function test_a_large_object_survives_each_way_a_disk_writes_and_copies_it(): void
    {
        // Past the SDK's 16 MB threshold, so a real store takes the multipart paths, and read
        // back through a stream that cannot seek, which is what stream_reads hands the SDK.
        $bytes = substr(str_repeat(hash('sha512', 'synthetic', true), (int) ceil(18 * 1024 * 1024 / 64)), 0, 18 * 1024 * 1024);
        $expected = hash('sha256', $bytes);
        $source = tempnam($this->ehiRoot, 'large-');
        file_put_contents((string) $source, $bytes);
        $key = 'patients/1/documents/large/original.bin';

        $stream = fopen((string) $source, 'rb');
        $this->assertTrue($this->documents()->writeStream($key, $stream));
        fclose($stream);
        $this->assertSame(strlen($bytes), $this->documents()->size($key));

        $this->assertTrue(LocalObjectFile::duplicateWithinDisk(PhrDocument::STORAGE_DISK, $key, 'patients/1/documents/large/copy.bin'), 'a server-side copy');
        $this->assertSame(strlen($bytes), $this->documents()->size('patients/1/documents/large/copy.bin'));

        $read = $this->documents()->readStream($key);
        $this->assertIsResource($read);
        $this->assertTrue(Storage::disk('phr_exports')->writeStream('patients/1/exports/large/crossed.bin', $read), 'disk to disk, from a read stream');
        fclose($read);

        foreach ([[PhrDocument::STORAGE_DISK, $key], [PhrDocument::STORAGE_DISK, 'patients/1/documents/large/copy.bin'], ['phr_exports', 'patients/1/exports/large/crossed.bin']] as [$disk, $object]) {
            LocalObjectFile::with($disk, $object, function (string $path) use ($expected, $object): void {
                $this->assertSame($expected, hash_file('sha256', $path), $object);
            });
        }

        $file = LocalObjectFile::open(PhrDocument::STORAGE_DISK, $key);
        $temporary = $file->path;
        $this->assertFileExists($temporary);
        $file->release();
        $this->assertFileDoesNotExist($temporary, 'release removes the temporary copy');
    }

    public function test_a_document_is_staged_for_genai_and_copied_back_between_disks(): void
    {
        $document = $this->storedDocument('note.txt', 'text/plain', 'Synthetic staging source.');
        Queue::fake();

        $result = app(PhrDocumentProcessingService::class)->create($this->patient, (int) $this->owner->id, (int) $document->id, 'phr_document');

        $job = $result->job;
        $this->assertStringStartsWith('genai-import/', $job->s3_path);
        $this->assertSame('Synthetic staging source.', Storage::disk('s3')->get($job->s3_path));

        $copy = app(PhrDocumentImporter::class)->storeGenAiDocument($this->patient, (int) $this->owner->id, $job, ['title' => 'Copy']);
        $this->assertSame('Synthetic staging source.', $this->documents()->get($copy->storage_path));
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
            .$element(0x0028, 0x0101, 'US', pack('v', 16))
            .$element(0x0028, 0x0102, 'US', pack('v', 15))
            .$element(0x0028, 0x0103, 'US', pack('v', 1));
    }
}

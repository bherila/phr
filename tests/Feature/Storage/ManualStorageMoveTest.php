<?php

namespace Tests\Feature\Storage;

use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Support\Storage\PhrStorageVerifier;
use App\Support\Storage\StoragePruner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Process;
use Tests\Support\LiveObjectStore;
use Tests\Support\UsesObjectStoreDisks;
use Tests\TestCase;

/**
 * The promise of docs/storage.md: copy each disk's directory to the prefix of the same name,
 * or back, change the setting, and every row still finds its file. Nothing here goes
 * through a disk to do the copying, because an operator's copy tool would not either. On a
 * live run with PHR_TEST_RCLONE set, the copies are the documented rclone commands.
 */
class ManualStorageMoveTest extends TestCase
{
    use RefreshDatabase;
    use UsesObjectStoreDisks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useObjectStoreDisks();
    }

    protected function tearDown(): void
    {
        $this->tearDownObjectStoreDisks();
        parent::tearDown();
    }

    private function localDirectory(string $directory): string
    {
        return $this->objectStoreDirectory.'/local/'.$directory;
    }

    private function useLocalDisks(): void
    {
        foreach (self::OBJECT_STORE_DISKS as $disk => $directory) {
            config(["filesystems.disks.{$disk}" => ['driver' => 'local', 'root' => $this->localDirectory($directory), 'throw' => false, 'report' => false]]);
            Storage::forgetDisk($disk);
        }
    }

    /** @return array<string, string> keys relative to the disk root => bytes */
    private function filesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }
        $files = [];
        /** @var SplFileInfo $file */
        foreach (Finder::create()->files()->ignoreDotFiles(false)->in($directory) as $file) {
            $files[$file->getRelativePathname()] = $file->getContents();
        }

        return $files;
    }

    private function bucket(): string
    {
        return (string) getenv('PHR_TEST_S3_BUCKET');
    }

    /** @return array<string, string> */
    private function rcloneEnvironment(): array
    {
        return [
            'RCLONE_CONFIG_STORE_TYPE' => 's3',
            'RCLONE_CONFIG_STORE_PROVIDER' => 'Other',
            'RCLONE_CONFIG_STORE_ACCESS_KEY_ID' => (string) getenv('PHR_TEST_S3_KEY'),
            'RCLONE_CONFIG_STORE_SECRET_ACCESS_KEY' => (string) getenv('PHR_TEST_S3_SECRET'),
            'RCLONE_CONFIG_STORE_ENDPOINT' => (string) getenv('PHR_TEST_S3_ENDPOINT'),
            'RCLONE_CONFIG_STORE_REGION' => (string) getenv('PHR_TEST_S3_REGION') ?: 'garage',
        ];
    }

    private function rclone(string $from, string $to): void
    {
        (new Process([(string) getenv('PHR_TEST_RCLONE'), 'copy', '--checksum', '--s3-no-check-bucket', $from, $to], null, $this->rcloneEnvironment()))->mustRun();
    }

    private function copyStoreToLocal(string $run, string $directory): void
    {
        $target = $this->localDirectory($directory);
        File::ensureDirectoryExists($target);
        if (! LiveObjectStore::configured()) {
            File::copyDirectory($this->objectStoreDirectory.'/'.$run.'/'.$directory, $target);

            return;
        }
        if ((string) getenv('PHR_TEST_RCLONE') !== '') {
            $this->rclone('store:'.$this->bucket().'/'.$run.'/'.$directory, $target);

            return;
        }
        $prefix = $run.'/'.$directory.'/';
        foreach (LiveObjectStore::keys($prefix) as $key) {
            $path = $target.'/'.substr($key, strlen($prefix));
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, (string) LiveObjectStore::client()->getObject(['Bucket' => $this->bucket(), 'Key' => $key])['Body']);
        }
    }

    private function copyLocalToStore(string $run, string $directory): void
    {
        $source = $this->localDirectory($directory);
        if (! LiveObjectStore::configured()) {
            File::copyDirectory($source, $this->objectStoreDirectory.'/'.$run.'/'.$directory);

            return;
        }
        if ((string) getenv('PHR_TEST_RCLONE') !== '') {
            $this->rclone($source, 'store:'.$this->bucket().'/'.$run.'/'.$directory);

            return;
        }
        foreach ($this->filesIn($source) as $key => $bytes) {
            LiveObjectStore::client()->putObject(['Bucket' => $this->bucket(), 'Key' => $run.'/'.$directory.'/'.$key, 'Body' => $bytes]);
        }
    }

    public function test_a_store_is_copied_to_local_and_back_with_no_row_rewritten(): void
    {
        $ownerId = (int) $this->createUser()->id;
        $patient = PhrPatient::query()->create(['owner_user_id' => $ownerId, 'display_name' => 'Synthetic Patient', 'relationship' => 'self']);
        $keys = [
            "patients/{$patient->id}/documents/aaaaaaaa/résumé ünï.txt" => 'First synthetic file, with a non-ASCII name.',
            "patients/{$patient->id}/documents/bbbbbbbb/note.txt" => '',
            "phr/documents/legacy/{$patient->id}/old.txt" => 'A key from before the patient-first layout.',
        ];
        foreach ($keys as $key => $bytes) {
            Storage::disk('phr_documents')->put($key, $bytes);
            PhrDocument::query()->create([
                'patient_id' => $patient->id, 'user_id' => $ownerId, 'uploaded_by_user_id' => $ownerId, 'document_type' => 'other', 'source' => 'manual_upload',
                'storage_disk' => 'phr_documents', 'storage_path' => $key, 'original_filename' => 'synthetic.txt', 'mime_type' => 'text/plain',
                'byte_size' => strlen($bytes), 'file_hash' => hash('sha256', $bytes),
            ]);
        }
        $quarantined = StoragePruner::QUARANTINE_ROOT.'/2030-01-02-030405/patients/1/documents/held/old.txt';
        Storage::disk('phr_documents')->put($quarantined, 'still inside its holding period');
        Storage::disk('phr_exports')->put('patients/1/exports/cccccccc/export.zip', 'an export');

        $rows = DB::table('phr_documents')->orderBy('id')->get()->toArray();
        $reportOnStore = app(PhrStorageVerifier::class)->verify();
        $this->assertSame(0, $reportOnStore['problem_count']);
        $this->assertSame(3, $reportOnStore['disks']['phr_documents']['ok']);

        foreach (self::OBJECT_STORE_DISKS as $directory) {
            $this->copyStoreToLocal($this->objectStoreRun, $directory);
        }
        $this->useLocalDisks();
        foreach ($keys as $key => $bytes) {
            $this->assertSame($bytes, file_get_contents($this->localDirectory('phr-documents').'/'.$key), 'the object key is the path under the directory');
        }
        $this->assertSame('an export', file_get_contents($this->localDirectory('phr-exports').'/patients/1/exports/cccccccc/export.zip'));

        $this->artisan('phr:storage:verify')->assertSuccessful();
        $reportOnLocal = app(PhrStorageVerifier::class)->verify();
        $this->assertSame($reportOnStore['disks'], $reportOnLocal['disks'], 'both sides report the same count, size and hash');

        $back = $this->objectStoreRun.'-back';
        foreach (self::OBJECT_STORE_DISKS as $disk => $directory) {
            $this->copyLocalToStore($back, $directory);
            $this->useObjectStoreDisk($disk, $back, $directory);
        }
        if (LiveObjectStore::configured()) {
            $this->assertContains($back.'/phr-documents/'.array_key_first($keys), LiveObjectStore::keys($back.'/'));
        }

        $this->artisan('phr:storage:verify')->assertSuccessful();
        $this->assertSame($reportOnStore['disks'], app(PhrStorageVerifier::class)->verify()['disks'], 'and so does the copy back');
        foreach ($keys as $key => $bytes) {
            $this->assertSame($bytes, (string) Storage::disk('phr_documents')->get($key));
        }
        $this->assertSame('still inside its holding period', Storage::disk('phr_documents')->get($quarantined), 'quarantine travels at the same key, so its hold still counts');
        $this->assertEquals($rows, DB::table('phr_documents')->orderBy('id')->get()->toArray(), 'no row changed');

        if (LiveObjectStore::configured()) {
            LiveObjectStore::purge($back.'/');
        }
    }
}

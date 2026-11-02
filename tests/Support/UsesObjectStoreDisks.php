<?php

namespace Tests\Support;

use App\Services\PHR\Documents\UploadUrlSigner;
use App\Support\Storage\StagedUpload;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Puts every PHR disk on an object store for one test: the real one when
 * PHR_TEST_S3_ENDPOINT selects a local server, otherwise NoLocalPathDisk, which fails on
 * the same mistakes a Storage::fake disk would hide. The same tests run either way, so
 * the default suite needs no server and a live run proves the behaviour against one.
 */
trait UsesObjectStoreDisks
{
    /** Each disk's directory or object prefix, the name a by-hand copy works from. */
    protected const array OBJECT_STORE_DISKS = [
        's3' => 's3-blobs',
        'phr_dicom' => 'phr-dicom',
        'phr_uploads' => 'phr-uploads',
        'phr_documents' => 'phr-documents',
        'phr_exports' => 'phr-exports',
    ];

    protected string $objectStoreRun = '';

    /** Where NoLocalPathDisk keeps a run's files, or a by-hand copy its local side. */
    protected string $objectStoreDirectory = '';

    protected function useObjectStoreDisks(): void
    {
        $this->objectStoreRun = 'run-'.Str::lower((string) Str::ulid());
        $this->objectStoreDirectory = sys_get_temp_dir().'/phr-test-disks-'.$this->objectStoreRun;
        mkdir($this->objectStoreDirectory, 0700, true);
        Storage::fake(StagedUpload::SPOOL_DISK);

        foreach (self::OBJECT_STORE_DISKS as $disk => $directory) {
            $this->useObjectStoreDisk($disk, $this->objectStoreRun, $directory);
        }
    }

    protected function useObjectStoreDisk(string $disk, string $run, string $directory): void
    {
        if (LiveObjectStore::configured()) {
            LiveObjectStore::useDisk($disk, $run);

            return;
        }
        $path = $this->objectStoreDirectory.'/'.$run.'/'.$directory;
        File::ensureDirectoryExists($path);
        Storage::set($disk, new NoLocalPathDisk($path));
    }

    /** Turn on the browser's direct transport, as PHR_STORAGE=s3 does. */
    protected function useDirectUploads(): void
    {
        config(['phr.storage' => 's3']);
        if (! LiveObjectStore::configured()) {
            $this->app->instance(UploadUrlSigner::class, new FakeObjectStoreUploads);
        }
    }

    /**
     * Send bytes to a signed URL as a browser would, past the application.
     *
     * @param  array{url: string, headers: array<string, string>}  $signed
     * @return array{status: int, etag: string|null}
     */
    protected function putToStore(array $signed, string $body): array
    {
        $store = $this->app->make(UploadUrlSigner::class);
        if ($store instanceof FakeObjectStoreUploads) {
            return $store->receive($signed['url'], $body);
        }
        $response = Http::withHeaders($signed['headers'])->withBody($body, $signed['headers']['Content-Type'] ?? '')->put($signed['url']);

        return ['status' => $response->status(), 'etag' => $response->header('ETag') ?: null];
    }

    /** @return list<string> ids of the uploads open at the store on one disk, under a disk-relative prefix */
    protected function openUploads(string $disk, string $prefix = ''): array
    {
        $store = $this->app->make(UploadUrlSigner::class);
        if ($store instanceof FakeObjectStoreUploads) {
            $found = array_filter($store->uploads, static fn (array $upload): bool => $upload['disk'] === $disk && str_starts_with($upload['path'], $prefix));

            return array_keys($found);
        }
        $ids = [];
        $root = trim((string) config("filesystems.disks.{$disk}.root"), '/');
        foreach (LiveObjectStore::client()->getPaginator('ListMultipartUploads', ['Bucket' => (string) getenv('PHR_TEST_S3_BUCKET'), 'Prefix' => $root.'/'.$prefix]) as $page) {
            foreach ($page['Uploads'] ?? [] as $upload) {
                $ids[] = (string) $upload['UploadId'];
            }
        }

        return $ids;
    }

    protected function tearDownObjectStoreDisks(): void
    {
        if ($this->objectStoreDirectory !== '') {
            File::deleteDirectory($this->objectStoreDirectory);
        }
        if ($this->objectStoreRun !== '' && LiveObjectStore::configured()) {
            LiveObjectStore::purge($this->objectStoreRun.'/');
            LiveObjectStore::abortUploads($this->objectStoreRun.'/');
        }
    }
}

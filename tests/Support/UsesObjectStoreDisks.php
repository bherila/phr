<?php

namespace Tests\Support;

use App\Support\Storage\StagedUpload;
use Illuminate\Support\Facades\File;
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

    protected function tearDownObjectStoreDisks(): void
    {
        if ($this->objectStoreDirectory !== '') {
            File::deleteDirectory($this->objectStoreDirectory);
        }
        if ($this->objectStoreRun !== '' && LiveObjectStore::configured()) {
            LiveObjectStore::purge($this->objectStoreRun.'/');
        }
    }
}

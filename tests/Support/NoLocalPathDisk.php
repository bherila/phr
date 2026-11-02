<?php

namespace Tests\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use LogicException;

/**
 * A disk that behaves like an object store where it matters to callers. A Storage::fake
 * disk is a local disk, so code that calls ->path() or link() or seeks in a read stream
 * passes on it and fails in production. Here:
 *
 *  - path() throws, where on a real object store it returns a key that reads as a
 *    relative path that does not exist;
 *  - LocalObjectFile::isLocal() is false, because the adapter is not the local one;
 *  - read streams cannot seek and return short reads, like a network stream;
 *  - writes can be refused, and observed.
 */
final class NoLocalPathDisk extends FilesystemAdapter
{
    public function __construct(public readonly string $directory)
    {
        $adapter = new ObjectStoreLikeAdapter(new LocalFilesystemAdapter($directory));
        parent::__construct(new Filesystem($adapter), $adapter, ['driver' => 'object-store-like']);
    }

    public function path($path)
    {
        throw new LogicException('A non-local disk has no local path. Use Storage streams or LocalObjectFile.');
    }

    public function objectStore(): ObjectStoreLikeAdapter
    {
        $adapter = $this->getAdapter();
        assert($adapter instanceof ObjectStoreLikeAdapter);

        return $adapter;
    }
}

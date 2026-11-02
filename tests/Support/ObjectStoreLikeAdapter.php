<?php

namespace Tests\Support;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToWriteFile;
use LogicException;
use RuntimeException;

/** Local files behind an adapter that is not the local one. See NoLocalPathDisk. */
final class ObjectStoreLikeAdapter implements FlysystemAdapter
{
    public bool $readOnly = false;

    /** @var (callable(string): void)|null runs after each object is written */
    public $afterWrite = null;

    public function __construct(private readonly LocalFilesystemAdapter $files) {}

    /** @param callable(RuntimeException): FilesystemException $failure */
    private function refuseIfReadOnly(callable $failure): void
    {
        if ($this->readOnly) {
            throw $failure(new RuntimeException('This disk is read-only.'));
        }
    }

    public function fileExists(string $path): bool
    {
        return $this->files->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->files->directoryExists($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->refuseIfReadOnly(fn (RuntimeException $why) => UnableToWriteFile::atLocation($path, $why->getMessage()));
        $this->files->write($path, $contents, $config);
        if ($this->afterWrite !== null) {
            ($this->afterWrite)($path);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->refuseIfReadOnly(fn (RuntimeException $why) => UnableToWriteFile::atLocation($path, $why->getMessage()));
        $this->files->writeStream($path, $contents, $config);
        if ($this->afterWrite !== null) {
            ($this->afterWrite)($path);
        }
    }

    public function read(string $path): string
    {
        return $this->files->read($path);
    }

    public function readStream(string $path)
    {
        return NetworkLikeStream::wrap($this->files->readStream($path));
    }

    public function delete(string $path): void
    {
        $this->refuseIfReadOnly(fn (RuntimeException $why) => UnableToDeleteFile::atLocation($path, $why->getMessage()));
        $this->files->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->refuseIfReadOnly(fn (RuntimeException $why) => UnableToDeleteDirectory::atLocation($path, $why->getMessage()));
        $this->files->deleteDirectory($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->refuseIfReadOnly(fn (RuntimeException $why) => UnableToCreateDirectory::atLocation($path, $why->getMessage()));
        $this->files->createDirectory($path, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw new LogicException('Object stores differ on visibility, so PHR does not use it.');
    }

    public function visibility(string $path): FileAttributes
    {
        throw new LogicException('Object stores differ on visibility, so PHR does not use it.');
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->files->mimeType($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->files->lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->files->fileSize($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return $this->files->listContents($path, $deep);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->refuseIfReadOnly(fn (RuntimeException $why) => UnableToMoveFile::because($why->getMessage(), $source, $destination));
        $this->files->move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->refuseIfReadOnly(fn (RuntimeException $why) => UnableToCopyFile::because($why->getMessage(), $source, $destination));
        $this->files->copy($source, $destination, $config);
    }
}

<?php

namespace App\Services\PHR\Import\Ehi;

use App\Models\PhrEhiPackage;
use ZipArchive;

/**
 * A supplied ZIP read in place. Entries are described from the central directory
 * and opened as streams; nothing is extracted to disk.
 */
final class EhiZipSource implements EhiPackageSource
{
    private const int TYPE_MASK = 0170000;

    private const int TYPE_FILE = 0100000;

    private const int TYPE_DIRECTORY = 0040000;

    private const int TYPE_LINK = 0120000;

    private const int DOS_DIRECTORY = 0x10;

    private ?ZipArchive $zip;

    /** @var list<EhiEntry>|null */
    private ?array $entries = null;

    /** @var array{sha256: string, bytes: int}|null */
    private ?array $original = null;

    public function __construct(private readonly string $path)
    {
        $zip = new ZipArchive;
        if (! is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package archive is not a readable ZIP.');
        }
        $this->zip = $zip;
    }

    public function kind(): string
    {
        return PhrEhiPackage::INTAKE_ZIP;
    }

    public function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }
        $zip = $this->archive();

        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if ($stat === false) {
                throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package archive has an unreadable entry.');
            }
            $name = (string) $stat['name'];

            $opsys = 0;
            $attributes = 0;
            $mode = 0;
            if ($zip->getExternalAttributesIndex($index, $opsys, $attributes, ZipArchive::FL_UNCHANGED)) {
                // Unix mode bits live in the high half whatever host is claimed.
                $mode = ($attributes >> 16) & self::TYPE_MASK;
            }
            $isDirectory = str_ends_with($name, '/')
                || $mode === self::TYPE_DIRECTORY
                || ($mode === 0 && $opsys === ZipArchive::OPSYS_DOS && ($attributes & self::DOS_DIRECTORY) !== 0);
            $isSymlink = $mode === self::TYPE_LINK;
            $isSpecial = ! $isSymlink && ! in_array($mode, [0, self::TYPE_FILE, self::TYPE_DIRECTORY], true);

            $entries[] = new EhiEntry(
                originalPath: $name,
                declaredBytes: (int) $stat['size'],
                isDirectory: $isDirectory && ! $isSymlink && ! $isSpecial,
                isSymlink: $isSymlink,
                isSpecial: $isSpecial,
                isEncrypted: (int) $stat['encryption_method'] !== ZipArchive::EM_NONE,
                compressedBytes: (int) $stat['comp_size'],
                handle: $index,
            );
        }

        return $this->entries = $entries;
    }

    public function open(EhiEntry $entry)
    {
        $zip = $this->archive();
        $index = $entry->handle;
        $stat = is_int($index) ? $zip->statIndex($index, ZipArchive::FL_UNCHANGED) : false;
        if ($stat === false || $stat['name'] !== $entry->originalPath || $entry->isDirectory || $entry->isSymlink || $entry->isSpecial || $entry->isEncrypted) {
            throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package entry was not listed as a readable file.');
        }

        $stream = $zip->getStreamIndex((int) $index, ZipArchive::FL_UNCHANGED);
        if (! is_resource($stream)) {
            throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package entry could not be opened.');
        }

        return $stream;
    }

    /** @return array{sha256: string, bytes: int} */
    public function originalArchive(): array
    {
        if ($this->original !== null) {
            return $this->original;
        }

        $stream = @fopen($this->path, 'rb');
        if (! is_resource($stream)) {
            throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package archive could not be re-read.');
        }
        try {
            $hash = hash_init('sha256');
            $bytes = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package archive could not be re-read.');
                }
                $bytes += strlen($chunk);
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }

        return $this->original = ['sha256' => hash_final($hash), 'bytes' => $bytes];
    }

    public function close(): void
    {
        if ($this->zip !== null) {
            $this->zip->close();
            $this->zip = null;
        }
        $this->entries = null;
    }

    private function archive(): ZipArchive
    {
        if ($this->zip === null) {
            throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package archive is closed.');
        }

        return $this->zip;
    }
}

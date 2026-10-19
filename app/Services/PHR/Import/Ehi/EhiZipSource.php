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

    /** @var resource|null the container ZipArchive opened, held for hashing */
    private $handle;

    /** @var list<EhiEntry>|null */
    private ?array $entries = null;

    /** @var array{sha256: string, bytes: int}|null */
    private ?array $original = null;

    public function __construct(private readonly string $path)
    {
        $handle = is_file($path) ? @fopen($path, 'rb') : false;
        if (! is_resource($handle)) {
            throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package archive is not a readable ZIP.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            fclose($handle);

            throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package archive is not a readable ZIP.');
        }
        // ZipArchive opens by path. The held handle is hashed for the archive
        // metadata, so the path must still name that same file once ZipArchive
        // has it open; a swapped path would describe another container.
        clearstatcache(true, $path);
        $held = fstat($handle);
        $named = @stat($path);
        if ($held === false || $named === false || $held['dev'] !== $named['dev'] || $held['ino'] !== $named['ino']) {
            $zip->close();
            fclose($handle);

            throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package archive changed while it was being opened.');
        }
        $this->zip = $zip;
        $this->handle = $handle;
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

    /**
     * A container larger than any package within the limits needs (a big
     * self-extracting prefix, trailing payload, or padding) is refused before
     * it is read, and the hash never reads past that bound, so recording this
     * metadata cannot become unbounded I/O.
     *
     * @return array{sha256: string, bytes: int}
     */
    public function originalArchive(EhiLimits $limits): array
    {
        if ($this->original !== null) {
            return $this->original;
        }
        $stream = $this->handle;
        if (! is_resource($stream)) {
            throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package archive could not be re-read.');
        }
        $maxBytes = $limits->maxContainerBytes();
        $stat = fstat($stream);
        if ($stat !== false && $stat['size'] > $maxBytes) {
            throw new EhiPackageRejected(EhiPackageRejected::PACKAGE_TOO_LARGE, 'EHI package archive is larger than any package within the intake limits.');
        }
        if (! rewind($stream)) {
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
                if ($bytes > $maxBytes) {
                    // Grew after it was opened; never read past the bound.
                    throw new EhiPackageRejected(EhiPackageRejected::PACKAGE_TOO_LARGE, 'EHI package archive is larger than any package within the intake limits.');
                }
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
            $this->handle = null;
        }

        return $this->original = ['sha256' => hash_final($hash), 'bytes' => $bytes];
    }

    public function close(): void
    {
        if ($this->zip !== null) {
            $this->zip->close();
            $this->zip = null;
        }
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
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

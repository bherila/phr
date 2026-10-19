<?php

namespace App\Services\PHR\Import\Ehi;

use App\Models\PhrEhiPackage;

/**
 * An already-extracted package directory. Listing never follows symlinks, so a
 * link to anything (inside or outside the root) is reported, not traversed.
 */
final class EhiDirectorySource implements EhiPackageSource
{
    private const int TYPE_MASK = 0170000;

    private const int TYPE_FILE = 0100000;

    private const int TYPE_DIRECTORY = 0040000;

    private const int TYPE_LINK = 0120000;

    private readonly string $root;

    /** @var array<string, array{dev: int, ino: int}> regular files seen while listing, by absolute path */
    private array $listedFiles = [];

    /** @var list<EhiEntry>|null */
    private ?array $entries = null;

    public function __construct(string $root)
    {
        $resolved = realpath($root);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package directory is not readable.');
        }
        $this->root = rtrim($resolved, '/');
    }

    public function kind(): string
    {
        return PhrEhiPackage::INTAKE_DIRECTORY;
    }

    public function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = [];
        $this->listedFiles = [];
        // Iterative walk: a hostile depth cannot exhaust the call stack.
        $pending = [''];
        while ($pending !== []) {
            $relativeDirectory = array_pop($pending);
            $absoluteDirectory = $relativeDirectory === '' ? $this->root : $this->root.'/'.$relativeDirectory;
            $names = @scandir($absoluteDirectory, SCANDIR_SORT_ASCENDING);
            if ($names === false) {
                throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package directory could not be listed.');
            }

            $subdirectories = [];
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $relative = $relativeDirectory === '' ? $name : $relativeDirectory.'/'.$name;
                $absolute = $absoluteDirectory.'/'.$name;
                $stat = @lstat($absolute);
                if ($stat === false) {
                    throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package entry could not be inspected.');
                }

                $type = $stat['mode'] & self::TYPE_MASK;
                if ($type === self::TYPE_DIRECTORY) {
                    $entries[] = new EhiEntry($relative, 0, isDirectory: true, handle: $absolute);
                    $subdirectories[] = $relative;
                } elseif ($type === self::TYPE_LINK) {
                    $entries[] = new EhiEntry($relative, 0, isSymlink: true, handle: $absolute);
                } elseif ($type === self::TYPE_FILE) {
                    $entries[] = new EhiEntry($relative, (int) $stat['size'], handle: $absolute);
                    $this->listedFiles[$absolute] = ['dev' => (int) $stat['dev'], 'ino' => (int) $stat['ino']];
                } else {
                    $entries[] = new EhiEntry($relative, 0, isSpecial: true, handle: $absolute);
                }
            }
            // Reverse so the stack pops subdirectories in ascending order.
            foreach (array_reverse($subdirectories) as $subdirectory) {
                $pending[] = $subdirectory;
            }
        }

        return $this->entries = $entries;
    }

    public function open(EhiEntry $entry)
    {
        $path = $entry->handle;
        if (! is_string($path) || $path !== $this->root.'/'.$entry->originalPath || ! isset($this->listedFiles[$path])) {
            throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package entry was not listed as a regular file.');
        }
        $listed = $this->listedFiles[$path];

        // Re-check at open time: the tree may have changed since it was listed.
        $before = @lstat($path);
        if ($before === false) {
            throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package entry is no longer readable.');
        }
        $type = $before['mode'] & self::TYPE_MASK;
        if ($type === self::TYPE_LINK) {
            throw new EhiPackageRejected(EhiPackageRejected::SYMLINK, 'EHI package entry became a symbolic link.');
        }
        if ($type !== self::TYPE_FILE) {
            throw new EhiPackageRejected(EhiPackageRejected::SPECIAL_FILE, 'EHI package entry is no longer a regular file.');
        }
        // A parent swapped for a link would resolve somewhere else.
        if (realpath($path) !== $path) {
            throw new EhiPackageRejected(EhiPackageRejected::SYMLINK, 'EHI package entry no longer resolves inside the package.');
        }

        $stream = @fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package entry could not be opened.');
        }
        // The opened inode must be the one listed and the one just checked.
        $opened = fstat($stream);
        if ($opened === false
            || ($opened['mode'] & self::TYPE_MASK) !== self::TYPE_FILE
            || (int) $opened['dev'] !== $listed['dev'] || (int) $opened['ino'] !== $listed['ino']
            || (int) $before['dev'] !== $listed['dev'] || (int) $before['ino'] !== $listed['ino']) {
            fclose($stream);
            throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'EHI package entry changed after it was listed.');
        }

        return $stream;
    }

    public function originalArchive(EhiLimits $limits): ?array
    {
        return null;
    }

    public function close(): void
    {
        $this->listedFiles = [];
        $this->entries = null;
    }
}

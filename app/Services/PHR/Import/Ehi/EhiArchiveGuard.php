<?php

namespace App\Services\PHR\Import\Ehi;

use Normalizer;

/**
 * Validates a package's whole entry list before any byte is copied, then copies
 * members with re-measured sizes. Every refusal carries a fixed reason code and
 * a structural message: never an entry name or content.
 */
final class EhiArchiveGuard
{
    private const int CHUNK_BYTES = 1024 * 1024;

    /** Compression ratios on small members are noise; a bomb needs volume. */
    private const int RATIO_FLOOR_BYTES = 1024 * 1024;

    /**
     * @return list<EhiValidatedMember> file members sorted by normalized path (byte order)
     *
     * @throws EhiPackageRejected
     */
    public function validate(EhiPackageSource $source, EhiLimits $limits): array
    {
        $entries = $source->entries();
        if (count($entries) > $limits->maxMembers) {
            throw $this->reject(EhiPackageRejected::TOO_MANY_MEMBERS, sprintf('%d entries exceed the limit of %d', count($entries), $limits->maxMembers));
        }

        $members = [];
        $memberKeys = [];
        $ancestorKeys = [];
        $totalBytes = 0;
        $totalCompressed = 0;
        foreach ($entries as $entry) {
            $path = $this->normalizePath($entry);
            $segments = explode('/', $path);
            if (count($segments) > $limits->maxPathDepth) {
                throw $this->reject(EhiPackageRejected::PATH_TOO_DEEP, sprintf('an entry is nested deeper than %d levels', $limits->maxPathDepth));
            }
            if (strlen($path) > $limits->maxPathBytes) {
                throw $this->reject(EhiPackageRejected::PATH_TOO_LONG, sprintf('an entry path exceeds %d bytes', $limits->maxPathBytes));
            }
            if ($entry->isSymlink) {
                throw $this->reject(EhiPackageRejected::SYMLINK, 'an entry is a symbolic link');
            }
            if ($entry->isSpecial) {
                throw $this->reject(EhiPackageRejected::SPECIAL_FILE, 'an entry is not a regular file or directory');
            }
            if ($entry->isEncrypted) {
                throw $this->reject(EhiPackageRejected::ENCRYPTED, 'an entry is encrypted');
            }
            if ($entry->declaredBytes < 0 || ($entry->compressedBytes !== null && $entry->compressedBytes < 0)) {
                throw $this->reject(EhiPackageRejected::UNREADABLE, 'an entry declares a negative size');
            }

            // Every ancestor is an implied directory; a file there would be ambiguous.
            $key = $this->collisionKey($path);
            $keySegments = explode('/', $key);
            for ($depth = 1; $depth < count($keySegments); $depth++) {
                $ancestorKeys[implode('/', array_slice($keySegments, 0, $depth))] = true;
            }

            if ($entry->isDirectory) {
                if ($entry->declaredBytes !== 0) {
                    throw $this->reject(EhiPackageRejected::PATH_UNSAFE, 'a directory entry carries content');
                }
                $ancestorKeys[$key] = true;

                continue;
            }

            if (isset($memberKeys[$key])) {
                throw $this->reject(EhiPackageRejected::PATH_COLLISION, 'two entries share a name after normalization');
            }
            $memberKeys[$key] = true;

            if ($entry->declaredBytes > $limits->maxMemberBytes) {
                throw $this->reject(EhiPackageRejected::MEMBER_TOO_LARGE, sprintf('an entry declares more than %d bytes', $limits->maxMemberBytes));
            }
            if ($totalBytes > $limits->maxPackageBytes - $entry->declaredBytes) {
                throw $this->reject(EhiPackageRejected::PACKAGE_TOO_LARGE, sprintf('entries declare more than %d bytes in total', $limits->maxPackageBytes));
            }
            $totalBytes += $entry->declaredBytes;

            if ($entry->compressedBytes !== null) {
                if ($this->exceedsRatio($entry->declaredBytes, $entry->compressedBytes, $limits->maxExpansionRatio)) {
                    throw $this->reject(EhiPackageRejected::EXPANSION_RATIO, sprintf('an entry expands more than %d times', $limits->maxExpansionRatio));
                }
                $totalCompressed += $entry->compressedBytes;
            }

            $members[] = new EhiValidatedMember($path, $entry);
        }

        if ($totalCompressed > 0 && $this->exceedsRatio($totalBytes, $totalCompressed, $limits->maxExpansionRatio)) {
            throw $this->reject(EhiPackageRejected::EXPANSION_RATIO, sprintf('the package expands more than %d times', $limits->maxExpansionRatio));
        }
        $collisions = count(array_intersect_key($memberKeys, $ancestorKeys));
        if ($collisions > 0) {
            throw $this->reject(EhiPackageRejected::PATH_COLLISION, sprintf('%d file entries share a name with a directory', $collisions));
        }

        usort($members, static fn (EhiValidatedMember $a, EhiValidatedMember $b): int => strcmp($a->path, $b->path));

        return $members;
    }

    /**
     * Stream a member to `$destination`, hashing as it goes. Reading stops one byte
     * past the smaller of the declared size and the cap, so an entry that lies
     * about its size can never be read without bound.
     *
     * @param  resource  $stream
     * @param  resource  $destination
     * @return array{sha256: string, bytes: int}
     *
     * @throws EhiPackageRejected
     */
    public function copyBounded($stream, $destination, int $declaredBytes, int $maxBytes): array
    {
        $readLimit = max(0, min($declaredBytes, $maxBytes)) + 1;
        $hash = hash_init('sha256');
        $bytes = 0;
        while ($bytes < $readLimit && ! feof($stream)) {
            $chunk = fread($stream, min(self::CHUNK_BYTES, $readLimit - $bytes));
            if ($chunk === false) {
                throw $this->reject(EhiPackageRejected::UNREADABLE, 'an entry could not be read');
            }
            if ($chunk === '') {
                break;
            }
            $bytes += strlen($chunk);
            if ($bytes > $maxBytes) {
                throw $this->reject(EhiPackageRejected::MEMBER_TOO_LARGE, sprintf('an entry holds more than %d bytes', $maxBytes));
            }
            if ($bytes > $declaredBytes) {
                break;
            }
            hash_update($hash, $chunk);
            $this->writeAll($destination, $chunk);
        }

        if ($bytes !== $declaredBytes) {
            throw $this->reject(EhiPackageRejected::SIZE_MISMATCH, 'an entry size differs from its declared size');
        }

        return ['sha256' => hash_final($hash), 'bytes' => $bytes];
    }

    /**
     * Canonical package identity: independent of container, compression, and
     * member order, so a repacked export with identical members converges.
     *
     * @param  list<array{path: string, bytes: int, sha256: string}>  $members
     */
    public function manifestDigest(array $members): string
    {
        $canonical = array_map(static fn (array $member): array => [
            'path' => $member['path'],
            'bytes' => $member['bytes'],
            'sha256' => $member['sha256'],
        ], $members);
        usort($canonical, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Relative, forward-slash, NFC path with no empty, `.`, or `..` segments. */
    private function normalizePath(EhiEntry $entry): string
    {
        $path = $entry->originalPath;
        // ZIP directory entries end in one slash; nothing else may.
        if ($entry->isDirectory && str_ends_with($path, '/')) {
            $path = substr($path, 0, -1);
        }

        if ($path === ''
            || ! mb_check_encoding($path, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw $this->reject(EhiPackageRejected::PATH_UNSAFE, 'an entry path is unsafe');
        }

        $normalized = Normalizer::normalize($path, Normalizer::FORM_C);
        if (! is_string($normalized) || preg_match('/[\x{80}-\x{9F}]/u', $normalized) === 1) {
            throw $this->reject(EhiPackageRejected::PATH_UNSAFE, 'an entry path is unsafe');
        }
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw $this->reject(EhiPackageRejected::PATH_UNSAFE, 'an entry path is unsafe');
            }
        }

        return $normalized;
    }

    /** Names that would land on the same file on a case-insensitive or normalizing filesystem. */
    private function collisionKey(string $path): string
    {
        $folded = Normalizer::normalize(mb_convert_case($path, MB_CASE_FOLD, 'UTF-8'), Normalizer::FORM_C);

        return is_string($folded) ? $folded : $path;
    }

    private function exceedsRatio(int $declared, int $compressed, int $maxRatio): bool
    {
        if ($declared <= self::RATIO_FLOOR_BYTES) {
            return false;
        }

        // declared > compressed * ratio, without the multiplication overflowing.
        return $compressed === 0 || intdiv($declared - 1, $compressed) >= $maxRatio;
    }

    /** @param resource $destination */
    private function writeAll($destination, string $chunk): void
    {
        $offset = 0;
        $length = strlen($chunk);
        while ($offset < $length) {
            $written = fwrite($destination, substr($chunk, $offset));
            if ($written === false || $written === 0) {
                throw $this->reject(EhiPackageRejected::UNREADABLE, 'an entry could not be written');
            }
            $offset += $written;
        }
    }

    private function reject(string $reason, string $detail): EhiPackageRejected
    {
        return new EhiPackageRejected($reason, sprintf('EHI package refused (%s): %s.', $reason, $detail));
    }
}

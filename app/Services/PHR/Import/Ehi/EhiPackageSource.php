<?php

namespace App\Services\PHR\Import\Ehi;

/**
 * A supplied package (an extracted directory or a ZIP) that can list its entries
 * without extracting anything and open one validated entry as a read stream.
 */
interface EhiPackageSource
{
    /** PhrEhiPackage::INTAKE_DIRECTORY or PhrEhiPackage::INTAKE_ZIP. */
    public function kind(): string;

    /**
     * @param  int|null  $maxEntries  refuse (too_many_members) as soon as more
     *                                entries than this are found, before listing the rest
     * @return list<EhiEntry> every entry, including directories and unsafe ones
     *
     * @throws EhiPackageRejected
     */
    public function entries(?int $maxEntries = null): array;

    /**
     * Open a read stream for an entry previously returned by entries().
     *
     * @return resource
     *
     * @throws EhiPackageRejected
     */
    public function open(EhiEntry $entry);

    /** @return array{sha256: string, bytes: int}|null metadata about a supplied archive file */
    public function originalArchive(EhiLimits $limits): ?array;

    public function close(): void;
}

<?php

namespace App\Services\PHR\Import\Ehi;

/** EHI-only usage, valid before writes inside the same locked patient chunk. */
final readonly class EhiPreviewEvidenceUsage
{
    public function __construct(public int $patientId, public int $rows, public int $bytes) {}
}

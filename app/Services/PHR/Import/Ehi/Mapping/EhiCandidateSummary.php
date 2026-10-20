<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

/** A display prefix plus a digest of every match; omitted candidates stay linkable. */
final class EhiCandidateSummary
{
    public const int LIMIT = 100;

    /** @var list<int> */
    public array $ids = [];

    public int $total = 0;

    private \HashContext $hash;

    public function __construct()
    {
        $this->hash = hash_init('sha256');
        hash_update($this->hash, "ehi-candidates-1\n");
    }

    /** Call in strictly ascending, unique record-id order. */
    public function add(int $id): void
    {
        $this->total++;
        hash_update($this->hash, $id."\n");
        if (count($this->ids) < self::LIMIT) {
            $this->ids[] = $id;
        }
    }

    /** @return array{version: int, total: int, truncated: bool, set_sha256: string} */
    public function metadata(): array
    {
        return ['version' => 1, 'total' => $this->total, 'truncated' => $this->total > count($this->ids), 'set_sha256' => hash_final(hash_copy($this->hash))];
    }
}

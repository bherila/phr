<?php

namespace App\Services\PHR\NativeBackup;

use App\Models\PhrNativeRecordIdentity;
use Illuminate\Support\Str;

/**
 * Durable archive identities for rows that generally have no immutable natural key.
 *
 * R3 deliberately does not derive identity from mutable clinical content: an edit
 * would produce a new identity, and duplicate observations could collapse to one.
 * It also does not expose database ids. An opaque UUID is assigned once, persisted,
 * emitted as nativeId, and recreated by restore beside the remapped database row.
 * Consequently later backups retain identity even when database ids have changed.
 */
final class PhrNativeIdentityRepository
{
    /** @var array<string, string> */
    private array $cache = [];

    public function forRecord(int $patientId, string $table, int $recordId): string
    {
        $key = $patientId.':'.$table.':'.$recordId;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $identity = PhrNativeRecordIdentity::query()->firstOrCreate(
            [
                'patient_id' => $patientId,
                'record_table' => $table,
                'record_id' => $recordId,
            ],
            ['native_id' => (string) Str::uuid()],
        );

        return $this->cache[$key] = $identity->native_id;
    }
}

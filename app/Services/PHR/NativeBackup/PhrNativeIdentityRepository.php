<?php

namespace App\Services\PHR\NativeBackup;

use App\Models\PhrNativeRecordIdentity;
use Illuminate\Support\Facades\DB;
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

    /**
     * Bulk form for one page of a streamed snapshot: fetch the identities that
     * exist and assign the rest, in a constant number of queries. Nothing is
     * cached, so memory stays bounded by the page rather than the patient.
     *
     * An insert that loses a race to a concurrent writer is ignored and the
     * winner's identity is read back, as firstOrCreate() would.
     *
     * @param  list<int>  $recordIds
     * @return array<int, string> native id keyed by record id
     */
    public function forRecords(int $patientId, string $table, array $recordIds): array
    {
        $recordIds = array_values(array_unique($recordIds));
        if ($recordIds === []) {
            return [];
        }

        $found = $this->existing($patientId, $table, $recordIds);
        $missing = array_values(array_diff($recordIds, array_keys($found)));
        if ($missing === []) {
            return $found;
        }

        $now = now();
        DB::table('phr_native_record_identities')->insertOrIgnore(array_map(
            static fn (int $recordId): array => [
                'patient_id' => $patientId,
                'record_table' => $table,
                'record_id' => $recordId,
                'native_id' => (string) Str::uuid(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $missing,
        ));
        $found += $this->existing($patientId, $table, $missing);
        if (count($found) !== count($recordIds)) {
            throw new \RuntimeException('Native record identity could not be assigned.');
        }

        return $found;
    }

    /**
     * @param  list<int>  $recordIds
     * @return array<int, string>
     */
    private function existing(int $patientId, string $table, array $recordIds): array
    {
        $found = [];
        foreach (DB::table('phr_native_record_identities')
            ->where('patient_id', $patientId)
            ->where('record_table', $table)
            ->whereIn('record_id', $recordIds)
            ->get(['record_id', 'native_id']) as $identity) {
            $found[(int) $identity->record_id] = (string) $identity->native_id;
        }

        return $found;
    }
}

<?php

namespace App\Services\PHR\NativeBackup;

use Illuminate\Support\Facades\DB;
use LogicException;

/** Insert a bounded archive page inside the caller's graph transaction. */
final class PhrNativeRestoreRecordWriter
{
    private const int BATCH_SIZE = 200;

    /** @var array<string, list<list<string>>> Existing unique keys; never generated-id sequences. */
    private const array KEYS = [
        'phr_ehi_entities' => [['source_id', 'table_name', 'key_digest']],
        'phr_ehi_revisions' => [['entity_id', 'content_digest']],
        'phr_ehi_occurrences' => [['member_id', 'line_number']],
        'phr_ehi_proposals' => [['package_id', 'mapping', 'mapping_version', 'subject_key']],
        'phr_ehi_proposal_revisions' => [['proposal_id', 'revision_id']],
        'phr_ehi_record_links' => [
            ['revision_id', 'immunization_id', 'link_kind'],
            ['revision_id', 'document_id', 'link_kind'],
            ['revision_id', 'lab_result_id', 'link_kind'],
            ['revision_id', 'office_visit_id', 'link_kind'],
        ],
    ];

    /**
     * @param  list<array<string, mixed>>  $rows  already bounded by archive page count and bytes
     * @return list<int> actual generated ids in input order
     */
    public function insertPage(int $patientId, string $table, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Native record pages require the graph transaction.');
        }
        $candidates = self::KEYS[$table] ?? [];
        if ($candidates !== []) {
            foreach ($rows as $row) {
                if ((string) ($row['patient_id'] ?? '') !== (string) $patientId) {
                    throw new NativeRestoreException('record_conflict');
                }
            }
        }
        $keys = null;
        foreach ($candidates as $candidate) {
            if ($this->canBatch($rows, $candidate)) {
                $keys = $candidate;
                break;
            }
        }
        if ($keys === null) {
            return array_map(static fn (array $row): int => (int) DB::table($table)->insertGetId($row), $rows);
        }

        $ids = [];
        foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
            // Plain INSERT must refuse a collision, including one outside this
            // patient. Ignoring/upserting a conflict could remap someone else's row.
            DB::table($table)->insert($chunk);
            $found = [];
            $query = DB::table($table)->where('patient_id', $patientId)
                ->where(function ($query) use ($chunk, $keys): void {
                    foreach ($chunk as $row) {
                        $query->orWhere(function ($query) use ($row, $keys): void {
                            foreach ($keys as $column) {
                                $query->where($column, $row[$column]);
                            }
                        });
                    }
                });
            foreach ($query->get(['id', ...$keys]) as $record) {
                $key = $this->key((array) $record, $keys);
                if (isset($found[$key])) {
                    throw new NativeRestoreException('record_conflict');
                }
                $found[$key] = (int) $record->id;
            }
            if (count($found) !== count($chunk)) {
                throw new NativeRestoreException('record_conflict');
            }
            foreach ($chunk as $row) {
                $ids[] = $found[$this->key($row, $keys)] ?? throw new NativeRestoreException('record_conflict');
            }
        }

        return $ids;
    }

    /** @param list<array<string, mixed>> $rows
     * @param  list<string>  $keys
     */
    private function canBatch(array $rows, array $keys): bool
    {
        $columns = array_keys($rows[0]);
        sort($columns);
        foreach ($rows as $row) {
            $current = array_keys($row);
            sort($current);
            if ($current !== $columns) {
                return false;
            }
            foreach ($keys as $column) {
                if (! isset($row[$column]) || (! is_int($row[$column]) && ! is_string($row[$column]))) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $row
     * @param  list<string>  $keys
     */
    private function key(array $row, array $keys): string
    {
        // Length-delimited tuples also preserve a legacy binary-encoded value;
        // JSON keys would reject bytes that the native codec can round-trip.
        return serialize(array_map(static fn (string $column): string => (string) $row[$column], $keys));
    }
}

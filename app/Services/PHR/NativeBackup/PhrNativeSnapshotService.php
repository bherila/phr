<?php

namespace App\Services\PHR\NativeBackup;

use App\Models\PhrDicomFile;
use App\Models\PhrDicomUpload;
use App\Services\PHR\ResourceByteSize;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One consistent, streamed read of a patient's catalog rows.
 *
 * A patient that has imported a large EHI package holds millions of evidence rows
 * (each projected source row adds an entity, a revision, an occurrence and a
 * proposal revision), so rows are never materialized per table. Callers run
 * inside snapshot() and pull each table in id-ordered keyset pages of at most
 * chunkSize() rows; row payloads are bounded by both page rows and raw bytes. Native admission
 * additionally bounds retained metadata and restore actions.
 * Artifact bytes are never read here; the archive builder streams those from
 * their configured disks after the snapshot closes.
 */
final class PhrNativeSnapshotService
{
    public const int DEFAULT_CHUNK_SIZE = 500;

    public function __construct(private readonly ResourceByteSize $rowSizes) {}

    /**
     * Run $callback inside one repeatable-read transaction. Every chunks() and
     * presentIds() call made by the callback observes the same snapshot, exactly
     * as when every table was materialized inside it.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function snapshot(int $patientId, bool $lockForUpdate, callable $callback): mixed
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $alreadyInTransaction = $connection->transactionLevel() > 0;

        // MySQL/MariaDB applies this to the next transaction. PostgreSQL requires
        // it immediately after BEGIN. SQLite transactions already retain one read
        // snapshot after their first query. In every case, all relational rows are
        // read before the transaction closes and artifact streaming begins.
        if ($driver === 'mysql' && ! $alreadyInTransaction) {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $connection->transaction(function () use ($connection, $driver, $patientId, $alreadyInTransaction, $lockForUpdate, $callback): mixed {
            if ($driver === 'pgsql' && ! $alreadyInTransaction) {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            if ($lockForUpdate) {
                // The DICOM upload and instance row sets are defined by the original
                // files. Lock those first (as the materialized snapshot did) so both
                // dependent sets are stable for the rest of the transaction.
                foreach ($this->chunks($patientId, 'phr_dicom_files', true, ['id']) as $ignored) {
                    // Locking is the side effect; the page itself is discarded.
                }
            }

            return $callback();
        });
    }

    /**
     * The table's snapshot rows in ascending id order, one keyset page at a time.
     * Only meaningful inside snapshot().
     *
     * @param  list<string>  $columns
     * @return \Generator<int, list<\stdClass>>
     */
    public function chunks(int $patientId, string $table, bool $lockForUpdate = false, array $columns = ['*']): \Generator
    {
        $size = self::chunkSize();
        $lastId = null;
        $rowSize = $this->rowSizes->row($table);
        while (true) {
            $query = $this->query($patientId, $table)->select($columns);
            if ($lastId !== null) {
                $query->where('id', '>', $lastId);
            }
            // Probe sizes without transferring payloads. Only ids fitting the byte
            // page are hydrated, even when every row is near the individual limit.
            $ids = [];
            $bytes = 0;
            foreach ((clone $query)->select('id')->selectRaw($rowSize.' AS native_row_bytes')->orderBy('id')->limit($size)->get() as $probe) {
                $rowBytes = (int) $probe->native_row_bytes;
                if ($rowBytes > (int) config('phr.native_restore_max_record_bytes')) {
                    throw new NativeBackupException('record_size_limit');
                }
                if ($rowBytes > (int) config('phr.native_page_bytes') - $bytes && $ids !== []) {
                    break;
                }
                $ids[] = (int) $probe->id;
                $bytes += $rowBytes;
            }
            if ($ids === []) {
                return;
            }
            $query->whereIn('id', $ids)->orderBy('id');
            if ($lockForUpdate) {
                $query->lockForUpdate();
            }
            $rows = $query->get()->all();
            if ($rows === []) {
                return;
            }

            yield $rows;

            $lastId = (int) $rows[array_key_last($rows)]->id;
        }
    }

    /**
     * Which of $ids are rows of $table in this snapshot. A relationship to a row
     * outside the snapshot (another patient's row, a dangling id, or a row a
     * catalog row policy excludes) has no archive identity.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function presentIds(int $patientId, string $table, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->query($patientId, $table)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function rowById(int $patientId, string $table, int $id, bool $lockForUpdate): ?\stdClass
    {
        $query = $this->query($patientId, $table)->where('id', $id);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $size = $this->rowSizes->row($table);
        $bytes = (clone $query)->selectRaw($size.' AS native_row_bytes')->first()?->native_row_bytes;
        if ($bytes !== null && (int) $bytes > (int) config('phr.native_restore_max_record_bytes')) {
            throw new NativeRestoreException('record_size_limit');
        }

        return $query->first();
    }

    public static function chunkSize(): int
    {
        return max(1, min(self::DEFAULT_CHUNK_SIZE, (int) config('phr.native_backup_chunk_size', self::DEFAULT_CHUNK_SIZE)));
    }

    private function query(int $patientId, string $table): Builder
    {
        $definition = PhrNativeBackupCatalog::included()[$table] ?? throw new \InvalidArgumentException('Not a native backup catalog table.');
        $query = DB::table($table)->where($definition['patient_column'], $patientId);

        return match ($table) {
            'phr_dicom_files' => $query->whereIn('file_kind', [PhrDicomFile::KIND_DICOM, PhrDicomFile::KIND_DICOMDIR]),
            // Sub-selects rather than materialized id lists keep these predicates
            // independent of the study size while selecting the same rows.
            'phr_dicom_uploads' => $query->where(function (Builder $query) use ($patientId): void {
                $query->where('status', PhrDicomUpload::STATUS_PROCESSED)
                    ->orWhereIn('id', $this->originalFiles($patientId)->select('upload_id'));
            }),
            'phr_dicom_instances' => $query->whereIn('file_id', $this->originalFiles($patientId)->select('id')),
            default => $query,
        };
    }

    private function originalFiles(int $patientId): Builder
    {
        return DB::table('phr_dicom_files')
            ->where('patient_id', $patientId)
            ->whereIn('file_kind', [PhrDicomFile::KIND_DICOM, PhrDicomFile::KIND_DICOMDIR]);
    }
}

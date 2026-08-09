<?php

namespace App\Services\PHR\NativeBackup;

use App\Models\PhrDicomFile;
use App\Models\PhrDicomUpload;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PhrNativeSnapshotService
{
    /**
     * Relational metadata is materialized so references can be translated before
     * emission. Artifact bytes are never materialized; the archive builder streams
     * those from their configured disks.
     *
     * @return array<string, Collection<int, \stdClass>>
     */
    public function rows(int $patientId): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        // MySQL/MariaDB applies this to the next transaction. PostgreSQL requires
        // it immediately after BEGIN. SQLite transactions already retain one read
        // snapshot after their first query. In every case, all relational rows are
        // materialized before the transaction closes and artifact streaming begins.
        if ($driver === 'mysql') {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $connection->transaction(function () use ($connection, $driver, $patientId): array {
            if ($driver === 'pgsql') {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            return $this->materializeRows($patientId);
        });
    }

    /** @return array<string, Collection<int, \stdClass>> */
    private function materializeRows(int $patientId): array
    {
        $originalFiles = DB::table('phr_dicom_files')
            ->where('patient_id', $patientId)
            ->whereIn('file_kind', [PhrDicomFile::KIND_DICOM, PhrDicomFile::KIND_DICOMDIR])
            ->orderBy('id')
            ->get();
        $originalFileIds = $originalFiles->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $originalUploadIds = $originalFiles->pluck('upload_id')->map(static fn ($id): int => (int) $id)->unique()->all();

        $rows = [];
        foreach (PhrNativeBackupCatalog::included() as $table => $definition) {
            $patientColumn = $definition['patient_column'];
            $query = DB::table($table)->where($patientColumn, $patientId)->orderBy('id');

            $rows[$table] = match ($table) {
                'phr_dicom_files' => $originalFiles,
                'phr_dicom_uploads' => $query
                    ->where(function ($query) use ($originalUploadIds): void {
                        $query->where('status', PhrDicomUpload::STATUS_PROCESSED);
                        if ($originalUploadIds !== []) {
                            $query->orWhereIn('id', $originalUploadIds);
                        }
                    })
                    ->get(),
                'phr_dicom_instances' => $originalFileIds === []
                    ? collect()
                    : $query->whereIn('file_id', $originalFileIds)->get(),
                default => $query->get(),
            };
        }

        return $rows;
    }
}

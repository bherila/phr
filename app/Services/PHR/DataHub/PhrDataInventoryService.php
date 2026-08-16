<?php

namespace App\Services\PHR\DataHub;

use App\Models\PhrDicomFile;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PhrDataInventoryService
{
    /**
     * Operational and derived tables are intentionally absent. This fixed map is
     * also the contract future native-backup work must reconcile against.
     *
     * @var array<string, array{table: string, patient_column: string, storage_column?: string, original_dicom_only?: bool, without_soft_deleted?: bool}>
     */
    public const array CATEGORIES = [
        'lab_results' => ['table' => 'phr_lab_results', 'patient_column' => 'patient_id'],
        'vitals' => ['table' => 'phr_patient_vitals', 'patient_column' => 'patient_id'],
        'office_visits' => ['table' => 'phr_office_visits', 'patient_column' => 'patient_id'],
        'medications' => ['table' => 'phr_medications', 'patient_column' => 'patient_id'],
        'conditions' => ['table' => 'phr_conditions', 'patient_column' => 'patient_id'],
        'procedures' => ['table' => 'phr_procedures', 'patient_column' => 'patient_id'],
        'immunizations' => ['table' => 'phr_immunizations', 'patient_column' => 'patient_id'],
        'allergies' => ['table' => 'phr_allergies', 'patient_column' => 'patient_id'],
        'portal_messages' => ['table' => 'phr_portal_messages', 'patient_column' => 'patient_id'],
        'negative_assertions' => ['table' => 'phr_negative_assertions', 'patient_column' => 'patient_id'],
        'health_logs' => ['table' => 'phr_health_logs', 'patient_column' => 'patient_id'],
        'health_log_entries' => ['table' => 'phr_health_log_entries', 'patient_column' => 'patient_id'],
        'respiratory_events' => ['table' => 'phr_respiratory_events', 'patient_column' => 'phr_patient_id'],
        'sinus_settings' => ['table' => 'phr_sinus_settings', 'patient_column' => 'phr_patient_id'],
        'sinus_enrollments' => ['table' => 'phr_sinus_enrollments', 'patient_column' => 'phr_patient_id'],
        'documents' => [
            'table' => 'phr_documents',
            'patient_column' => 'patient_id',
            'storage_column' => 'byte_size',
            'without_soft_deleted' => true,
        ],
        'dicom_studies' => ['table' => 'phr_dicom_studies', 'patient_column' => 'patient_id'],
        'dicom_series' => ['table' => 'phr_dicom_series', 'patient_column' => 'patient_id'],
        'dicom_instances' => ['table' => 'phr_dicom_instances', 'patient_column' => 'patient_id'],
        'original_dicom_files' => [
            'table' => 'phr_dicom_files',
            'patient_column' => 'patient_id',
            'storage_column' => 'file_size_bytes',
            'original_dicom_only' => true,
        ],
    ];

    public function __construct(private readonly PhrPatientAccessService $accessService) {}

    /**
     * @return array{owned_patients: list<array<string, mixed>>, shared_patients: list<array<string, mixed>>}
     */
    public function forUser(int $userId): array
    {
        $ownedPatients = $this->accessService->ownedPatientsQuery($userId)
            ->orderBy('display_name')
            ->orderBy('id')
            ->get(['id', 'owner_user_id', 'display_name', 'relationship', 'updated_at']);
        $ownedIds = $ownedPatients->modelKeys();
        $categoryRows = $this->categoryRows($ownedIds);
        $shareRows = $this->shareRows($ownedIds);

        $owned = $ownedPatients->map(function (PhrPatient $patient) use ($categoryRows, $shareRows): array {
            $counts = array_fill_keys(array_keys(self::CATEGORIES), 0);
            $documentBytes = 0;
            $dicomBytes = 0;
            $lastUpdated = $this->immutableDate($patient->updated_at);

            foreach ($categoryRows->get((int) $patient->id, collect()) as $row) {
                $category = (string) $row->category;
                $counts[$category] = (int) $row->record_count;
                $lastUpdated = $this->latest($lastUpdated, $this->immutableDate($row->last_updated_at));

                if ($category === 'documents') {
                    $documentBytes = (int) $row->storage_bytes;
                } elseif ($category === 'original_dicom_files') {
                    $dicomBytes = (int) $row->storage_bytes;
                }
            }

            $share = $shareRows->get((int) $patient->id);
            $activeShareCount = 0;
            if ($share !== null) {
                $activeShareCount = (int) $share->active_share_count;
                $lastUpdated = $this->latest($lastUpdated, $this->immutableDate($share->last_updated_at));
            }

            return [
                'id' => (int) $patient->id,
                'display_name' => $patient->display_name,
                'relationship' => $patient->relationship,
                'record_counts' => $counts,
                'storage_bytes' => [
                    'documents' => $documentBytes,
                    'original_dicom' => $dicomBytes,
                    'total' => $documentBytes + $dicomBytes,
                ],
                'last_updated_at' => $lastUpdated?->toIso8601String(),
                'active_share_count' => $activeShareCount,
                'operations' => [
                    'clinical_export' => ['eligible' => true, 'status' => 'available', 'format' => 'ccda'],
                    'native_backup' => ['eligible' => true, 'status' => 'available'],
                    'restore' => ['eligible' => true, 'status' => 'planned'],
                    'aggregate_delete' => ['eligible' => true, 'status' => 'available'],
                ],
            ];
        })->values()->all();

        $shared = $this->accessService->sharedPatientsQuery($userId)
            ->with(['accessGrants' => fn ($query) => $query->where('user_id', $userId)])
            ->orderBy('display_name')
            ->orderBy('id')
            ->get(['id', 'owner_user_id', 'display_name', 'relationship'])
            ->map(function (PhrPatient $patient): array {
                $grant = $patient->accessGrants->first();

                return [
                    'id' => (int) $patient->id,
                    'display_name' => $patient->display_name,
                    'relationship' => $patient->relationship,
                    'access_level' => $grant->access_level,
                    'operations' => [
                        'clinical_export' => ['eligible' => false, 'status' => 'owner_only'],
                        'native_backup' => ['eligible' => false, 'status' => 'owner_only'],
                        'restore' => ['eligible' => false, 'status' => 'owner_only'],
                        'aggregate_delete' => ['eligible' => false, 'status' => 'owner_only'],
                    ],
                ];
            })->values()->all();

        return ['owned_patients' => $owned, 'shared_patients' => $shared];
    }

    /**
     * @param  list<int>  $patientIds
     * @return Collection<int|string, Collection<int, \stdClass>>
     */
    private function categoryRows(array $patientIds): Collection
    {
        if ($patientIds === []) {
            return collect();
        }

        $queries = [];
        foreach (self::CATEGORIES as $category => $definition) {
            $patientColumn = $definition['patient_column'];
            $query = DB::table($definition['table'])
                ->selectRaw("{$patientColumn} AS patient_id")
                ->selectRaw('? AS category', [$category])
                ->selectRaw('COUNT(*) AS record_count')
                ->selectRaw('MAX(updated_at) AS last_updated_at')
                ->whereIn($patientColumn, $patientIds)
                ->groupBy($patientColumn);

            if ($definition['without_soft_deleted'] ?? false) {
                $query->whereNull('deleted_at');
            }
            if ($definition['original_dicom_only'] ?? false) {
                $query->whereIn('file_kind', [PhrDicomFile::KIND_DICOM, PhrDicomFile::KIND_DICOMDIR]);
            }

            $storageColumn = $definition['storage_column'] ?? null;
            $query->selectRaw($storageColumn === null
                ? '0 AS storage_bytes'
                : "COALESCE(SUM({$storageColumn}), 0) AS storage_bytes");
            $queries[] = $query;
        }

        /** @var QueryBuilder $union */
        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        return DB::query()->fromSub($union, 'inventory_categories')->get()->groupBy('patient_id');
    }

    /**
     * @param  list<int>  $patientIds
     * @return Collection<int|string, \stdClass>
     */
    private function shareRows(array $patientIds): Collection
    {
        if ($patientIds === []) {
            return collect();
        }

        return DB::table('phr_patient_user_access as access')
            ->join('phr_patients as patient', 'patient.id', '=', 'access.patient_id')
            ->whereIn('access.patient_id', $patientIds)
            ->whereColumn('access.user_id', '<>', 'patient.owner_user_id')
            ->groupBy('access.patient_id')
            ->selectRaw('access.patient_id, COUNT(*) AS active_share_count, MAX(access.updated_at) AS last_updated_at')
            ->get()
            ->keyBy('patient_id');
    }

    private function immutableDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value);
    }

    private function latest(?CarbonImmutable $left, ?CarbonImmutable $right): ?CarbonImmutable
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        return $left->greaterThan($right) ? $left : $right;
    }
}

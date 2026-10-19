<?php

namespace App\Services\PHR\Identity;

use App\Models\PhrNativeRecordIdentity;
use App\Services\PHR\NativeBackup\PhrNativeIdentityRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Public ids for the records another application may refer to: patients,
 * documents and EOBs.
 *
 * A public id is the record's native identity (phr_native_record_identities),
 * the same opaque UUID a phr-native-v1 archive carries and a native restore
 * recreates beside the renumbered row, so it survives a restore while integer
 * ids do not. It is unique per record type across the installation. A
 * reference to a record is still the pair (patient public id, record public
 * id); integer ids remain the routing locators in API paths.
 *
 * Identities used to exist only once a backup had been built. They are now
 * created when the record is written ({@see HasPublicId}) and, for a row
 * written without model events, the first time the API returns it.
 */
final class PhrPublicIds
{
    public const string PATIENTS = 'phr_patients';

    public const string DOCUMENTS = 'phr_documents';

    public const string EOBS = 'phr_eobs';

    private const array TABLES = [self::PATIENTS, self::DOCUMENTS, self::EOBS];

    public function __construct(private readonly PhrNativeIdentityRepository $identities) {}

    /** The public id of a patient, document or EOB model (created if it has none yet). */
    public function assign(Model $record): string
    {
        $table = $record->getTable();
        $recordId = (int) $record->getKey();
        $patientId = $table === self::PATIENTS ? $recordId : (int) $record->getAttribute('patient_id');

        return $this->forRecords($patientId, $table, [$recordId])[$recordId];
    }

    public function forPatient(int $patientId): string
    {
        return $this->forRecords($patientId, self::PATIENTS, [$patientId])[$patientId];
    }

    /**
     * Each patient's public id; a patient's identity is filed under the patient itself.
     *
     * @param  iterable<int>  $patientIds
     * @return array<int, string>
     */
    public function forPatients(iterable $patientIds): array
    {
        $ids = $this->ids($patientIds);
        if ($ids === []) {
            return [];
        }
        $found = PhrNativeRecordIdentity::query()
            ->where('record_table', self::PATIENTS)
            ->whereIn('record_id', $ids)
            ->whereColumn('patient_id', 'record_id')
            ->pluck('native_id', 'record_id')
            ->all();
        $publicIds = [];
        foreach ($ids as $id) {
            $publicIds[$id] = isset($found[$id]) ? (string) $found[$id] : $this->forPatient($id);
        }

        return $publicIds;
    }

    /**
     * The public ids of one patient's records of one type, creating any that
     * are missing, in a single read for the ones that exist. An id that names
     * no row of this patient is left out.
     *
     * @param  iterable<int|null>  $recordIds
     * @return array<int, string>
     */
    public function forRecords(int $patientId, string $table, iterable $recordIds): array
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new LogicException("Records in [{$table}] have no public id.");
        }
        $ids = $this->ids($recordIds);
        if ($ids === []) {
            return [];
        }
        $found = PhrNativeRecordIdentity::query()
            ->where('patient_id', $patientId)
            ->where('record_table', $table)
            ->whereIn('record_id', $ids)
            ->pluck('native_id', 'record_id')
            ->all();
        $missing = array_values(array_diff($ids, array_keys($found)));
        if ($missing !== []) {
            // Only a row of this patient may be given an identity under it.
            $owned = $table === self::PATIENTS
                ? array_values(array_intersect($missing, [$patientId]))
                : DB::table($table)->where('patient_id', $patientId)->whereIn('id', $missing)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            foreach ($owned as $id) {
                $found[$id] = $this->identities->forRecord($patientId, $table, $id);
            }
        }
        $publicIds = [];
        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $publicIds[$id] = (string) $found[$id];
            }
        }

        return $publicIds;
    }

    /**
     * @param  iterable<int|null>  $ids
     * @return list<int>
     */
    private function ids(iterable $ids): array
    {
        $unique = [];
        foreach ($ids as $id) {
            if ($id !== null && (int) $id > 0) {
                $unique[(int) $id] = (int) $id;
            }
        }

        return array_values($unique);
    }
}

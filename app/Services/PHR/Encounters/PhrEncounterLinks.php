<?php

namespace App\Services\PHR\Encounters;

use App\Models\PhrDocument;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatientVital;
use App\Models\PhrProcedure;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The records made during an encounter: documents, lab results, vitals, medications and
 * procedures, each linked to at most one office visit through its own link table (#411).
 *
 * A link is evidence, like a fill's link to its pharmacy claim: writing or removing one
 * changes neither record's columns, so neither its version nor its review status moves.
 * A record's encounter_id is read from its link, and only while the linked visit still
 * stands (not deleted, not withdrawn by its source).
 */
final class PhrEncounterLinks
{
    public const string LINKED = 'linked';

    public const string UNLINKED = 'unlinked';

    public const string UNCHANGED = 'unchanged';

    /**
     * Link type => its link table, record column, record model and the name of its group in
     * a grouped encounter read. Types are the evidence-link target names.
     *
     * @var array<string, array{table: string, column: string, model: class-string<Model>, group: string}>
     */
    public const array TYPES = [
        'lab-result' => ['table' => 'phr_office_visit_lab_results', 'column' => 'lab_result_id', 'model' => PhrLabResult::class, 'group' => 'lab_results'],
        'vital' => ['table' => 'phr_office_visit_vitals', 'column' => 'vital_id', 'model' => PhrPatientVital::class, 'group' => 'vitals'],
        'medication' => ['table' => 'phr_office_visit_medications', 'column' => 'medication_id', 'model' => PhrMedication::class, 'group' => 'medications'],
        'procedure' => ['table' => 'phr_office_visit_procedures', 'column' => 'procedure_id', 'model' => PhrProcedure::class, 'group' => 'procedures'],
        'document' => ['table' => 'phr_office_visit_documents', 'column' => 'document_id', 'model' => PhrDocument::class, 'group' => 'documents'],
    ];

    /** Agent clinical resource id => link type. */
    public const array CLINICAL_RESOURCES = [
        'lab-results' => 'lab-result',
        'vitals' => 'vital',
        'medications' => 'medication',
        'procedures' => 'procedure',
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    /** @return array{table: string, column: string, model: class-string<Model>, group: string} */
    public static function definition(string $type): array
    {
        return self::TYPES[$type] ?? throw new InvalidArgumentException("Unknown encounter link type [{$type}].");
    }

    public static function typeForModel(Model $record): ?string
    {
        foreach (self::TYPES as $type => $definition) {
            if ($record instanceof $definition['model']) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Link a record to a visit on a person's (or their agent's) instruction. A record already
     * on another standing visit is a conflict: it belongs to one encounter, and moving it is
     * an unlink and a link. A link to a visit that no longer stands is replaced.
     */
    public function link(int $patientId, int $visitId, string $type, int $recordId): string
    {
        $definition = self::definition($type);

        return DB::transaction(function () use ($patientId, $visitId, $type, $recordId, $definition): string {
            $visit = PhrOfficeVisit::query()->where('patient_id', $patientId)->whereNull('retracted_at')->lockForUpdate()->find($visitId)
                ?? throw new NotFoundHttpException('Office visit not found.');
            if ($visit->review_status === PhrReviewStatus::REJECTED) {
                throw new ConflictHttpException('A rejected visit cannot take new links.');
            }
            $record = $this->standingRecord($patientId, $type, $recordId)
                ?? throw new NotFoundHttpException('Record not found.');
            if ($record->getAttribute('review_status') === PhrReviewStatus::REJECTED) {
                throw new ConflictHttpException('A rejected record cannot be linked.');
            }

            $existing = DB::table($definition['table'])->where($definition['column'], $recordId)->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->office_visit_id === $visitId) {
                    return self::UNCHANGED;
                }
                if ($this->visitStands((int) $existing->office_visit_id)) {
                    throw new ConflictHttpException(sprintf('This record is already linked to office visit %d. Unlink it there first.', (int) $existing->office_visit_id));
                }
                DB::table($definition['table'])->where('id', $existing->id)->delete();
            }
            DB::table($definition['table'])->insert([
                'patient_id' => $patientId,
                'office_visit_id' => $visitId,
                $definition['column'] => $recordId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return self::LINKED;
        });
    }

    public function unlink(int $patientId, int $visitId, string $type, int $recordId): string
    {
        $definition = self::definition($type);
        PhrOfficeVisit::query()->where('patient_id', $patientId)->findOrFail($visitId);

        $deleted = DB::table($definition['table'])
            ->where('patient_id', $patientId)
            ->where('office_visit_id', $visitId)
            ->where($definition['column'], $recordId)
            ->delete();

        return $deleted > 0 ? self::UNLINKED : self::UNCHANGED;
    }

    /**
     * Link a record an import read as part of this visit, unless it already belongs to an
     * encounter: an import never moves a record a person (or another source) placed.
     */
    public function linkIfFree(int $patientId, int $visitId, string $type, int $recordId): bool
    {
        $definition = self::definition($type);
        $existing = DB::table($definition['table'])->where($definition['column'], $recordId)->value('office_visit_id');
        if ($existing !== null) {
            return (int) $existing === $visitId;
        }

        return DB::table($definition['table'])->insertOrIgnore([
            'patient_id' => $patientId,
            'office_visit_id' => $visitId,
            $definition['column'] => $recordId,
            'created_at' => now(),
            'updated_at' => now(),
        ]) > 0;
    }

    /**
     * The standing visit each record is linked to.
     *
     * @param  iterable<int|string>  $recordIds
     * @return array<int, int> record id => office visit id
     */
    public function encounterIds(int $patientId, string $type, iterable $recordIds): array
    {
        $ids = [];
        foreach ($recordIds as $id) {
            $ids[] = (int) $id;
        }
        if ($ids === []) {
            return [];
        }
        $definition = self::definition($type);
        $rows = DB::table($definition['table'].' as links')
            ->join('phr_office_visits as visits', 'visits.id', '=', 'links.office_visit_id')
            ->where('links.patient_id', $patientId)
            ->whereIn('links.'.$definition['column'], array_values(array_unique($ids)))
            ->whereNull('visits.deleted_at')
            ->whereNull('visits.retracted_at')
            ->get(['links.'.$definition['column'].' as record_id', 'links.office_visit_id']);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->record_id] = (int) $row->office_visit_id;
        }

        return $map;
    }

    /**
     * How many standing records of each type each visit has linked.
     *
     * @param  iterable<int|string>  $visitIds
     * @return array<int, array<string, int>> visit id => group => count
     */
    public function counts(int $patientId, iterable $visitIds): array
    {
        $ids = [];
        foreach ($visitIds as $id) {
            $ids[] = (int) $id;
        }
        $counts = [];
        foreach ($ids as $id) {
            $counts[$id] = array_fill_keys(array_column(self::TYPES, 'group'), 0);
        }
        if ($ids === []) {
            return $counts;
        }
        foreach (self::TYPES as $type => $definition) {
            $rows = $this->standingLinks($patientId, $type)
                ->whereIn('links.office_visit_id', $ids)
                ->groupBy('links.office_visit_id')
                ->selectRaw('links.office_visit_id as visit_id, COUNT(*) as aggregate')
                ->get();
            foreach ($rows as $row) {
                $counts[(int) $row->visit_id][$definition['group']] = (int) $row->aggregate;
            }
        }

        return $counts;
    }

    /**
     * The standing records of one type linked to a visit, as an Eloquent query.
     *
     * @return Builder<Model>
     */
    public function linkedRecords(int $patientId, int $visitId, string $type): Builder
    {
        $definition = self::definition($type);
        /** @var class-string<Model> $model */
        $model = $definition['model'];
        $table = (new $model)->getTable();

        /** @var Builder<Model> $query */
        $query = $model::query()
            ->where($table.'.patient_id', $patientId)
            ->whereNull($table.'.retracted_at')
            ->whereIn($table.'.id', fn (QueryBuilder $links) => $links->select($definition['column'])->from($definition['table'])
                ->where('patient_id', $patientId)->where('office_visit_id', $visitId));

        return $query;
    }

    /**
     * Ids of the standing records of one type linked to a visit, as a raw query.
     */
    public function linkedIds(int $patientId, int $visitId, string $type): QueryBuilder
    {
        $definition = self::definition($type);
        /** @var class-string<Model> $model */
        $model = $definition['model'];
        $table = (new $model)->getTable();

        return DB::table($table)
            ->where('patient_id', $patientId)
            ->whereNull('deleted_at')
            ->whereNull('retracted_at')
            ->whereIn('id', fn (QueryBuilder $links) => $links->select($definition['column'])->from($definition['table'])
                ->where('patient_id', $patientId)->where('office_visit_id', $visitId));
    }

    /**
     * Every link of a patient whose visit is one of `$visitIds`: what an export may reference.
     *
     * @param  iterable<int|string>  $visitIds
     * @return array<string, array<int, int>> type => record id => office visit id
     */
    public function linksToVisits(int $patientId, iterable $visitIds): array
    {
        $ids = [];
        foreach ($visitIds as $id) {
            $ids[] = (int) $id;
        }
        $map = array_fill_keys(self::types(), []);
        if ($ids === []) {
            return $map;
        }
        foreach (self::TYPES as $type => $definition) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $rows = DB::table($definition['table'])
                    ->where('patient_id', $patientId)
                    ->whereIn('office_visit_id', $chunk)
                    ->get([$definition['column'].' as record_id', 'office_visit_id']);
                foreach ($rows as $row) {
                    $map[$type][(int) $row->record_id] = (int) $row->office_visit_id;
                }
            }
        }

        return $map;
    }

    private function standingLinks(int $patientId, string $type): QueryBuilder
    {
        $definition = self::definition($type);
        /** @var class-string<Model> $model */
        $model = $definition['model'];
        $table = (new $model)->getTable();

        return DB::table($definition['table'].' as links')
            ->join($table.' as records', 'records.id', '=', 'links.'.$definition['column'])
            ->where('links.patient_id', $patientId)
            ->whereNull('records.deleted_at')
            ->whereNull('records.retracted_at');
    }

    private function standingRecord(int $patientId, string $type, int $recordId): ?Model
    {
        $definition = self::definition($type);
        /** @var class-string<Model> $model */
        $model = $definition['model'];

        return $model::query()->where('patient_id', $patientId)->whereNull('retracted_at')->find($recordId);
    }

    private function visitStands(int $visitId): bool
    {
        return PhrOfficeVisit::query()->whereKey($visitId)->whereNull('retracted_at')->exists();
    }
}

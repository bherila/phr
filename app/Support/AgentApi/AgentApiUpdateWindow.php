<?php

namespace App\Support\AgentApi;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

final class AgentApiUpdateWindow
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $validated
     */
    public static function apply(Builder $query, array $validated, string $patientColumn): void
    {
        $model = $query->getModel();
        $recordTable = $model->getTable();
        $recordIdColumn = $model->qualifyColumn($model->getKeyName());
        $updatedAtColumn = $model->qualifyColumn('updated_at');

        if (isset($validated['updated_after'])) {
            $after = Carbon::parse((string) $validated['updated_after'])->utc();
            $query->where(function (Builder $window) use (
                $after,
                $patientColumn,
                $recordIdColumn,
                $recordTable,
                $updatedAtColumn,
            ): void {
                $window
                    ->where($updatedAtColumn, '>=', $after)
                    ->orWhereExists(function (QueryBuilder $identities) use (
                        $after,
                        $patientColumn,
                        $recordIdColumn,
                        $recordTable,
                    ): void {
                        self::restoredIdentityQuery(
                            $identities,
                            $patientColumn,
                            $recordIdColumn,
                            $recordTable,
                        )->where('agent_restore_identity.restored_at', '>=', $after);
                    });
            });
        }

        if (isset($validated['updated_before'])) {
            $before = Carbon::parse((string) $validated['updated_before'])->utc();
            $query
                ->where($updatedAtColumn, '<=', $before)
                ->whereNotExists(function (QueryBuilder $identities) use (
                    $before,
                    $patientColumn,
                    $recordIdColumn,
                    $recordTable,
                ): void {
                    self::restoredIdentityQuery(
                        $identities,
                        $patientColumn,
                        $recordIdColumn,
                        $recordTable,
                    )->where('agent_restore_identity.restored_at', '>', $before);
                });
        }
    }

    private static function restoredIdentityQuery(
        QueryBuilder $query,
        string $patientColumn,
        string $recordIdColumn,
        string $recordTable,
    ): QueryBuilder {
        return $query
            ->selectRaw('1')
            ->from('phr_native_record_identities as agent_restore_identity')
            ->where('agent_restore_identity.record_table', $recordTable)
            ->whereColumn('agent_restore_identity.patient_id', $patientColumn)
            ->whereColumn('agent_restore_identity.record_id', $recordIdColumn)
            ->whereNotNull('agent_restore_identity.restored_at');
    }
}

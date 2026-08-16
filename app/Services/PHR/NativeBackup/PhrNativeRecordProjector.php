<?php

namespace App\Services\PHR\NativeBackup;

final class PhrNativeRecordProjector
{
    public function __construct(private readonly PhrNativeRecordCodec $codec) {}

    /**
     * Rebuild the exact content projection used by phr-native-v1 without creating
     * identities. Restore previews are strictly read-only.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<int, string>>  $identitiesByRecordId
     * @return array<string, mixed>
     */
    public function project(int $patientId, string $table, array $definition, \stdClass $row, array $identitiesByRecordId): array
    {
        $relationships = $definition['relationships'] ?? [];
        $excluded = $definition['excluded_columns'] ?? [];
        $attributes = [];
        foreach ((array) $row as $column => $value) {
            if ($column === 'id' || $column === $definition['patient_column'] || isset($relationships[$column]) || isset($excluded[$column])) {
                continue;
            }
            $attributes[$column] = $this->codec->encodeValue($table, $column, $value);
        }

        $projectedRelationships = [];
        if (! ($definition['root'] ?? false)) {
            $rootNativeId = $identitiesByRecordId['phr_patients'][$patientId] ?? null;
            if (! is_string($rootNativeId)) {
                throw new NativeRestoreException('current_identity_missing');
            }
            $projectedRelationships[$definition['patient_column']] = [
                'kind' => 'record',
                'table' => 'phr_patients',
                'nativeId' => $rootNativeId,
            ];
        }

        foreach ($relationships as $column => $relationship) {
            $relatedId = $row->{$column};
            if ($relatedId === null) {
                $projectedRelationships[$column] = null;

                continue;
            }
            $nativeId = $identitiesByRecordId[$relationship['target']][(int) $relatedId] ?? null;
            if (! is_string($nativeId)) {
                throw new NativeRestoreException('current_identity_missing');
            }
            $projectedRelationships[$column] = [
                'kind' => $relationship['kind'],
                'table' => $relationship['target'],
                'nativeId' => $nativeId,
            ];
        }

        $nativeId = $identitiesByRecordId[$table][(int) $row->id] ?? null;
        if (! is_string($nativeId)) {
            throw new NativeRestoreException('current_identity_missing');
        }

        return $this->codec->record($nativeId, $attributes, $projectedRelationships);
    }
}

<?php

namespace App\Support\AgentApi;

use App\Services\PHR\Encounters\PhrEncounterLinks;
use App\Services\PHR\Encounters\PhrEpisodeLinks;
use Illuminate\Database\Eloquent\Model;

/**
 * What a clinical payload says about encounters (#411): the visit a lab result, vital,
 * medication or procedure is linked to (encounter_id), for a visit how many records of
 * each kind it has linked (linked_records), and for an episode (#415) the visits grouped
 * under it (encounter_ids). Links are evidence, not the record's own
 * columns, so this sits beside the resource and never enters its version. Reads and write
 * responses add it the same way, so a write answers what a later read returns.
 */
final readonly class AgentEncounterContext
{
    public function __construct(private PhrEncounterLinks $links, private PhrEpisodeLinks $episodes) {}

    /**
     * The context of a page of records, read in bulk.
     *
     * @param  list<int|string>  $ids
     * @return array<int, int|array<string, int>|list<int>>
     */
    public function forRecords(int $patientId, string $resource, array $ids): array
    {
        if ($resource === 'office-visits') {
            return $this->links->counts($patientId, $ids);
        }
        if ($resource === 'episodes') {
            return $this->episodes->encounterIds($patientId, $ids);
        }
        $type = PhrEncounterLinks::CLINICAL_RESOURCES[$resource] ?? null;

        return $type === null ? [] : $this->links->encounterIds($patientId, $type, $ids);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, int|array<string, int>|list<int>>  $context  from forRecords()
     * @return array<string, mixed>
     */
    public function add(array $payload, string $resource, int $id, array $context): array
    {
        if ($resource === 'office-visits') {
            $payload['linked_records'] = $context[$id] ?? [];
        } elseif ($resource === 'episodes') {
            $payload['encounter_ids'] = $context[$id] ?? [];
        } elseif (isset(PhrEncounterLinks::CLINICAL_RESOURCES[$resource])) {
            $payload['encounter_id'] = $context[$id] ?? null;
        }

        return $payload;
    }

    /**
     * One record's payload with its context.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function with(array $payload, string $resource, Model $record): array
    {
        $id = (int) $record->getKey();

        return $this->add($payload, $resource, $id, $this->forRecords((int) $record->getAttribute('patient_id'), $resource, [$id]));
    }
}

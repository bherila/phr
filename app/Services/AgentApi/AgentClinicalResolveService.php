<?php

namespace App\Services\AgentApi;

use App\Models\PhrPatient;
use App\Support\AgentApi\AgentApiClientIdentity;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Answers "have I written this before?" for the calling OAuth client.
 *
 * Lookup is on the same composite identity the upsert writes -- patient,
 * client-namespaced import source, external ID -- so a client can never
 * observe, and therefore never target, another integration's records. That
 * makes this a resolver for a client's own mirror, not a patient-wide search.
 */
final readonly class AgentClinicalResolveService
{
    public function __construct(private AgentClinicalRecordVersion $versions) {}

    /**
     * @param  list<string>  $externalIds
     * @return array{
     *     resolved: array<string, array{id: int, version: string, review_status: string|null, updated_at: string|null}>,
     *     unresolved: list<string>
     * }
     */
    public function resolve(
        PhrPatient $patient,
        AgentApiClientIdentity $client,
        string $resource,
        array $externalIds,
    ): array {
        $definition = AgentClinicalResourceCatalog::definition($resource);
        $modelClass = $definition['model'] ?? null;
        abort_unless(is_string($modelClass) && isset($definition['write_rules']), 404);

        $records = $modelClass::query()
            ->where('patient_id', $patient->id)
            ->where('import_source', $client->importSource())
            ->whereIn('external_id', $externalIds)
            ->get();

        $resolved = [];
        foreach ($records as $record) {
            /** @var Model $record */
            $resolved[(string) $record->getAttribute('external_id')] = [
                'id' => (int) $record->getAttribute('id'),
                // The same opaque version the write endpoints compare against,
                // so a caller can decide skip-or-update without a second read.
                'version' => $this->versions->for($record),
                'review_status' => $this->stringOrNull($record->getAttribute('review_status')),
                'updated_at' => $this->timestamp($record->getAttribute('updated_at')),
            ];
        }

        return [
            'resolved' => $resolved,
            'unresolved' => array_values(array_filter(
                $externalIds,
                static fn (string $externalId): bool => ! isset($resolved[$externalId]),
            )),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** Matches the `toDateTimeString()` format every clinical resource emits. */
    private function timestamp(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : null;
    }
}

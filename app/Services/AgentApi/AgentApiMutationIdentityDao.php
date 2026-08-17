<?php

namespace App\Services\AgentApi;

use App\Models\AgentApiMutationIdentity;
use App\Support\AgentApi\AgentApiClientIdentity;
use App\Support\AgentApi\AgentApiSecretDigest;

/** Typed persistence boundary for client-scoped mutation retries. */
final class AgentApiMutationIdentityDao
{
    public function find(
        int $patientId,
        AgentApiClientIdentity $client,
        string $operation,
        string $externalId,
    ): ?AgentApiMutationIdentity {
        return AgentApiMutationIdentity::query()
            ->where('patient_id', $patientId)
            ->where('oauth_client_id', $client->id)
            ->where('operation', $operation)
            ->whereIn('external_id_hash', $this->externalIdHashes(
                $patientId,
                $client,
                $operation,
                $externalId,
            ))
            ->lockForUpdate()
            ->first();
    }

    public function remember(
        int $patientId,
        AgentApiClientIdentity $client,
        string $operation,
        string $externalId,
        string $requestHash,
        string $targetTable,
        int $targetId,
    ): AgentApiMutationIdentity {
        return AgentApiMutationIdentity::query()->create([
            ...$this->identity($patientId, $client, $operation, $externalId),
            'request_hash' => $requestHash,
            'target_table' => $targetTable,
            'target_id' => $targetId,
        ]);
    }

    /** @return array{patient_id: int, oauth_client_id: string, operation: string, external_id_hash: string} */
    private function identity(
        int $patientId,
        AgentApiClientIdentity $client,
        string $operation,
        string $externalId,
    ): array {
        return [
            'patient_id' => $patientId,
            'oauth_client_id' => $client->id,
            'operation' => $operation,
            'external_id_hash' => $this->externalIdHashes(
                $patientId,
                $client,
                $operation,
                $externalId,
            )[0],
        ];
    }

    /** @return non-empty-list<string> */
    private function externalIdHashes(
        int $patientId,
        AgentApiClientIdentity $client,
        string $operation,
        string $externalId,
    ): array {
        return AgentApiSecretDigest::candidates(
            'mutation-external-id',
            implode("\0", [$patientId, $client->id, $operation, $externalId]),
        );
    }
}

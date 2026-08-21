<?php

namespace App\Support\AgentApi;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Shared envelope for agent mutation responses.
 *
 * A write scope authorizes mutation, not retrieval. Callers holding the
 * matching read scope receive the full resource; write-only callers receive a
 * receipt that identifies what changed without disclosing record contents. The
 * new opaque version stays in the receipt either way, so a write-only client
 * can still retry or chain an update after a lost response.
 */
final class AgentMutationResponse
{
    /**
     * @param  callable(): array<string, mixed>  $fullPayload
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function payload(
        Request $request,
        string $resourceType,
        int $patientId,
        string $outcome,
        string $readScope,
        Model $record,
        callable $fullPayload,
        ?string $version = null,
        array $extra = [],
    ): array {
        $readable = (bool) $request->user('api')?->tokenCan($readScope);

        return [
            'resource_type' => $resourceType,
            'patient_id' => $patientId,
            ...$extra,
            'outcome' => $outcome,
            ...($version === null ? [] : ['version' => $version]),
            'data' => $readable ? $fullPayload() : self::receipt($record),
            'receipt_only' => ! $readable,
        ];
    }

    /**
     * Identity and review state only: enough to correlate the mutation with the
     * caller's own records, and nothing that was not already known to it.
     *
     * @return array<string, mixed>
     */
    private static function receipt(Model $record): array
    {
        $attributes = $record->attributesToArray();
        $receipt = ['id' => $record->getKey()];
        foreach (['patient_id', 'health_log_id', 'review_status'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $receipt[$key] = $attributes[$key];
            }
        }

        return $receipt;
    }
}

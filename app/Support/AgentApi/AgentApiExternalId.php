<?php

namespace App\Support\AgentApi;

final class AgentApiExternalId
{
    public static function withoutDocumentHash(?string $externalId): ?string
    {
        if ($externalId === null) {
            return null;
        }

        // Meritain's internal deduplication key embeds the source PDF SHA-256.
        // It is not an interoperability identifier, so neither clinical nor
        // document metadata responses may expose it as an external ID.
        return preg_match('/^eob:meritain:[a-f0-9]{64}$/iD', $externalId) === 1
            ? null
            : $externalId;
    }
}

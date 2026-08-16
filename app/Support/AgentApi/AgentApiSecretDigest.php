<?php

namespace App\Support\AgentApi;

use LogicException;

/** Domain-separated keyed digests for low-entropy agent metadata. */
final class AgentApiSecretDigest
{
    public static function for(string $domain, string $value): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new LogicException('APP_KEY is required to digest agent API metadata.');
        }

        return hash_hmac('sha256', $domain."\0".$value, $key);
    }
}

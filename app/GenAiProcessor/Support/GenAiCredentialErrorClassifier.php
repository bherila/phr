<?php

namespace App\GenAiProcessor\Support;

class GenAiCredentialErrorClassifier
{
    /**
     * Returns true only for provider credential failures that require the user
     * to replace the saved API key or token.
     */
    public static function isInvalidCredential(?string $provider, \Throwable $e): bool
    {
        $message = self::normalize($e->getMessage());

        if (str_contains($message, 'invalid api credentials')) {
            return true;
        }

        return match ($provider) {
            'gemini' => self::containsAny($message, [
                'api key not valid',
                'invalid api key',
                'invalid_api_key',
            ]),
            'anthropic' => self::containsAny($message, [
                'invalid api key',
                'invalid x-api-key',
                'invalid_api_key',
            ]),
            'bedrock' => self::containsAny($message, [
                'invalid api key',
                'api key format',
                'security token included in the request is invalid',
                'invalid security token',
                'invalidclienttokenid',
                'unrecognizedclientexception',
            ]),
            default => self::containsAny($message, [
                'invalid api key',
                'invalid_api_key',
                'api key not valid',
                'api key format',
            ]),
        };
    }

    private static function normalize(string $message): string
    {
        return strtolower(str_replace(['-', '_'], ' ', $message));
    }

    /**
     * @param  list<string>  $needles
     */
    private static function containsAny(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, self::normalize($needle))) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace App\Console\Commands\Phr;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Laravel\Passport\Passport;

#[Signature('phr:agent-api:verify-oauth-keys')]
#[Description('Validate that the persistent Passport signing keys parse and match')]
final class AgentApiVerifyOAuthKeysCommand extends BasePhrCommand
{
    public function handle(): int
    {
        $privatePath = Passport::keyPath('oauth-private.key');
        $publicPath = Passport::keyPath('oauth-public.key');

        if (! is_readable($privatePath) || ! is_readable($publicPath)) {
            return $this->invalidPair();
        }

        $privatePem = file_get_contents($privatePath);
        $publicPem = file_get_contents($publicPath);
        if (! is_string($privatePem) || ! is_string($publicPem)) {
            return $this->invalidPair();
        }

        $privateKey = openssl_pkey_get_private($privatePem);
        $publicKey = openssl_pkey_get_public($publicPem);
        if ($privateKey === false || $publicKey === false) {
            return $this->invalidPair();
        }

        $privateDetails = openssl_pkey_get_details($privateKey);
        $publicDetails = openssl_pkey_get_details($publicKey);
        if (! is_array($privateDetails)
            || ! is_array($publicDetails)
            || ! hash_equals((string) $privateDetails['key'], (string) $publicDetails['key'])) {
            return $this->invalidPair();
        }

        $this->info('OAuth signing key pair is valid.');

        return self::SUCCESS;
    }

    private function invalidPair(): int
    {
        // Never print paths, parser diagnostics, or key material in public CI logs.
        $this->error('OAuth signing key pair is missing, invalid, or mismatched.');

        return self::FAILURE;
    }
}

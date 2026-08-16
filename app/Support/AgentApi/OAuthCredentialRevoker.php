<?php

namespace App\Support\AgentApi;

use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;

final class OAuthCredentialRevoker
{
    /**
     * Revoke every credential Passport has issued or prepared for an account.
     *
     * Authorization codes are independent of access-token families, so they
     * must be revoked even when an account has never completed a token exchange.
     * Each table is guarded independently to keep user lifecycle operations safe
     * while Passport's migrations are being installed on a fresh deployment.
     */
    public function revokeForUserIdentifier(int|string $userIdentifier): void
    {
        $connection = config('passport.connection');
        $passportSchema = Schema::connection(is_string($connection) ? $connection : null);

        if ($passportSchema->hasTable('oauth_auth_codes')) {
            Passport::authCode()->newQuery()
                ->where('user_id', $userIdentifier)
                ->update(['revoked' => true]);
        }

        if (! $passportSchema->hasTable('oauth_access_tokens')) {
            return;
        }

        $tokenIds = Passport::token()->newQuery()
            ->where('user_id', $userIdentifier)
            ->pluck('id');

        if ($tokenIds->isEmpty()) {
            return;
        }

        if ($passportSchema->hasTable('oauth_refresh_tokens')) {
            Passport::refreshToken()->newQuery()
                ->whereIn('access_token_id', $tokenIds)
                ->update(['revoked' => true]);
        }

        Passport::token()->newQuery()
            ->whereIn('id', $tokenIds)
            ->update(['revoked' => true]);
    }
}

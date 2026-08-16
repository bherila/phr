<?php

namespace App\Support\AgentApi;

use App\Models\OAuthTokenFamily;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

final class OAuthCredentialRevoker
{
    /**
     * Revoke one rotation family without affecting newer grants for the account.
     *
     * A stale refresh credential can outlive a disable/re-enable transition. It
     * must clean up its own obsolete family, but it must not become a way to
     * disconnect independently authorized clients from the re-enabled account.
     */
    public function revokeFamilyForAccessToken(Token $accessToken): void
    {
        $connection = config('passport.connection');
        DB::connection(is_string($connection) ? $connection : null)
            ->transaction(function () use ($accessToken): void {
                $family = $this->lockFamilyForAccessToken($accessToken);
                $family->forceFill(['revoked' => true])->save();
                $familyTokens = Passport::token()->newQuery()
                    ->where('user_id', $family->user_id)
                    ->where('client_id', $family->client_id)
                    ->where(function ($query) use ($family): void {
                        $query->where('oauth_family_id', $family->id)
                            ->orWhere('id', $family->id);
                    })
                    ->lockForUpdate()
                    ->get();
                $familyTokenIds = $familyTokens->pluck('id');

                Passport::refreshToken()->newQuery()
                    ->whereIn('access_token_id', $familyTokenIds)
                    ->update(['revoked' => true]);
                Passport::token()->newQuery()
                    ->whereIn('id', $familyTokenIds)
                    ->update(['revoked' => true]);
            }, 1);
    }

    /**
     * Lock the stable family root before any refresh row. Refresh, replay, and
     * disconnect all use this order so a concurrent rotation either finishes
     * first and is included in cleanup, or waits before it can issue a successor.
     */
    public function lockFamilyForAccessToken(Token $accessToken): OAuthTokenFamily
    {
        $familyIdentifier = is_string($accessToken->oauth_family_id)
            ? $accessToken->oauth_family_id
            : $accessToken->id;
        OAuthTokenFamily::query()->firstOrCreate([
            'id' => $familyIdentifier,
        ], [
            'user_id' => $accessToken->user_id,
            'client_id' => $accessToken->client_id,
            'oauth_security_version' => $accessToken->oauth_security_version,
            'revoked' => false,
            'expires_at' => now()->addDays(AgentApiTokenPolicy::REFRESH_TOKEN_LIFETIME_DAYS),
        ]);

        return OAuthTokenFamily::query()
            ->whereKey($familyIdentifier)
            ->lockForUpdate()
            ->firstOrFail();
    }

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

        if ($passportSchema->hasTable('oauth_token_families')) {
            OAuthTokenFamily::query()
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

<?php

namespace App\Support\AgentApi;

use App\Models\User;
use BWH\Auth\OAuth\Credentials\CredentialOwnerPolicy;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Only an account that may sign in (User::canLogin(): the `user` or `admin`
 * role) may hold OAuth credentials. The auth package consults this when every
 * authorization code, access token and refresh token is issued, exchanged,
 * refreshed and used, and PHR's own token repositories and middleware ask the
 * same question through User::mayHoldOAuthCredentials().
 */
final class AccountCredentialOwnerPolicy implements CredentialOwnerPolicy
{
    public function mayHoldCredentials(Authenticatable $owner): bool
    {
        return $owner instanceof User && $owner->canLogin();
    }
}

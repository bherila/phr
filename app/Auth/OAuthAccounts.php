<?php

namespace App\Auth;

use BWH\Auth\OAuth\Login\RelyingPartyAccounts;
use BWH\Auth\OAuth\OAuthIdentity;

class OAuthAccounts extends RelyingPartyAccounts
{
    /**
     * A first sign-in creates an account with no patient access: the role is the only
     * thing PHR adds to the shared defaults.
     */
    public function newAccountAttributes(OAuthIdentity $identity): array
    {
        return [...parent::newAccountAttributes($identity), 'user_role' => 'user'];
    }
}

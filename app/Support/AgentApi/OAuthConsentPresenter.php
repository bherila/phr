<?php

namespace App\Support\AgentApi;

use Illuminate\Http\Request;
use Laravel\Passport\Client;

final class OAuthConsentPresenter
{
    public function redirectUri(Request $request, Client $client): ?string
    {
        $requested = $request->query('redirect_uri');
        if (is_string($requested) && $requested !== '') {
            return $requested;
        }

        $redirectUris = $client->getAttribute('redirect_uris');
        if (! is_array($redirectUris)) {
            return null;
        }

        $registered = array_values(array_filter(
            $redirectUris,
            static fn (mixed $uri): bool => is_string($uri) && $uri !== '',
        ));

        return count($registered) === 1 ? $registered[0] : null;
    }
}

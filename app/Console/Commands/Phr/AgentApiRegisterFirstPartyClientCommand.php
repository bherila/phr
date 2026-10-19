<?php

namespace App\Console\Commands\Phr;

use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Credentials\ApiCredentialService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * Register a first-party application (for example the finance app that reads
 * EOBs) as a confidential, authorization-code-only OAuth client with no owner
 * and a scope ceiling. It still asks the person for consent once; listing its
 * id in AGENT_API_FIRST_PARTY_OAUTH_CLIENTS lets later authorizations within
 * that consent skip the screen. The secret is shown once and stored hashed.
 */
#[Signature('phr:agent-api:register-first-party-client
    {name : Name shown on the consent screen}
    {--redirect-uri=* : Exact callback URL (repeatable; https, or http on loopback)}
    {--scope=* : A scope the client may request (repeatable; REST scopes only)}')]
#[Description('Register a confidential first-party OAuth client for the authorization-code grant')]
final class AgentApiRegisterFirstPartyClientCommand extends BasePhrCommand
{
    public function handle(ClientRepository $clients): int
    {
        $name = trim((string) $this->argument('name'));
        $redirectUris = array_values(array_unique(array_filter(
            array_map(static fn (mixed $uri): string => trim((string) $uri), (array) $this->option('redirect-uri')),
            static fn (string $uri): bool => $uri !== '',
        )));
        $scopes = array_values(array_unique(array_filter(
            array_map(static fn (mixed $scope): string => trim((string) $scope), (array) $this->option('scope')),
            static fn (string $scope): bool => $scope !== '',
        )));

        if ($name === '') {
            $this->error('Give the client a name.');

            return self::FAILURE;
        }
        if ($redirectUris === []) {
            $this->error('Give at least one --redirect-uri.');

            return self::FAILURE;
        }
        foreach ($redirectUris as $uri) {
            if (! ApiCredentialService::validRedirectUri($uri)) {
                $this->error('Redirect URIs must be https, or http on a loopback address, with no fragment or credentials.');

                return self::FAILURE;
            }
        }
        if ($scopes === []) {
            $this->error('Give at least one --scope.');

            return self::FAILURE;
        }
        $unknown = array_diff($scopes, AgentApiScopes::restIds());
        if ($unknown !== []) {
            $this->error('Unknown or non-REST scopes: '.implode(', ', $unknown).'.');

            return self::FAILURE;
        }
        if (Passport::client()->newQuery()->where('name', $name)->where('revoked', false)->exists()) {
            $this->error('An active client with that name already exists.');

            return self::FAILURE;
        }

        $client = Passport::client()->getConnection()->transaction(function () use ($clients, $name, $redirectUris, $scopes) {
            // No owner: a person-registered app is never first-party. The
            // repository grants authorization_code and refresh_token only.
            $client = $clients->createAuthorizationCodeGrantClient($name, $redirectUris, true, null);
            sort($scopes);
            $client->forceFill(['scopes' => $scopes])->save();

            return $client;
        });

        $this->info('Registered first-party client "'.$name.'".');
        $this->line('Client ID:     '.$client->getKey());
        $this->line('Client secret: '.$client->plainSecret);
        $this->line('Scopes:        '.implode(' ', $scopes));
        $this->newLine();
        $this->comment('The secret is shown only now; only its hash is kept.');
        $this->comment('Add the client id to AGENT_API_FIRST_PARTY_OAUTH_CLIENTS to let it skip the consent screen after the first approval.');

        return self::SUCCESS;
    }
}

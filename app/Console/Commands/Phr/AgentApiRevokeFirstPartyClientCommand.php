<?php

namespace App\Console\Commands\Phr;

use App\Models\OAuthClientConsent;
use App\Models\OAuthTokenFamily;
use App\Support\AgentApi\OAuthCredentialRevoker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Laravel\Passport\Passport;

#[Signature('phr:agent-api:revoke-first-party-client {client_id : Exact first-party client ID to disable permanently}')]
#[Description('Disable one operator-registered OAuth client and revoke its grants for every person')]
final class AgentApiRevokeFirstPartyClientCommand extends BasePhrCommand
{
    public function handle(OAuthCredentialRevoker $revoker): int
    {
        $clientId = (string) $this->argument('client_id');
        $client = Passport::client()->newQuery()->find($clientId);
        $grants = $client?->getAttribute('grant_types');
        $registeredColumn = config('bherila-auth.oauth_server.dynamic_clients.registered_at_column', 'dynamically_registered_at');
        if ($client === null || ! $client->firstParty() || ! $client->confidential()
            || ! is_array($grants) || ! in_array('authorization_code', $grants, true)
            || array_diff($grants, ['authorization_code', 'refresh_token']) !== []
            || (is_string($registeredColumn) && $client->getAttribute($registeredColumn) !== null)) {
            $this->error('No eligible operator-registered first-party client has that ID.');

            return self::FAILURE;
        }

        // Commit the client fence before the per-person cleanup. New issuance
        // refuses this registration even if cleanup is interrupted; retrying
        // continues cleanup without ever enabling the client again.
        $client->forceFill(['revoked' => true])->save();

        $userIds = OAuthClientConsent::query()->where('client_id', $clientId)->pluck('user_id')
            ->merge(OAuthTokenFamily::query()->where('client_id', $clientId)->pluck('user_id'))
            ->merge(Passport::authCode()->newQuery()->where('client_id', $clientId)->pluck('user_id'))
            ->merge(Passport::token()->newQuery()->where('client_id', $clientId)->pluck('user_id'));
        $refresh = Passport::refreshToken();
        $schema = $refresh->getConnection()->getSchemaBuilder();
        if ($schema->hasColumn($refresh->getTable(), 'client_id')
            && $schema->hasColumn($refresh->getTable(), 'provider_user_id')) {
            $userIds = $userIds->merge($refresh->newQuery()->where('client_id', $clientId)->pluck('provider_user_id'));
        }
        foreach ($userIds->unique()->sort() as $userId) {
            if (! is_int($userId) && ! is_string($userId)) {
                continue;
            }
            // Preserve the existing consent/version, code, family and refresh
            // lock order. Never revoke the person's grants for another client.
            $revoker->revokeClientConsent($userId, $clientId);
        }

        $this->info('First-party client disabled; its grants are revoked. The registration is retained.');
        $this->comment('Remove its ID from AGENT_API_FIRST_PARTY_OAUTH_CLIENTS if listed. Register a new client to reconnect.');

        return self::SUCCESS;
    }
}

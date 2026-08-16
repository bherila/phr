<?php

namespace App\Console\Commands\Phr;

use App\Models\OAuthTokenFamily;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

#[Signature('phr:agent-api:prune-oauth-credentials')]
#[Description('Delete closed OAuth families while retaining active-family replay history')]
final class AgentApiPruneOAuthCredentialsCommand extends BasePhrCommand
{
    public function handle(): int
    {
        $connection = config('passport.connection');
        [$deletedFamilies, $deletedClients] = DB::connection(is_string($connection) ? $connection : null)
            ->transaction(function (): array {
                $families = OAuthTokenFamily::query()
                    ->where('revoked', true)
                    ->orWhere('expires_at', '<=', now())
                    ->lockForUpdate()
                    ->get();

                foreach ($families as $family) {
                    $tokenIds = Passport::token()->newQuery()
                        ->where('oauth_family_id', $family->id)
                        ->orWhere('id', $family->id)
                        ->pluck('id');
                    Passport::refreshToken()->newQuery()
                        ->whereIn('access_token_id', $tokenIds)
                        ->delete();
                    Passport::token()->newQuery()
                        ->whereIn('id', $tokenIds)
                        ->delete();
                    $family->delete();
                }

                Passport::authCode()->newQuery()
                    ->where('revoked', true)
                    ->orWhere('expires_at', '<=', now())
                    ->delete();

                $unusedClients = Passport::client()->newQuery()
                    ->whereNotNull('dynamically_registered_at')
                    ->where('dynamically_registered_at', '<=', now()->subDay())
                    ->whereNull('first_authorized_at')
                    ->whereDoesntHave('authCodes')
                    ->whereDoesntHave('tokens')
                    ->get();
                foreach ($unusedClients as $client) {
                    $client->delete();
                }

                return [$families->count(), $unusedClients->count()];
            }, 1);

        $this->info("Pruned {$deletedFamilies} closed OAuth token family/families and {$deletedClients} unused dynamic client(s).");

        return self::SUCCESS;
    }
}

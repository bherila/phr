<?php

namespace App\Console\Commands\Phr;

use App\Models\OAuthTokenFamily;
use App\Support\AgentApi\OAuthDynamicClientDao;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

#[Signature('phr:agent-api:prune-oauth-credentials')]
#[Description('Delete closed OAuth families while retaining active-family replay history')]
final class AgentApiPruneOAuthCredentialsCommand extends BasePhrCommand
{
    private const int DYNAMIC_CLIENT_BATCH_SIZE = 100;

    public function handle(OAuthDynamicClientDao $dynamicClients): int
    {
        $connection = config('passport.connection');
        $database = DB::connection(is_string($connection) ? $connection : null);
        $deletedFamilies = $database->transaction(function (): int {
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

            return $families->count();
        }, 1);

        $registeredBefore = now()->subDay();
        $deletedClients = 0;
        do {
            [$candidateCount, $deletedInBatch] = $database->transaction(function () use ($dynamicClients, $registeredBefore): array {
                $clientIds = $dynamicClients->staleUnusedIdBatch(
                    $registeredBefore,
                    self::DYNAMIC_CLIENT_BATCH_SIZE,
                );
                $deleted = 0;
                foreach ($clientIds as $clientId) {
                    // Recheck every predicate while holding the same client-row
                    // lock acquired by first authorization. A consent approval
                    // that wins the lock makes this lookup return null; pruning
                    // that wins makes approval fail before issuing an orphan code.
                    $client = $dynamicClients->lockUnusedForPruning($clientId, $registeredBefore);
                    if ($client === null) {
                        continue;
                    }
                    $client->delete();
                    $deleted++;
                }

                return [count($clientIds), $deleted];
            }, 1);
            $deletedClients += $deletedInBatch;
        } while ($candidateCount > 0);

        $this->info("Pruned {$deletedFamilies} closed OAuth token family/families and {$deletedClients} unused dynamic client(s).");

        return self::SUCCESS;
    }
}

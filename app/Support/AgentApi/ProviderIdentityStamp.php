<?php

namespace App\Support\AgentApi;

use BWH\Auth\OAuth\Server\ProviderIdentityTokens;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The auth package's provider identity stamp on credentials PHR's own token
 * repositories persist: the provider subject and login generation of the
 * session that authorized them, which tokens minted from them inherit.
 */
final class ProviderIdentityStamp
{
    /**
     * The stamp columns for a credential issued to this user now. Empty while
     * enforcement is off and the session has no baseline; with enforcement on,
     * a bound account without a verified session cannot be issued one.
     *
     * @return array<string, string|int>
     */
    public static function forIssue(Model $model, string|int|null $userId): array
    {
        $stamp = app(ProviderIdentityTokens::class)->stampForIssue(request(), $userId);
        if ($stamp === null) {
            return [];
        }
        if (! self::hasColumns($model)) {
            if (ProviderIdentityTokens::enabled()) {
                throw new RuntimeException("The {$model->getTable()} provider identity columns are required.");
            }

            return [];
        }

        return ProviderIdentityTokens::attributes($stamp);
    }

    public static function hasColumns(Model $model): bool
    {
        return $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), ProviderIdentityTokens::GENERATION_COLUMN);
    }
}

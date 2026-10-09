<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Delegated access configuration and accounts for the adapter's tests.
 *
 * Account #1 is seeded first so it really is the protected bootstrap account.
 */
trait SeedsDelegatedAccessAccounts
{
    protected const DELEGATED_PROVIDER = 'example-provider';

    protected const DELEGATED_ISSUER = 'https://identity.example.test';

    protected const DELEGATED_APPLICATION = 'example-application';

    protected function configureDelegatedAccess(array $overrides = []): void
    {
        config([
            'bherila-auth.oauth_client.provider' => self::DELEGATED_PROVIDER,
            'bherila-auth.oauth_client.base_url' => self::DELEGATED_ISSUER,
            'bherila-auth.delegated_access' => array_merge(config('bherila-auth.delegated_access'), [
                'issuer' => self::DELEGATED_ISSUER,
                'application' => self::DELEGATED_APPLICATION,
                'oauth_provider' => self::DELEGATED_PROVIDER,
            ], $overrides),
        ]);
    }

    /**
     * A bound account with the given raw role list. Pass `$provider` to bind it elsewhere, or null
     * for a row that is not bound at all.
     */
    protected function delegatedAccount(string $subject, string $roles, ?string $provider = self::DELEGATED_PROVIDER, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        // Written with the query builder so the factory's role handling cannot normalise it.
        DB::table('users')->where('id', $user->id)->update([
            'user_role' => $roles,
            'oauth_provider' => $provider,
            'oauth_subject' => $provider === null ? null : $subject,
        ]);

        return $user->refresh();
    }

    protected function rawRoles(User|int $user): string
    {
        return (string) DB::table('users')->where('id', $user instanceof User ? $user->id : $user)->value('user_role');
    }
}

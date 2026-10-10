<?php

namespace Tests\Concerns;

use App\Models\User;
use BWH\Auth\OAuth\DelegatedAccess\ApplicationAccessAdapter;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedAccessException;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedContract;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedRequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Delegated access configuration and accounts for the adapter's tests.
 *
 * Account #1 is seeded first so it really is the protected bootstrap account. `assertRefused()` needs
 * the package's AssertsDelegatedAccessAdapter in the same test.
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

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function assertRefused(string $outcome, string $actor, array $payload): void
    {
        try {
            $this->delegatedAccessCall($actor, $payload);
        } catch (DelegatedAccessException $refusal) {
            $this->assertSame($outcome, $refusal->outcome);

            return;
        }

        $this->fail("Accepted {$payload['operation']}; expected {$outcome}");
    }

    /**
     * Call the adapter as the endpoint does, with a request context whose `jti` is known.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function callWithJti(string $jti, array $payload): array
    {
        $this->app->instance(DelegatedRequestContext::class, new DelegatedRequestContext(
            self::DELEGATED_ISSUER, 'subject-manager', self::DELEGATED_APPLICATION, $jti, (string) $payload['operation'],
            is_string($payload['operation_id'] ?? null) ? $payload['operation_id'] : null,
        ));
        try {
            $fields = $this->app->make(ApplicationAccessAdapter::class)->handle('subject-manager', $payload);
        } finally {
            $this->app->forgetInstance(DelegatedRequestContext::class);
        }

        return (new DelegatedContract)->adapterAnswer($fields, self::DELEGATED_APPLICATION, (string) $payload['operation'], $payload['subject'] ?? null);
    }
}

<?php

namespace Tests\Feature\DelegatedAccess;

use App\Models\User;
use App\Services\Accounts\PhrApplicationAccessAdapter;
use BWH\Auth\Models\AuthAuditLog;
use BWH\Auth\OAuth\DelegatedAccess\ApplicationAccessAdapter;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedAccessException;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedContract;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedRefusal;
use BWH\Auth\OAuth\PendingAccount;
use BWH\Auth\Testing\AssertsDelegatedAccessAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsDelegatedAccessAccounts;
use Tests\TestCase;

/**
 * PHR's own rules behind delegated access: the protected bootstrap account, self-demotion, the
 * last administrator, accounts that cannot sign in, roles other than admin, provisioning and audit.
 */
class DelegatedAccessRulesTest extends TestCase
{
    use AssertsDelegatedAccessAdapter;
    use SeedsDelegatedAccessAccounts;

    private User $bootstrap;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDelegatedAccess();

        $this->bootstrap = $this->delegatedAccount('subject-bootstrap', 'admin');
        $this->manager = $this->delegatedAccount('subject-manager', 'admin');
        $this->assertSame(1, $this->bootstrap->id);
    }

    protected function delegatedAccessTruth(string $subject): array
    {
        $user = User::query()->where('oauth_provider', self::DELEGATED_PROVIDER)->where('oauth_subject', $subject)->first();

        return ['application_admin' => (bool) $user?->hasRole('admin'), 'workspaces' => []];
    }

    protected function delegatedAccessManager(): string
    {
        return 'subject-manager';
    }

    public function test_the_bootstrap_account_is_reported_not_editable_and_never_demoted(): void
    {
        // Account #1 is an administrator whatever its stored roles say.
        DB::table('users')->where('id', 1)->update(['user_role' => 'user']);

        $state = $this->delegatedAccessRead('subject-manager', 'subject-bootstrap');
        $this->assertTrue($state['access']['application_admin']);
        $this->assertFalse($state['allowed_edits']['application_admin']);

        $this->assertRefused(DelegatedRefusal::NOT_AUTHORIZED, 'subject-manager', $this->demote('subject-bootstrap', $state['revision']));
        $this->assertSame('user', $this->rawRoles(1));
    }

    public function test_an_administrator_cannot_demote_themselves(): void
    {
        $state = $this->delegatedAccessRead('subject-manager', 'subject-manager');
        $this->assertTrue($state['access']['application_admin']);
        $this->assertFalse($state['allowed_edits']['application_admin']);

        $this->assertRefused(DelegatedRefusal::NOT_AUTHORIZED, 'subject-manager', $this->demote('subject-manager', $state['revision']));
        $this->assertSame('admin', $this->rawRoles($this->manager));

        // Demoting another administrator is allowed.
        $other = $this->delegatedAccount('subject-other-admin', 'admin');
        $state = $this->delegatedAccessRead('subject-manager', 'subject-other-admin');
        $this->assertTrue($state['allowed_edits']['application_admin']);
        $this->delegatedAccessCall('subject-manager', $this->demote('subject-other-admin', $state['revision']));
        $this->assertFalse($other->refresh()->hasRole('admin'));
        $this->assertTrue($this->manager->refresh()->hasRole('admin'));
    }

    public function test_the_last_administrator_who_can_sign_in_is_not_demoted(): void
    {
        // Account #1 is an administrator but not bound to the sign-in provider, so it cannot sign
        // in: the manager is the only administrator who can.
        DB::table('users')->where('id', 1)->update(['oauth_provider' => null, 'oauth_subject' => null]);

        $state = $this->delegatedAccessRead('subject-manager', 'subject-manager');
        $this->assertFalse($state['allowed_edits']['application_admin']);
        $this->assertRefused(DelegatedRefusal::NOT_AUTHORIZED, 'subject-manager', $this->demote('subject-manager', $state['revision']));
        $this->assertSame('admin', $this->rawRoles($this->manager));
    }

    public function test_an_account_that_cannot_sign_in_is_not_promoted(): void
    {
        $disabled = $this->delegatedAccount('subject-disabled', 'reviewer');
        $this->assertFalse($disabled->canLogin());

        $state = $this->delegatedAccessRead('subject-manager', 'subject-disabled');
        $this->assertFalse($state['access']['application_admin']);
        $this->assertFalse($state['allowed_edits']['application_admin']);

        $this->assertRefused(DelegatedRefusal::NOT_AUTHORIZED, 'subject-manager', $this->promote('subject-disabled', $state['revision']));
        $this->assertSame('reviewer', $this->rawRoles($disabled));
        $this->assertFalse($disabled->refresh()->canLogin());
    }

    public function test_other_roles_survive_promotion_and_demotion(): void
    {
        $person = $this->delegatedAccount('subject-person', 'User, beta-tester');

        $state = $this->delegatedAccessRead('subject-manager', 'subject-person');
        $this->assertFalse($state['access']['application_admin']);
        $this->assertTrue($state['allowed_edits']['application_admin']);

        $promoted = $this->delegatedAccessCall('subject-manager', $this->promote('subject-person', $state['revision']));
        $this->assertTrue($promoted['access']['application_admin']);
        $this->assertNotSame($state['revision'], $promoted['revision']);
        $this->assertSame('User, beta-tester,admin', $this->rawRoles($person));

        $demoted = $this->delegatedAccessCall('subject-manager', $this->demote('subject-person', $promoted['revision']));
        $this->assertFalse($demoted['access']['application_admin']);
        $this->assertSame('User, beta-tester', $this->rawRoles($person));
        $this->assertTrue($person->refresh()->hasRole('beta-tester'));
    }

    public function test_demoting_an_administrator_whose_only_role_is_admin_leaves_an_ordinary_account(): void
    {
        $person = $this->delegatedAccount('subject-person', 'reviewer,Admin');

        $state = $this->delegatedAccessRead('subject-manager', 'subject-person');
        $this->delegatedAccessCall('subject-manager', $this->demote('subject-person', $state['revision']));

        $this->assertSame('reviewer,user', $this->rawRoles($person));
        $person->refresh();
        $this->assertFalse($person->hasRole('admin'));
        $this->assertTrue($person->canLogin());
    }

    public function test_an_update_resending_the_current_state_changes_nothing(): void
    {
        $person = $this->delegatedAccount('subject-person', 'User, beta-tester');
        $state = $this->delegatedAccessRead('subject-manager', 'subject-person');

        $this->delegatedAccessCall('subject-manager', $this->demote('subject-person', $state['revision']));

        $this->assertSame('User, beta-tester', $this->rawRoles($person));
        $this->assertSame(0, AuthAuditLog::query()->count());
    }

    public function test_provisioning_binds_a_new_account_to_the_issuer_and_exact_subject(): void
    {
        $unprovisioned = $this->delegatedAccessCall('subject-manager', ['operation' => 'read', 'subject' => 'subject-new']);
        $this->assertFalse($unprovisioned['provisioned']);
        $this->assertTrue($unprovisioned['allowed_edits']['provision']);

        $created = $this->delegatedAccessCall('subject-manager', $this->provision('subject-new', admin: false, displayName: 'Example Person'));
        $this->assertTrue($created['provisioned']);
        $this->assertSame(['application_admin' => false, 'workspaces' => []], $created['access']);
        $this->assertFalse($created['allowed_edits']['provision']);

        $user = User::query()->where('oauth_provider', self::DELEGATED_PROVIDER)->where('oauth_subject', 'subject-new')->sole();
        $this->assertSame(PendingAccount::email(self::DELEGATED_PROVIDER, 'subject-new'), $user->email);
        $this->assertSame(PendingAccount::name('Example Person', 'subject-new'), $user->name);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('user', $this->rawRoles($user));
        $this->assertTrue($user->canLogin());

        $this->delegatedAccessCall('subject-manager', $this->provision('subject-new-admin', admin: true));
        $admin = User::query()->where('oauth_subject', 'subject-new-admin')->sole();
        $this->assertSame('user,admin', $this->rawRoles($admin));
        $this->assertSame(PendingAccount::name('Invited account', 'subject-new-admin'), $admin->name);
    }

    public function test_provisioning_an_already_bound_subject_is_a_conflict(): void
    {
        $existing = $this->delegatedAccount('subject-existing', 'User, beta-tester');
        $before = $existing->only(['name', 'email', 'user_role']);

        $this->assertRefused(DelegatedRefusal::REVISION_CONFLICT, 'subject-manager', $this->provision('subject-existing', admin: true));
        $this->assertSame($before, $existing->refresh()->only(['name', 'email', 'user_role']));
        $this->assertSame('User, beta-tester', $this->rawRoles($existing));

        $this->delegatedAccessCall('subject-manager', $this->provision('subject-twice', admin: false));
        $this->assertRefused(DelegatedRefusal::REVISION_CONFLICT, 'subject-manager', $this->provision('subject-twice', admin: true));
        $this->assertSame('user', $this->rawRoles(User::query()->where('oauth_subject', 'subject-twice')->sole()));
    }

    public function test_provisioning_never_adopts_an_existing_account_by_address_or_name(): void
    {
        // An unbound row holding the placeholder address the new account would get.
        $holder = User::factory()->create(['email' => PendingAccount::email(self::DELEGATED_PROVIDER, 'subject-new'), 'user_role' => 'user']);
        $this->assertRefused(DelegatedRefusal::REVISION_CONFLICT, 'subject-manager', $this->provision('subject-new', admin: true));
        $holder->refresh();
        $this->assertNull($holder->oauth_subject);
        $this->assertSame('user', $this->rawRoles($holder));

        // An unbound row with the same name as the display name is left alone; a new row is created.
        $legacy = User::factory()->create(['name' => 'Example Person', 'email' => 'person@example.test', 'user_role' => 'user']);
        $this->delegatedAccessCall('subject-manager', $this->provision('subject-other', admin: false, displayName: 'Example Person'));
        $this->assertNull($legacy->refresh()->oauth_subject);
        $this->assertNotSame($legacy->id, User::query()->where('oauth_subject', 'subject-other')->sole()->id);
    }

    public function test_the_first_sign_in_of_a_provisioned_account_is_the_same_account_with_real_details(): void
    {
        $this->delegatedAccessCall('subject-manager', $this->provision('subject-new', admin: true, displayName: 'Example Person'));
        $provisioned = User::query()->where('oauth_subject', 'subject-new')->sole();

        Config::set('bherila-auth.oauth_client', [
            ...config('bherila-auth.oauth_client'),
            'client_id' => 'phr-client',
            'client_secret' => 'phr-secret',
            'redirect_uri' => 'http://localhost/oauth/callback',
        ]);
        Http::fake(static fn (Request $request) => match ($request->url()) {
            self::DELEGATED_ISSUER.'/oauth/token' => Http::response(['access_token' => 'test-access-token']),
            self::DELEGATED_ISSUER.'/api/oauth/user' => Http::response(['sub' => 'subject-new', 'name' => 'Real Name', 'email' => 'real@example.test', 'apps' => []]),
            default => Http::response([], 404),
        });

        $this->withSession(['oauth.login.state' => 'expected-state', 'oauth.login.code_verifier' => str_repeat('v', 64)])
            ->get('/oauth/callback?state=expected-state&code=authorization-code')
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($provisioned);
        $provisioned->refresh();
        $this->assertSame(['Real Name', 'real@example.test'], [$provisioned->name, $provisioned->email]);
        $this->assertTrue($provisioned->hasRole('admin'));
        $this->assertSame(1, User::query()->where('oauth_subject', 'subject-new')->count());
    }

    public function test_each_change_is_audited_with_the_request_jti(): void
    {
        $person = $this->delegatedAccount('subject-person', 'user');
        $state = $this->callWithJti('jti-read', ['operation' => 'read', 'subject' => 'subject-person']);

        $promote = $this->promote('subject-person', $state['revision']);
        $provision = $this->provision('subject-new', admin: false);
        $this->callWithJti('jti-promote', $promote);
        $this->callWithJti('jti-provision', $provision);

        $rows = AuthAuditLog::query()->orderBy('id')->get();
        $this->assertSame([PhrApplicationAccessAdapter::EVENT_ADMIN_GRANTED, PhrApplicationAccessAdapter::EVENT_PROVISIONED], $rows->pluck('event')->all());
        $this->assertSame(['jti-promote', 'jti-provision'], $rows->pluck('metadata.jti')->all());
        $this->assertSame([$promote['operation_id'], $provision['operation_id']], $rows->pluck('metadata.operation_id')->all());
        $this->assertSame([$this->manager->id, $this->manager->id], $rows->pluck('acting_user_id')->all());
        $this->assertSame($person->id, $rows[0]->user_id);
        $this->assertSame(['subject-person', 'subject-new'], $rows->pluck('metadata.subject')->all());
        $this->assertSame([self::DELEGATED_ISSUER], $rows->pluck('metadata.issuer')->unique()->values()->all());
    }

    public function test_subjects_lists_bound_accounts_a_page_at_a_time(): void
    {
        $this->delegatedAccount('subject-a', 'user', attributes: ['name' => 'Person A']);
        $this->delegatedAccount('subject-unbound', 'user', provider: null);
        $this->delegatedAccount('subject-elsewhere', 'user', provider: 'another-provider');
        $this->delegatedAccount('subject-b', '', attributes: ['name' => 'Person B']);

        $first = $this->delegatedAccessCall('subject-manager', ['operation' => 'subjects', 'limit' => 2]);
        $this->assertSame(['subject-bootstrap', 'subject-manager'], array_column($first['subjects'], 'subject'));
        $this->assertNotNull($first['next_cursor']);

        $second = $this->delegatedAccessCall('subject-manager', ['operation' => 'subjects', 'limit' => 2, 'cursor' => $first['next_cursor']]);
        $this->assertSame([['subject' => 'subject-a', 'label' => 'Person A'], ['subject' => 'subject-b', 'label' => 'Person B']],
            array_map(static fn (array $entry): array => ['subject' => $entry['subject'], 'label' => $entry['label']], $second['subjects']));
        $this->assertNull($second['next_cursor']);

        // A cursor is bound to the actor that received it (the package's own refusal, so called directly).
        $this->delegatedAccount('subject-other-admin', 'admin');
        try {
            $this->app->make(ApplicationAccessAdapter::class)->handle('subject-other-admin', ['operation' => 'subjects', 'limit' => 2, 'cursor' => $first['next_cursor']]);
            $this->fail('Accepted another actor\'s cursor');
        } catch (DelegatedAccessException $refusal) {
            $this->assertSame('invalid_cursor', $refusal->outcome);
        }
    }

    public function test_an_actor_demoted_since_reading_cannot_update(): void
    {
        $this->delegatedAccount('subject-person', 'user');
        $state = $this->delegatedAccessRead('subject-manager', 'subject-person');
        DB::table('users')->where('id', $this->manager->id)->update(['user_role' => 'user']);

        $this->assertRefused(DelegatedRefusal::NOT_AUTHORIZED, 'subject-manager', $this->promote('subject-person', $state['revision']));
        $this->assertSame('user', $this->rawRoles(User::query()->where('oauth_subject', 'subject-person')->sole()));
    }

    /**
     * @return array<string, mixed>
     */
    private function promote(string $subject, string $revision): array
    {
        return ['operation' => 'update', 'subject' => $subject, 'expected_revision' => $revision, 'access' => ['application_admin' => true, 'workspaces' => []],
            'operation_id' => DelegatedContract::operationId()];
    }

    /**
     * @return array<string, mixed>
     */
    private function demote(string $subject, string $revision): array
    {
        return ['operation' => 'update', 'subject' => $subject, 'expected_revision' => $revision, 'access' => ['application_admin' => false, 'workspaces' => []],
            'operation_id' => DelegatedContract::operationId()];
    }

    /**
     * @return array<string, mixed>
     */
    private function provision(string $subject, bool $admin, ?string $displayName = null): array
    {
        return ['operation' => 'update', 'subject' => $subject, 'expected_revision' => null,
            'access' => ['application_admin' => $admin, 'workspaces' => []], 'operation_id' => DelegatedContract::operationId()]
            + ($displayName !== null ? ['display_name' => $displayName] : []);
    }
}

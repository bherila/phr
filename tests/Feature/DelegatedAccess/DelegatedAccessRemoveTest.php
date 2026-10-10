<?php

namespace Tests\Feature\DelegatedAccess;

use App\Models\PhrPatient;
use App\Models\User;
use App\Services\Accounts\PhrApplicationAccessAdapter;
use BWH\Auth\Models\AuthAuditLog;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedContract;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedRefusal;
use BWH\Auth\Testing\AssertsDelegatedAccessAdapter;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsDelegatedAccessAccounts;
use Tests\TestCase;

/**
 * `remove` in PHR: the administrator role is the whole of what delegation manages, so removal is a
 * demotion under the same rule as an update, and the account, its sign-in, its other roles and its
 * health records stay.
 */
class DelegatedAccessRemoveTest extends TestCase
{
    use AssertsDelegatedAccessAdapter;
    use SeedsDelegatedAccessAccounts;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDelegatedAccess();

        $bootstrap = $this->delegatedAccount('subject-bootstrap', 'admin');
        $this->manager = $this->delegatedAccount('subject-manager', 'admin');
        $this->assertSame(1, $bootstrap->id);
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

    public function test_removing_an_administrator_whose_only_role_is_admin_leaves_an_ordinary_account_with_its_records(): void
    {
        $person = $this->delegatedAccount('subject-person', 'admin');
        $patient = PhrPatient::query()->create(['owner_user_id' => $person->id, 'display_name' => 'Synthetic Patient', 'relationship' => 'self']);

        $state = $this->delegatedAccessRead('subject-manager', 'subject-person');
        $this->assertTrue($state['allowed_edits']['remove']);

        $removed = $this->delegatedAccessCall('subject-manager', $this->removal('subject-person', $state['revision']));
        $this->assertTrue($removed['provisioned']);
        $this->assertSame(['application_admin' => false, 'workspaces' => []], $removed['access']);
        $this->assertNotSame($state['revision'], $removed['revision']);
        $this->assertTrue($removed['allowed_edits']['remove']);

        $this->assertSame('user', $this->rawRoles($person));
        $person->refresh();
        $this->assertTrue($person->canLogin());
        $this->assertSame([self::DELEGATED_PROVIDER, 'subject-person'], [$person->oauth_provider, $person->oauth_subject]);
        $this->assertTrue(PhrPatient::query()->whereKey($patient->id)->where('owner_user_id', $person->id)->exists());
    }

    public function test_removal_keeps_every_role_but_admin(): void
    {
        $person = $this->delegatedAccount('subject-person', 'User, beta-tester,Admin');
        $other = $this->delegatedAccount('subject-other', 'reviewer,admin');

        $this->delegatedAccessCall('subject-manager', $this->removal('subject-person', $this->delegatedAccessRead('subject-manager', 'subject-person')['revision']));
        $this->delegatedAccessCall('subject-manager', $this->removal('subject-other', $this->delegatedAccessRead('subject-manager', 'subject-other')['revision']));

        $this->assertSame('User, beta-tester', $this->rawRoles($person));
        $this->assertSame('reviewer,user', $this->rawRoles($other));
        $this->assertTrue($other->refresh()->canLogin());
    }

    public function test_removing_an_account_that_cannot_sign_in_is_a_no_op_that_never_re_enables_it(): void
    {
        $disabled = $this->delegatedAccount('subject-disabled', 'reviewer');
        $version = $disabled->oauth_security_version;

        $state = $this->delegatedAccessRead('subject-manager', 'subject-disabled');
        $this->assertFalse($state['allowed_edits']['application_admin']);
        $this->assertTrue($state['allowed_edits']['remove']);

        $again = $this->delegatedAccessCall('subject-manager', $this->removal('subject-disabled', $state['revision']));
        $this->assertSame($state['revision'], $again['revision']);
        $this->assertSame('reviewer', $this->rawRoles($disabled));
        $disabled->refresh();
        $this->assertFalse($disabled->canLogin());
        $this->assertSame($version, $disabled->oauth_security_version);
        $this->assertSame(0, AuthAuditLog::query()->count());
    }

    public function test_removal_invalidates_the_persons_agent_credentials_and_a_no_op_does_not(): void
    {
        $person = $this->delegatedAccount('subject-person', 'user,admin');
        $version = $person->oauth_security_version;

        $removed = $this->delegatedAccessCall('subject-manager', $this->removal('subject-person', $this->delegatedAccessRead('subject-manager', 'subject-person')['revision']));
        $this->assertSame($version + 1, $person->refresh()->oauth_security_version);

        $this->delegatedAccessCall('subject-manager', $this->removal('subject-person', $removed['revision']));
        $this->assertSame($version + 1, $person->refresh()->oauth_security_version);
    }

    public function test_each_removal_is_audited_with_the_request_jti_and_operation_id_and_a_no_op_or_refusal_is_not(): void
    {
        $person = $this->delegatedAccount('subject-person', 'user,admin');
        $state = $this->callWithJti('jti-read', ['operation' => 'read', 'subject' => 'subject-person']);

        $removal = $this->removal('subject-person', $state['revision']);
        $removed = $this->callWithJti('jti-remove', $removal);
        $this->callWithJti('jti-again', $this->removal('subject-person', $removed['revision']));
        $this->assertRefused(DelegatedRefusal::NOT_AUTHORIZED, 'subject-manager', $this->removal('subject-bootstrap', $this->delegatedAccessRead('subject-manager', 'subject-bootstrap')['revision']));

        $row = AuthAuditLog::query()->sole();
        $this->assertSame(PhrApplicationAccessAdapter::EVENT_REMOVED, $row->event);
        $this->assertSame([$person->id, $this->manager->id], [$row->user_id, $row->acting_user_id]);
        $this->assertSame('delegated_access', $row->auth_method);
        $this->assertSame('jti-remove', $row->metadata['jti']);
        $this->assertSame($removal['operation_id'], $row->metadata['operation_id']);
        $this->assertSame('subject-person', $row->metadata['subject']);
        $this->assertSame(self::DELEGATED_ISSUER, $row->metadata['issuer']);
    }

    public function test_the_bootstrap_account_is_never_removed_whatever_its_stored_roles(): void
    {
        DB::table('users')->where('id', 1)->update(['user_role' => 'user']);

        $state = $this->delegatedAccessRead('subject-manager', 'subject-bootstrap');
        $this->assertTrue($state['access']['application_admin']);
        $this->assertFalse($state['allowed_edits']['remove']);

        $this->assertRefused(DelegatedRefusal::NOT_AUTHORIZED, 'subject-manager', $this->removal('subject-bootstrap', $state['revision']));
        $this->assertSame('user', $this->rawRoles(1));
    }

    public function test_an_administrator_cannot_remove_themselves(): void
    {
        $state = $this->delegatedAccessRead('subject-manager', 'subject-manager');
        $this->assertFalse($state['allowed_edits']['remove']);

        $this->assertRefused(DelegatedRefusal::NOT_AUTHORIZED, 'subject-manager', $this->removal('subject-manager', $state['revision']));
        $this->assertSame('admin', $this->rawRoles($this->manager));
    }

    public function test_the_last_administrator_who_can_sign_in_is_not_removed(): void
    {
        // Account #1 cannot sign in (not bound to the provider), so the manager is the last
        // administrator who can; and another administrator who has lost sign-in is no substitute.
        DB::table('users')->where('id', 1)->update(['oauth_provider' => null, 'oauth_subject' => null]);
        $this->delegatedAccount('subject-unbound-admin', 'admin', provider: null);

        $state = $this->delegatedAccessRead('subject-manager', 'subject-manager');
        $this->assertFalse($state['allowed_edits']['remove']);
        $this->assertRefused(DelegatedRefusal::NOT_AUTHORIZED, 'subject-manager', $this->removal('subject-manager', $state['revision']));
        $this->assertSame('admin', $this->rawRoles($this->manager));
    }

    public function test_a_stale_revision_or_an_unprovisioned_subject_is_refused(): void
    {
        $person = $this->delegatedAccount('subject-person', 'user,admin');
        $state = $this->delegatedAccessRead('subject-manager', 'subject-person');
        DB::table('users')->where('id', $person->id)->update(['user_role' => 'user,admin,reviewer']);

        $this->assertRefused(DelegatedRefusal::REVISION_CONFLICT, 'subject-manager', $this->removal('subject-person', $state['revision']));
        $this->assertSame('user,admin,reviewer', $this->rawRoles($person));

        $this->assertRefused(DelegatedRefusal::NOT_PROVISIONED, 'subject-manager', $this->removal('subject-unknown', hash('sha256', 'any')));
    }

    public function test_removal_finds_only_the_account_bound_to_the_exact_subject_never_one_by_address(): void
    {
        // An unbound administrator, and one bound under another provider name, each sharing what a
        // careless lookup might match on: the subject string as an address, or the subject itself.
        $unbound = $this->delegatedAccount('subject-legacy', 'user,admin', provider: null, attributes: ['email' => 'subject-legacy@example.test']);
        $elsewhere = $this->delegatedAccount('subject-legacy', 'user,admin', provider: 'another-provider');

        $this->assertRefused(DelegatedRefusal::NOT_PROVISIONED, 'subject-manager', $this->removal('subject-legacy', hash('sha256', 'any')));
        $this->assertRefused(DelegatedRefusal::NOT_PROVISIONED, 'subject-manager', $this->removal('subject-legacy@example.test', hash('sha256', 'any')));
        $this->assertSame('user,admin', $this->rawRoles($unbound));
        $this->assertSame('user,admin', $this->rawRoles($elsewhere));
    }

    public function test_an_actor_demoted_since_reading_cannot_remove(): void
    {
        $person = $this->delegatedAccount('subject-person', 'user,admin');
        $state = $this->delegatedAccessRead('subject-manager', 'subject-person');
        DB::table('users')->where('id', $this->manager->id)->update(['user_role' => 'user']);

        $this->assertRefused(DelegatedRefusal::NOT_AUTHORIZED, 'subject-manager', $this->removal('subject-person', $state['revision']));
        $this->assertSame('user,admin', $this->rawRoles($person));
    }

    /**
     * @return array<string, mixed>
     */
    private function removal(string $subject, string $revision): array
    {
        return ['operation' => 'remove', 'subject' => $subject, 'expected_revision' => $revision, 'operation_id' => DelegatedContract::operationId()];
    }
}

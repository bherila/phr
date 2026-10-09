<?php

namespace Tests\Feature\DelegatedAccess;

use App\Models\User;
use BWH\Auth\Testing\AssertsDelegatedAccessAdapter;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsDelegatedAccessAccounts;
use Tests\TestCase;

/**
 * The package's normative update semantics, checked against PHR's real adapter and users table.
 *
 * PHR is account-only, so the membership checks return rather than check, and every answer must
 * report no workspaces.
 */
class DelegatedAccessConformanceTest extends TestCase
{
    use AssertsDelegatedAccessAdapter;
    use SeedsDelegatedAccessAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDelegatedAccess();

        $this->delegatedAccount('subject-bootstrap', 'admin');
        $this->delegatedAccount('subject-manager', 'admin');
        $this->delegatedAccount('subject-target', 'user');
        $this->delegatedAccount('subject-ordinary', 'user');
    }

    protected function delegatedAccessTruth(string $subject): array
    {
        $row = DB::table('users')->where('oauth_provider', self::DELEGATED_PROVIDER)->where('oauth_subject', $subject)->first();
        $roles = array_map(static fn (string $role): string => strtolower(trim($role)), explode(',', (string) ($row->user_role ?? '')));

        return [
            'application_admin' => $row !== null && ((int) $row->id === 1 || in_array('admin', $roles, true)),
            'workspaces' => [],
        ];
    }

    protected function delegatedAccessManager(): string
    {
        return 'subject-manager';
    }

    public function test_the_adapter_meets_the_account_only_update_semantics(): void
    {
        $this->assertSame(1, User::query()->where('oauth_subject', 'subject-bootstrap')->value('id'));
        $this->assertTrue($this->delegatedAccessAccountOnly());

        $this->assertDelegatedActorRefusedEverywhere('subject-ordinary', 'subject-target');
        $this->assertDelegatedProtectedMembershipsHold('subject-manager', 'subject-target');
        // Refused targets: the actor's own account, and the protected bootstrap account.
        $this->assertDelegatedApplicationAdminFollowsAllowedEdits('subject-manager', 'subject-manager');
        $this->assertDelegatedApplicationAdminFollowsAllowedEdits('subject-manager', 'subject-bootstrap');
        $this->assertDelegatedApplicationAdminFollowsAllowedEdits('subject-manager', 'subject-target');
        $this->assertDelegatedStaleRevisionRefused('subject-manager', 'subject-target');
        $this->assertDelegatedStaleRevisionRefused('subject-manager', 'subject-bootstrap');
        $this->assertDelegatedUnadvertisedRoleRefused('subject-manager', 'subject-target');
        $this->assertDelegatedUpdateKeepsUnseenMemberships('subject-manager', 'subject-target');
        $this->assertDelegatedUpdateKeepsUnseenMemberships('subject-manager', 'subject-bootstrap');
    }

    public function test_accounts_that_are_not_active_bound_administrators_are_refused_everything(): void
    {
        // An administrator row not bound to the sign-in provider, one bound under another provider
        // name, a disabled account, and a subject with no account at all.
        $this->delegatedAccount('subject-unbound-admin', 'admin', provider: null);
        $this->delegatedAccount('subject-elsewhere', 'admin', provider: 'another-provider');
        $this->delegatedAccount('subject-disabled', '');

        foreach (['subject-unbound-admin', 'subject-elsewhere', 'subject-disabled', 'subject-unknown'] as $actor) {
            $this->assertDelegatedActorRefusedEverywhere($actor, 'subject-target');
        }
    }
}

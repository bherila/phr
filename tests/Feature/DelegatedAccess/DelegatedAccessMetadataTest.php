<?php

namespace Tests\Feature\DelegatedAccess;

use App\Models\User;
use BWH\Auth\Models\AuthAuditLog;
use BWH\Auth\Testing\AssertsDelegatedAccessAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsDelegatedAccessAccounts;
use Tests\TestCase;

/**
 * The read-only observations PHR reports: when an account was created, and its latest successful
 * sign-in from the authentication audit log. Never part of the revision.
 */
class DelegatedAccessMetadataTest extends TestCase
{
    use AssertsDelegatedAccessAdapter;
    use SeedsDelegatedAccessAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDelegatedAccess();

        $this->delegatedAccount('subject-bootstrap', 'admin');
        $this->delegatedAccount('subject-manager', 'admin');
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

    public function test_a_state_reports_when_the_account_was_created_and_its_latest_successful_sign_in(): void
    {
        $person = $this->delegatedAccount('subject-person', 'user');
        $other = $this->delegatedAccount('subject-other', 'user');
        DB::table('users')->where('id', $person->id)->update(['created_at' => '2026-01-02 03:04:05']);

        $this->signIn($person, '2026-03-01 10:00:00', AuthAuditLog::EVENT_LOGIN_SUCCEEDED);
        $this->signIn($person, '2026-04-01 11:30:00', AuthAuditLog::EVENT_PASSKEY_LOGIN_SUCCEEDED);
        // Not sign-ins: a failure, a later unrelated event, and someone else's sign-in.
        $this->signIn($person, '2026-05-01 00:00:00', AuthAuditLog::EVENT_LOGIN_FAILED, succeeded: false);
        $this->signIn($person, '2026-05-02 00:00:00', AuthAuditLog::EVENT_LOGGED_OUT);
        $this->signIn($other, '2026-06-01 00:00:00', AuthAuditLog::EVENT_LOGIN_SUCCEEDED);

        $state = $this->delegatedAccessRead('subject-manager', 'subject-person');
        $this->assertSame('2026-01-02T03:04:05+00:00', $state['provisioned_at']);
        $this->assertSame('2026-04-01T11:30:00+00:00', $state['last_seen_at']);
        $this->assertArrayNotHasKey('first_sign_in_at', $state);
    }

    public function test_an_account_that_never_signed_in_has_no_last_sign_in_and_an_unprovisioned_subject_no_metadata(): void
    {
        $this->delegatedAccount('subject-person', 'user');

        $state = $this->delegatedAccessRead('subject-manager', 'subject-person');
        $this->assertNotNull($state['provisioned_at']);
        $this->assertNull($state['last_seen_at']);

        $unprovisioned = $this->delegatedAccessCall('subject-manager', ['operation' => 'read', 'subject' => 'subject-new']);
        $this->assertSame([], array_intersect_key($unprovisioned, array_flip(['provisioned_at', 'first_sign_in_at', 'last_seen_at'])));
    }

    public function test_listing_entries_carry_the_same_observations(): void
    {
        $person = $this->delegatedAccount('subject-person', 'user', attributes: ['name' => 'Person']);
        DB::table('users')->where('id', $person->id)->update(['created_at' => '2026-01-02 03:04:05']);
        $this->signIn($person, '2026-04-01 11:30:00', AuthAuditLog::EVENT_LOGIN_SUCCEEDED);

        $entries = collect($this->delegatedAccessCall('subject-manager', ['operation' => 'subjects'])['subjects'])->keyBy('subject');
        $this->assertSame(
            ['subject' => 'subject-person', 'label' => 'Person', 'provisioned_at' => '2026-01-02T03:04:05+00:00', 'last_seen_at' => '2026-04-01T11:30:00+00:00'],
            $entries['subject-person'],
        );
        $this->assertNull($entries['subject-manager']['last_seen_at']);
        $this->assertSame($entries->all(), collect($this->delegatedAccessCall('subject-manager', ['operation' => 'subjects', 'query' => 'example'])['subjects'])->keyBy('subject')->all());
    }

    public function test_a_sign_in_does_not_change_the_revision(): void
    {
        $person = $this->delegatedAccount('subject-person', 'user');
        $before = $this->delegatedAccessRead('subject-manager', 'subject-person');

        $this->signIn($person, Carbon::now()->subMinute()->toDateTimeString(), AuthAuditLog::EVENT_LOGIN_SUCCEEDED);

        $after = $this->delegatedAccessRead('subject-manager', 'subject-person');
        $this->assertNotNull($after['last_seen_at']);
        $this->assertSame($before['revision'], $after['revision']);
    }

    private function signIn(User $user, string $at, string $event, bool $succeeded = true): void
    {
        $row = AuthAuditLog::query()->create(['user_id' => $user->id, 'event' => $event, 'auth_method' => 'oauth', 'succeeded' => $succeeded]);
        DB::table($row->getTable())->where('id', $row->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }
}

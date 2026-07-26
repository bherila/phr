<?php

namespace Tests\Feature\PHR;

use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Regression: the MCP bearer token must have a lifecycle.
 *
 * `mcp_api_key` was a permanent, full-account credential with no expiry, no
 * last-used signal, and no issue/revoke path short of a manual UPDATE. A token
 * that cannot expire is one nobody notices has been stolen.
 */
class McpTokenLifecycleTest extends TestCase
{
    public function test_issued_token_authenticates_a_device_request(): void
    {
        [$user, $patientId] = $this->userWithPatient();
        $token = $user->issueMcpToken(30);

        $this->summaryRequest($token, $patientId)->assertOk();
    }

    public function test_expired_token_is_rejected(): void
    {
        [$user, $patientId] = $this->userWithPatient();
        $token = $user->issueMcpToken(30);

        $this->summaryRequest($token, $patientId)->assertOk();

        Carbon::setTestNow(now()->addDays(31));

        try {
            $this->summaryRequest($token, $patientId)
                ->assertUnauthorized()
                ->assertExactJson(['message' => 'Unauthenticated.']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_token_without_an_expiry_fails_closed(): void
    {
        [$user, $patientId] = $this->userWithPatient();

        // A row shaped like the old schema: a hash present, no expiry recorded.
        $user->forceFill([
            'mcp_api_key' => User::hashMcpToken('legacy-eternal-token'),
            'mcp_api_key_expires_at' => null,
        ])->save();

        $this->summaryRequest('legacy-eternal-token', $patientId)->assertUnauthorized();
    }

    public function test_revoked_token_stops_working_immediately(): void
    {
        [$user, $patientId] = $this->userWithPatient();
        $token = $user->issueMcpToken(30);

        $this->summaryRequest($token, $patientId)->assertOk();

        $this->artisan('mcp:token:revoke', ['email' => $user->email])->assertExitCode(0);

        $this->summaryRequest($token, $patientId)->assertUnauthorized();
        $this->assertNull($user->fresh()?->mcp_api_key);
    }

    public function test_reissuing_invalidates_the_previous_token(): void
    {
        [$user, $patientId] = $this->userWithPatient();
        $firstToken = $user->issueMcpToken(30);
        $secondToken = $user->issueMcpToken(30);

        $this->assertNotSame($firstToken, $secondToken);
        $this->summaryRequest($firstToken, $patientId)->assertUnauthorized();
        $this->summaryRequest($secondToken, $patientId)->assertOk();
    }

    public function test_token_is_stored_hashed_and_never_serialised(): void
    {
        $user = $this->createUser();
        $token = $user->issueMcpToken(30);

        $this->assertNotSame($token, $user->mcp_api_key);
        $this->assertSame(hash('sha256', $token), $user->mcp_api_key);
        $this->assertArrayNotHasKey('mcp_api_key', $user->toArray());
    }

    public function test_token_columns_are_not_mass_assignable(): void
    {
        $user = $this->createUser();

        $user->fill([
            'mcp_api_key' => User::hashMcpToken('attacker-chosen'),
            'mcp_api_key_expires_at' => now()->addYears(10),
        ]);

        $this->assertNull($user->mcp_api_key);
        $this->assertNull($user->mcp_api_key_expires_at);
    }

    public function test_use_is_recorded_for_misuse_detection(): void
    {
        [$user, $patientId] = $this->userWithPatient();
        $token = $user->issueMcpToken(30);

        $this->assertNull($user->mcp_api_key_last_used_at);

        $this->summaryRequest($token, $patientId)->assertOk();

        $this->assertNotNull($user->fresh()?->mcp_api_key_last_used_at);
    }

    public function test_issue_command_prints_a_working_token(): void
    {
        [$user, $patientId] = $this->userWithPatient();

        $this->artisan('mcp:token:issue', ['email' => $user->email, '--days' => 7])
            ->assertExitCode(0);

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed?->mcp_api_key);
        $this->assertNotNull($refreshed->mcp_api_key_expires_at);
        $this->assertEqualsWithDelta(
            7 * 24 * 60,
            now()->diffInMinutes($refreshed->mcp_api_key_expires_at),
            5,
        );
    }

    public function test_issue_command_rejects_unknown_and_disabled_users(): void
    {
        $this->artisan('mcp:token:issue', ['email' => 'nobody@example.test'])
            ->assertExitCode(1);

        $disabled = $this->createDisabledUser();
        $this->artisan('mcp:token:issue', ['email' => $disabled->email])
            ->assertExitCode(1);
        $this->assertNull($disabled->fresh()?->mcp_api_key);
    }

    public function test_disabled_account_token_is_rejected(): void
    {
        // Burn id 1, which User::hasRole() unconditionally treats as admin, so
        // clearing user_role below actually disables the account.
        $this->createUser();

        [$user, $patientId] = $this->userWithPatient();
        $token = $user->issueMcpToken(30);

        $this->summaryRequest($token, $patientId)->assertOk();

        $user->forceFill(['user_role' => ''])->save();
        $this->assertFalse($user->fresh()?->canLogin());

        $this->summaryRequest($token, $patientId)->assertUnauthorized();
    }

    /**
     * User::hasRole() hardcodes id 1 as admin, so a disabled user must not be
     * the first row in the fresh in-memory database.
     */
    private function createDisabledUser(): User
    {
        $this->createUser();

        return User::factory()->create(['user_role' => '']);
    }

    public function test_token_does_not_reach_across_users(): void
    {
        [$owner, $ownerPatientId] = $this->userWithPatient();
        [$other] = $this->userWithPatient();

        $otherToken = $other->issueMcpToken(30);

        // A valid token for a different user must not reach this patient.
        $this->summaryRequest($otherToken, $ownerPatientId)->assertNotFound();
    }

    /**
     * Built directly through the models rather than over HTTP on purpose.
     *
     * `actingAs` would leave an authenticated session behind, and the
     * middleware short-circuits on Auth::check() before it ever looks at the
     * bearer token — so these tests would pass on the session and prove
     * nothing about the token.
     *
     * @return array{0: User, 1: int}
     */
    private function userWithPatient(): array
    {
        $user = $this->createUser();
        $patient = PhrPatient::create([
            'owner_user_id' => $user->id,
            'display_name' => 'Primary',
        ]);
        PhrPatientUserAccess::create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $user->id,
            'granted_at' => now(),
        ]);

        return [$user, (int) $patient->id];
    }

    /**
     * Each call starts from a clean auth state.
     *
     * The middleware short-circuits on Auth::check(), and the test harness
     * reuses one resolved guard across every request in a test method — so
     * without forgetting guards, the user set by an earlier successful call
     * would still be present and a revoked or expired token would appear to
     * keep working. In production each request is a fresh process; setUser()
     * only sets an in-memory property and never writes to the session, so
     * there is nothing to carry over.
     */
    private function summaryRequest(string $token, int $patientId): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson("/api/phr/patients/{$patientId}/respiratory-events/summary");
    }
}

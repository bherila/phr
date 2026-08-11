<?php

namespace Tests\Feature;

use App\Models\PhrDeviceKey;
use App\Models\PhrDevicePairingCode;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Device pairing replaces `php artisan mcp:token:issue` + a human pasting a
 * key over shell access: the Mac app opens a browser URL, the signed-in user
 * approves or denies on DevicePairingController's page, and the app exchanges
 * the resulting one-time code (DevicePairingExchangeController) for its own
 * per-device key — a full-account-adjacent credential that must be minted
 * without ever passing through a human's clipboard.
 */
class DevicePairingTest extends TestCase
{
    private const string REDIRECT_URI = 'sinussentinel://paired';

    // ── show() ──────────────────────────────────────────────────────────────

    public function test_unauthenticated_show_redirects_to_login_not_the_custom_scheme(): void
    {
        $response = $this->get('/device-pairing?'.http_build_query($this->pairingParams('device-1')));

        $response->assertRedirect('/login');
    }

    public function test_show_renders_the_approve_page_for_a_signed_in_user(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->get('/device-pairing?'.http_build_query($this->pairingParams('device-1', 'My Mac')));

        $response->assertOk();
        $response->assertSee('My Mac');
        $response->assertSee($user->email);
    }

    public function test_invalid_redirect_uri_is_rejected_and_mints_no_code(): void
    {
        $user = $this->createUser();
        $params = $this->pairingParams('device-1');
        $params['redirect_uri'] = 'https://evil.example/callback';

        $response = $this->actingAs($user)->get('/device-pairing?'.http_build_query($params));

        $response->assertStatus(422);
        $this->assertNull($response->headers->get('Location'));
        $this->assertSame(0, PhrDevicePairingCode::query()->count());
    }

    // ── approve() / deny() ──────────────────────────────────────────────────

    public function test_approve_mints_a_code_and_redirects_to_the_custom_scheme(): void
    {
        $user = $this->createUser();
        [, $challenge] = $this->pkce();

        $response = $this->actingAs($user)->post('/device-pairing/approve', $this->pairingParams('device-1', 'My Mac', $challenge));

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT_URI.'?', $location);
        $this->assertArrayHasKey('code', $this->queryFromLocation($location));

        $this->assertSame(1, PhrDevicePairingCode::query()->count());
        $code = PhrDevicePairingCode::query()->first();
        $this->assertSame($user->id, $code->user_id);
        $this->assertSame('device-1', $code->device_id);
        $this->assertSame('My Mac', $code->name);
        $this->assertNull($code->consumed_at);
    }

    public function test_approve_with_invalid_redirect_uri_is_rejected_and_mints_no_code(): void
    {
        $user = $this->createUser();
        $params = $this->pairingParams('device-1');
        $params['redirect_uri'] = 'https://evil.example/callback';

        $response = $this->actingAs($user)->post('/device-pairing/approve', $params);

        $response->assertStatus(422);
        $this->assertNull($response->headers->get('Location'));
        $this->assertSame(0, PhrDevicePairingCode::query()->count());
    }

    public function test_deny_redirects_with_error_and_mints_nothing(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->post('/device-pairing/deny', $this->pairingParams('device-1'));

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT_URI.'?', $location);
        $this->assertSame(['error' => 'denied'], $this->queryFromLocation($location));

        $this->assertSame(0, PhrDevicePairingCode::query()->count());
    }

    // ── exchange() happy path ───────────────────────────────────────────────

    public function test_exchange_happy_path_returns_a_working_token(): void
    {
        [$user, $patientId] = $this->userWithPatient();
        [$verifier, $challenge] = $this->pkce();
        $deviceId = 'mac-'.Str::random(8);

        $code = $this->approveAndGetCode($user, $deviceId, $challenge, 'My Mac');

        $response = $this->postJson('/api/device-pairing/exchange', [
            'code' => $code,
            'code_verifier' => $verifier,
            'device_id' => $deviceId,
        ]);

        $response->assertOk();
        $token = $response->json('token');
        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));
        $this->assertSame('My Mac', $response->json('device_name'));
        $this->assertNotNull($response->json('expires_at'));

        $key = PhrDeviceKey::query()->where('device_id', $deviceId)->first();
        $this->assertNotNull($key);
        $this->assertNull($key->last_used_at);

        $this->deviceRequest($token, $patientId)->assertOk();
        $this->assertNotNull($key->fresh()->last_used_at);

        // The code cannot be redeemed twice.
        $this->assertNotNull(PhrDevicePairingCode::query()->first()->consumed_at);
    }

    public function test_exchange_repairing_same_device_replaces_the_previous_key(): void
    {
        [$user, $patientId] = $this->userWithPatient();
        $deviceId = 'mac-repair';

        [$verifier1, $challenge1] = $this->pkce();
        $code1 = $this->approveAndGetCode($user, $deviceId, $challenge1);
        $token1 = $this->exchange($code1, $verifier1, $deviceId)->json('token');

        [$verifier2, $challenge2] = $this->pkce();
        $code2 = $this->approveAndGetCode($user, $deviceId, $challenge2);
        $token2 = $this->exchange($code2, $verifier2, $deviceId)->json('token');

        $this->assertNotSame($token1, $token2);
        $this->assertSame(
            1,
            PhrDeviceKey::query()->where('user_id', $user->id)->where('device_id', $deviceId)->count()
        );

        $this->deviceRequest($token1, $patientId)->assertUnauthorized();
        $this->deviceRequest($token2, $patientId)->assertOk();
    }

    // ── exchange() failure modes: identical 400 for every one ──────────────

    public function test_exchange_wrong_verifier_is_rejected(): void
    {
        [$user] = $this->userWithPatient();
        [, $challenge] = $this->pkce();
        [$wrongVerifier] = $this->pkce();
        $deviceId = 'mac-1';
        $code = $this->approveAndGetCode($user, $deviceId, $challenge);

        $this->exchange($code, $wrongVerifier, $deviceId)->assertStatus(400)->assertExactJson([
            'message' => 'Invalid or expired pairing code.',
        ]);
    }

    public function test_exchange_expired_code_is_rejected(): void
    {
        [$user] = $this->userWithPatient();
        [$verifier, $challenge] = $this->pkce();
        $deviceId = 'mac-1';
        $code = $this->approveAndGetCode($user, $deviceId, $challenge);

        Carbon::setTestNow(now()->addMinutes(6));

        try {
            $this->exchange($code, $verifier, $deviceId)->assertStatus(400)->assertExactJson([
                'message' => 'Invalid or expired pairing code.',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_exchange_consumed_code_is_rejected_on_second_redemption(): void
    {
        [$user] = $this->userWithPatient();
        [$verifier, $challenge] = $this->pkce();
        $deviceId = 'mac-1';
        $code = $this->approveAndGetCode($user, $deviceId, $challenge);

        $this->exchange($code, $verifier, $deviceId)->assertOk();

        $this->exchange($code, $verifier, $deviceId)->assertStatus(400)->assertExactJson([
            'message' => 'Invalid or expired pairing code.',
        ]);
    }

    public function test_exchange_wrong_device_id_is_rejected(): void
    {
        [$user] = $this->userWithPatient();
        [$verifier, $challenge] = $this->pkce();
        $code = $this->approveAndGetCode($user, 'mac-1', $challenge);

        $this->exchange($code, $verifier, 'mac-2')->assertStatus(400)->assertExactJson([
            'message' => 'Invalid or expired pairing code.',
        ]);
    }

    public function test_exchange_unknown_code_is_rejected(): void
    {
        [$verifier] = $this->pkce();

        $this->exchange(Str::random(64), $verifier, 'mac-1')->assertStatus(400)->assertExactJson([
            'message' => 'Invalid or expired pairing code.',
        ]);
    }

    // ── exchange() CSRF exemption + malformed body ─────────────────────────

    public function test_exchange_is_csrf_exempt(): void
    {
        [$user] = $this->userWithPatient();
        [$verifier, $challenge] = $this->pkce();
        $deviceId = 'mac-csrf';
        $code = $this->approveAndGetCode($user, $deviceId, $challenge);

        // No @csrf token anywhere; a session-carrying request without one
        // would normally 419 on a `web`-group route.
        $this->exchange($code, $verifier, $deviceId)->assertOk();
    }

    public function test_exchange_rejects_a_non_json_body(): void
    {
        $this->post('/api/device-pairing/exchange', [
            'code' => 'x',
            'code_verifier' => 'y',
            'device_id' => 'z',
        ])->assertStatus(415);
    }

    public function test_exchange_rejects_a_malformed_json_body(): void
    {
        $this->postJson('/api/device-pairing/exchange', [
            'code_verifier' => 'y',
            'device_id' => 'z',
        ])->assertStatus(422);
    }

    // ── AuthenticateWebOrMcpRequest: device-key fail-closed cases ──────────

    public function test_revoked_device_key_is_rejected(): void
    {
        [$user, $patientId] = $this->userWithPatient();
        ['plaintext' => $token, 'key' => $key] = PhrDeviceKey::issueFor($user, 'mac-1', 'My Mac');

        $this->deviceRequest($token, $patientId)->assertOk();

        $key->forceFill(['revoked_at' => now()])->save();

        $this->deviceRequest($token, $patientId)->assertUnauthorized();
    }

    public function test_expired_device_key_is_rejected(): void
    {
        [$user, $patientId] = $this->userWithPatient();
        ['plaintext' => $token] = PhrDeviceKey::issueFor($user, 'mac-1', 'My Mac', 30);

        $this->deviceRequest($token, $patientId)->assertOk();

        Carbon::setTestNow(now()->addDays(31));

        try {
            $this->deviceRequest($token, $patientId)->assertUnauthorized();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_device_key_for_a_disabled_user_is_rejected(): void
    {
        // Burn id 1, which User::hasRole() unconditionally treats as admin, so
        // clearing user_role below actually disables the account.
        $this->createUser();

        [$user, $patientId] = $this->userWithPatient();
        ['plaintext' => $token] = PhrDeviceKey::issueFor($user, 'mac-1', 'My Mac');

        $this->deviceRequest($token, $patientId)->assertOk();

        $user->forceFill(['user_role' => ''])->save();

        $this->deviceRequest($token, $patientId)->assertUnauthorized();
    }

    public function test_exchange_for_a_since_disabled_user_is_rejected(): void
    {
        // Burn id 1, which User::hasRole() unconditionally treats as admin, so
        // clearing user_role below actually disables the account.
        $this->createUser();

        [$user] = $this->userWithPatient();
        [$verifier, $challenge] = $this->pkce();
        $code = $this->approveAndGetCode($user, 'mac-1', $challenge);

        // The account is disabled between approval and redemption: no key row
        // should ever be created, and the failure is the standard 400.
        $user->forceFill(['user_role' => ''])->save();

        $this->exchange($code, $verifier, 'mac-1')->assertStatus(400)->assertExactJson([
            'message' => 'Invalid or expired pairing code.',
        ]);
        $this->assertSame(0, PhrDeviceKey::query()->count());
    }

    public function test_legacy_mcp_token_still_authenticates(): void
    {
        [$user, $patientId] = $this->userWithPatient();
        $legacyToken = $user->issueMcpToken(30);

        $this->deviceRequest($legacyToken, $patientId)->assertOk();
    }

    // ── /user/devices ───────────────────────────────────────────────────────

    public function test_user_devices_index_requires_a_session(): void
    {
        $this->getJson('/api/user/devices')->assertUnauthorized();
    }

    public function test_user_devices_destroy_requires_a_session(): void
    {
        $this->deleteJson('/api/user/devices/1')->assertUnauthorized();
    }

    public function test_user_devices_lists_only_own_keys_and_hides_the_hash(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();

        ['key' => $ownKey] = PhrDeviceKey::issueFor($user, 'mac-1', 'My Mac');
        PhrDeviceKey::issueFor($other, 'mac-2', 'Someone else\'s Mac');

        $response = $this->actingAs($user)->getJson('/api/user/devices');

        $response->assertOk();
        $body = $response->json();
        $this->assertCount(1, $body);
        $this->assertSame($ownKey->id, $body[0]['id']);
        $this->assertArrayNotHasKey('token_hash', $body[0]);
    }

    public function test_user_can_revoke_their_own_device_key(): void
    {
        $user = $this->createUser();
        ['key' => $key] = PhrDeviceKey::issueFor($user, 'mac-1', 'My Mac');

        $this->actingAs($user)->deleteJson("/api/user/devices/{$key->id}")->assertOk();

        $this->assertNotNull($key->fresh()->revoked_at);
    }

    public function test_user_cannot_revoke_another_users_device_key(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        ['key' => $key] = PhrDeviceKey::issueFor($other, 'mac-1', 'My Mac');

        $this->actingAs($user)->deleteJson("/api/user/devices/{$key->id}")->assertNotFound();

        $this->assertNull($key->fresh()->revoked_at);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function pairingParams(string $deviceId, string $name = 'My Mac', ?string $codeChallenge = null): array
    {
        return [
            'device_id' => $deviceId,
            'name' => $name,
            'code_challenge' => $codeChallenge ?? str_repeat('a', 43),
            'redirect_uri' => self::REDIRECT_URI,
        ];
    }

    /**
     * @return array{0: string, 1: string} [verifier, challenge]
     */
    private function pkce(): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    /**
     * @return array<string, string>
     */
    private function queryFromLocation(string $location): array
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

        /** @var array<string, string> $params */
        return $params;
    }

    private function approveAndGetCode(User $user, string $deviceId, string $codeChallenge, string $name = 'My Mac'): string
    {
        $response = $this->actingAs($user)->post(
            '/device-pairing/approve',
            $this->pairingParams($deviceId, $name, $codeChallenge)
        );

        $params = $this->queryFromLocation((string) $response->headers->get('Location'));

        return $params['code'];
    }

    private function exchange(string $code, string $verifier, string $deviceId): TestResponse
    {
        return $this->postJson('/api/device-pairing/exchange', [
            'code' => $code,
            'code_verifier' => $verifier,
            'device_id' => $deviceId,
        ]);
    }

    /**
     * Built directly through the middleware's bearer-token path, with a fresh
     * auth state each call — see McpTokenLifecycleTest::summaryRequest() for
     * why forgetGuards() is required between calls in the same test.
     */
    private function deviceRequest(string $token, int $patientId): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson("/api/phr/patients/{$patientId}/respiratory-events/summary");
    }

    /**
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
}

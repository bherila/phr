<?php

namespace Tests\Feature;

use App\Models\OAuthPatientConsent;
use App\Models\OAuthTokenFamily;
use App\Models\PhrNativeRecordIdentity;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Identity\PhrPublicIds;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentOpenApi;
use App\Support\AgentApi\AgentOperations;
use App\Support\AgentApi\OAuthClientConsents;
use App\Support\AgentApi\OAuthCredentialRevoker;
use App\Support\AgentApi\OAuthPatientConsents;
use Bherila\McpLaravelBridge\Testing\OperationRegistryAssertions;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Events\RefreshTokenCreated;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AgentApiPatientSelectionConsentTest extends TestCase
{
    use OperationRegistryAssertions;

    private const string CALLBACK = 'https://consumer.example.test/connect/callback';

    private const string VERIFIER = 'synthetic-patient-selection-verifier-synthetic-verifier';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32))]);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        config(['passport.private_key' => $privateKey, 'passport.public_key' => $details['key']]);
    }

    public function test_the_patient_consent_migration_uses_the_configured_passport_connection(): void
    {
        $migration = require database_path('migrations/2026_10_20_120000_add_oauth_patient_consents.php');
        $original = config('passport.connection');
        try {
            // Inspect only the name; never connect to this synthetic database.
            config(['passport.connection' => 'synthetic-passport']);
            $this->assertSame('synthetic-passport', $migration->getConnection());
            config(['passport.connection' => null]);
            $this->assertNull($migration->getConnection());
        } finally {
            config(['passport.connection' => $original]);
        }
    }

    public function test_a_patient_selection_request_cannot_issue_a_code_without_a_selection(): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $this->patient($user, 'Synthetic Owned Patient');
        $this->startSelection($user, $this->client())->assertOk();
        $this->post('/oauth/authorize', ['auth_token' => session('authToken')])
            ->assertUnprocessable();
        $this->assertSame(0, AuthCode::query()->count());
    }

    public function test_the_picker_shows_names_only_for_owned_or_managed_patients(): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $other = $this->createUser(['name' => 'Synthetic Other', 'email' => 'other@example.test']);
        $this->patient($user, 'Synthetic Owned Patient');
        $managed = $this->patient($other, 'Synthetic Managed Patient');
        $viewer = $this->patient($other, 'Synthetic Viewer Patient');
        $this->patient($other, 'Synthetic Foreign Patient');
        $this->grant($managed, $user, PhrPatientUserAccess::LEVEL_MANAGER);
        $this->grant($viewer, $user, PhrPatientUserAccess::LEVEL_VIEWER);
        $this->startSelection($user, $this->client())->assertOk()
            ->assertSee('Synthetic Owned Patient')->assertSee('Synthetic Managed Patient')
            ->assertDontSee('Synthetic Viewer Patient')->assertDontSee('Synthetic Foreign Patient');
    }

    #[DataProvider('refusedSelections')]
    public function test_a_tampered_or_unwritable_patient_selection_is_refused(string $selection): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $other = $this->createUser(['name' => 'Synthetic Other', 'email' => 'other@example.test']);
        $owned = $this->patient($user, 'Synthetic Owned Patient');
        $foreign = $this->patient($other, 'Synthetic Foreign Patient');
        $viewer = $this->patient($other, 'Synthetic Viewer Patient');
        $this->grant($viewer, $user, PhrPatientUserAccess::LEVEL_VIEWER);
        $chosen = match ($selection) {
            'foreign' => $this->publicId($foreign),
            'viewer' => $this->publicId($viewer),
            'integer' => (string) $owned->id,
            default => '00000000-0000-4000-8000-000000000001',
        };
        $this->startSelection($user, $this->client())->assertOk();
        $this->post('/oauth/authorize', ['auth_token' => session('authToken'), 'patient_public_id' => $chosen])
            ->assertUnprocessable();
        $this->assertSame(0, AuthCode::query()->count());
    }

    public static function refusedSelections(): array
    {
        return [['foreign'], ['viewer'], ['integer'], ['unknown']];
    }

    public function test_an_open_patient_picker_cannot_grant_consent_after_the_client_is_revoked(): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $patient = $this->patient($user, 'Synthetic Patient');
        $client = $this->client();
        $this->startSelection($user, $client)->assertOk();
        $client->forceFill(['revoked' => true])->save();
        $this->post('/oauth/authorize', ['auth_token' => session('authToken'), 'patient_public_id' => $this->publicId($patient)])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(0, OAuthPatientConsent::query()->count());
        $this->assertDatabaseCount('oauth_client_consents', 0);
        $this->assertSame(0, AuthCode::query()->count());
    }

    public function test_the_token_response_returns_only_the_chosen_patient_public_id_as_context(): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $patient = $this->patient($user, 'Synthetic Chosen Patient');
        $client = $this->client();
        $this->startSelection($user, $client)->assertOk();
        $response = $this->exchange($client, $this->approve($this->publicId($patient)))->assertOk();
        $response->assertJsonPath('patient', $this->publicId($patient));
        $response->assertDontSee('Synthetic Chosen Patient');
        $this->assertArrayNotHasKey('patient_name', $response->json());
        $this->assertArrayNotHasKey('access_level', $response->json());
    }

    public function test_a_selected_billing_client_lists_only_the_consented_patient(): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $selected = $this->patient($user, 'Synthetic Selected Patient');
        $this->patient($user, 'Synthetic Unselected Patient');
        $client = $this->client();
        $this->startSelection($user, $client)->assertOk();
        $token = $this->exchange($client, $this->approve($this->publicId($selected)))->assertOk()->json('access_token');
        Auth::forgetGuards();
        $response = $this->withToken($token)->getJson('/api/v1/patients')->assertOk();
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_id', $this->publicId($selected));
        $this->assertSame(['id', 'public_id'], array_keys($response->json('data.0')));
    }

    #[DataProvider('unselectedPatientPaths')]
    public function test_an_unselected_accessible_patient_has_no_billing_existence_oracle(string $path): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $selected = $this->patient($user, 'Synthetic Selected Patient');
        $unselected = $this->patient($user, 'Synthetic Unselected Patient');
        $client = $this->client();
        $this->startSelection($user, $client)->assertOk();
        $token = $this->exchange($client, $this->approve($this->publicId($selected)))->assertOk()->json('access_token');
        Auth::forgetGuards();
        $this->withToken($token)->getJson(str_replace('{patient}', (string) $unselected->id, $path))
            ->assertNotFound();
    }

    public static function unselectedPatientPaths(): array
    {
        return [['/api/v1/patients/{patient}'], ['/api/v1/patients/{patient}/eobs']];
    }

    public function test_a_second_round_trip_forces_the_picker_and_adds_one_patient(): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $first = $this->patient($user, 'Synthetic First Patient');
        $second = $this->patient($user, 'Synthetic Second Patient');
        $client = $this->client();
        $tokens = $this->selectTokens($user, $client, $first);
        $this->selectTokens($user, $client, $second);
        $this->getAsToken($tokens['access_token'], '/api/v1/patients')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_patient_choices_are_bound_to_the_person_as_well_as_the_client(): void
    {
        $firstUser = $this->createUser(['name' => 'Synthetic First Owner', 'email' => 'first@example.test']);
        $secondUser = $this->createUser(['name' => 'Synthetic Second Owner', 'email' => 'second@example.test']);
        $first = $this->patient($firstUser, 'Synthetic First Patient');
        $second = $this->patient($secondUser, 'Synthetic Second Patient');
        $client = $this->client();
        $firstTokens = $this->selectTokens($firstUser, $client, $first);
        $secondTokens = $this->selectTokens($secondUser, $client, $second);
        $this->getAsToken($firstTokens['access_token'], '/api/v1/patients')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_id', $this->publicId($first));
        $this->getAsToken($secondTokens['access_token'], '/api/v1/patients')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_id', $this->publicId($second));
        $this->getAsToken($secondTokens['access_token'], '/api/v1/patients/'.$first->id)->assertNotFound();
    }

    public function test_revoking_one_patient_leaves_the_other_selected_patient_accessible(): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $first = $this->patient($user, 'Synthetic First Patient');
        $second = $this->patient($user, 'Synthetic Second Patient');
        $client = $this->client();
        $this->selectTokens($user, $client, $first);
        $tokens = $this->selectTokens($user, $client, $second);
        $this->revokePatient($tokens['access_token'], $first)->assertNoContent();
        $this->getAsToken($tokens['access_token'], '/api/v1/patients/'.$first->id)->assertNotFound();
        $this->getAsToken($tokens['access_token'], '/api/v1/patients/'.$second->id)->assertOk();
        $this->getAsToken($tokens['access_token'], '/api/v1/patients')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_refresh_omits_revoked_context_without_losing_other_patient_grants(): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $first = $this->patient($user, 'Synthetic First Patient');
        $second = $this->patient($user, 'Synthetic Second Patient');
        $client = $this->client();
        $firstTokens = $this->selectTokens($user, $client, $first);
        $secondTokens = $this->selectTokens($user, $client, $second);
        $this->refreshTokens($client, $firstTokens['refresh_token'])->assertOk()->assertJsonPath('patient', $this->publicId($first));
        // Use the rotated refresh token for the same family below.
        $firstTokens = $this->selectTokens($user, $client, $first);
        $this->revokePatient($secondTokens['access_token'], $first)->assertNoContent();
        $rotated = $this->refreshTokens($client, $firstTokens['refresh_token'])->assertOk()->json();
        $this->assertArrayNotHasKey('patient', $rotated);
        $this->getAsToken($rotated['access_token'], '/api/v1/patients/'.$first->id)->assertNotFound();
        $this->getAsToken($rotated['access_token'], '/api/v1/patients/'.$second->id)->assertOk();
    }

    #[DataProvider('issuanceGrants')]
    public function test_revocation_during_issuance_omits_context_and_preserves_other_patient_grants(string $grant): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $first = $this->patient($user, 'Synthetic First Patient');
        $second = $this->patient($user, 'Synthetic Second Patient');
        $client = $this->client();
        $firstTokens = $this->selectTokens($user, $client, $first);
        $this->selectTokens($user, $client, $second);
        $publicId = $this->publicId($first);
        $code = null;
        if ($grant === 'authorization_code') {
            $this->startSelection($user, $client)->assertOk();
            $code = $this->approve($publicId);
        }
        $ran = false;
        Event::listen(RefreshTokenCreated::class, function () use (&$ran, $publicId): void {
            if ($ran) {
                return;
            }
            $ran = true;
            OAuthPatientConsent::query()->where('patient_public_id', $publicId)
                ->update(['revoked_at' => now(), 'version' => 2]);
        });
        $response = $grant === 'authorization_code'
            ? $this->exchange($client, $code)
            : $this->refreshTokens($client, $firstTokens['refresh_token']);
        $tokens = $response->assertOk()->json();
        $this->assertTrue($ran);
        $this->assertArrayNotHasKey('patient', $tokens);
        $this->getAsToken($tokens['access_token'], '/api/v1/patients/'.$first->id)->assertNotFound();
        $this->getAsToken($tokens['access_token'], '/api/v1/patients/'.$second->id)->assertOk();
    }

    #[DataProvider('issuanceGrants')]
    public function test_revoke_and_regrant_during_issuance_cannot_restore_an_older_patient_context(string $grant): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $first = $this->patient($user, 'Synthetic First Patient');
        $second = $this->patient($user, 'Synthetic Second Patient');
        $client = $this->client();
        $firstTokens = $this->selectTokens($user, $client, $first);
        $this->selectTokens($user, $client, $second);
        $publicId = $this->publicId($first);
        $code = null;
        if ($grant === 'authorization_code') {
            $this->startSelection($user, $client)->assertOk();
            $code = $this->approve($publicId);
        }
        $ran = false;
        Event::listen(RefreshTokenCreated::class, function () use (&$ran, $publicId, $user, $client, $first): void {
            if ($ran) {
                return;
            }
            $ran = true;
            $selection = OAuthPatientConsent::query()->where('patient_public_id', $publicId)->sole();
            $selection->forceFill(['revoked_at' => now(), 'version' => $selection->version + 1])->save();
            $parent = app(OAuthClientConsents::class)->covering($user->id, (string) $client->id, OAuthResourceIndicator::resource('rest'), [AgentApiScopes::BILLING_READ]);
            $this->assertNotNull($parent);
            app(OAuthPatientConsents::class)->grant($parent, $first);
        });
        $response = $grant === 'authorization_code'
            ? $this->exchange($client, $code)
            : $this->refreshTokens($client, $firstTokens['refresh_token']);
        $tokens = $response->assertOk()->json();
        $this->assertTrue($ran);
        $this->assertArrayNotHasKey('patient', $tokens);
        // The newly granted set remains usable; it does not revive old launch context.
        $this->getAsToken($tokens['access_token'], '/api/v1/patients/'.$first->id)->assertOk();
        $this->getAsToken($tokens['access_token'], '/api/v1/patients/'.$second->id)->assertOk();
    }

    public function test_a_family_without_a_patient_version_omits_context_without_revoking_its_access(): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $patient = $this->patient($user, 'Synthetic Patient');
        $client = $this->client();
        $tokens = $this->selectTokens($user, $client, $patient);
        OAuthTokenFamily::query()->where('client_id', $client->id)->update(['patient_consent_version' => null]);
        $refreshed = $this->refreshTokens($client, $tokens['refresh_token'])->assertOk()->json();
        $this->assertArrayNotHasKey('patient', $refreshed);
        $this->getAsToken($refreshed['access_token'], '/api/v1/patients/'.$patient->id)->assertOk();
    }

    public static function issuanceGrants(): array
    {
        return [['authorization_code'], ['refresh_token']];
    }

    public function test_full_client_revoke_and_regrant_does_not_restore_old_patient_choices(): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $first = $this->patient($user, 'Synthetic First Patient');
        $second = $this->patient($user, 'Synthetic Second Patient');
        $client = $this->client();
        $this->selectTokens($user, $client, $first);
        $this->selectTokens($user, $client, $second);
        app(OAuthCredentialRevoker::class)->revokeClientConsent($user->id, (string) $client->id);
        $tokens = $this->selectTokens($user, $client, $first);
        $this->getAsToken($tokens['access_token'], '/api/v1/patients')->assertOk()->assertJsonCount(1, 'data');
        $this->getAsToken($tokens['access_token'], '/api/v1/patients/'.$second->id)->assertNotFound();
    }

    public function test_a_code_selected_before_revoke_and_regrant_cannot_be_exchanged(): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $first = $this->patient($user, 'Synthetic First Patient');
        $second = $this->patient($user, 'Synthetic Second Patient');
        $client = $this->client();
        $this->startSelection($user, $client)->assertOk();
        $oldCode = $this->approve($this->publicId($first));
        $tokens = $this->selectTokens($user, $client, $second);
        $this->revokePatient($tokens['access_token'], $first)->assertNoContent();
        $this->selectTokens($user, $client, $first);
        $this->exchange($client, $oldCode)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_public_id_consent_survives_native_identity_renumbering(): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $original = $this->patient($user, 'Synthetic Original Patient');
        $publicId = $this->publicId($original);
        $tokens = $this->selectTokens($user, $this->client(), $original);
        $oldId = $original->id;
        // A native restore recreates this stable identity beside a different routing row.
        PhrNativeRecordIdentity::query()->where('patient_id', $oldId)->delete();
        $original->delete();
        $replacement = $this->patient($user, 'Synthetic Restored Patient');
        $this->assertNotSame($oldId, $replacement->id);
        PhrNativeRecordIdentity::query()->where('patient_id', $replacement->id)->delete();
        PhrNativeRecordIdentity::query()->create([
            'patient_id' => $replacement->id, 'record_table' => PhrPublicIds::PATIENTS,
            'record_id' => $replacement->id, 'native_id' => $publicId,
        ]);
        $this->getAsToken($tokens['access_token'], '/api/v1/patients')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $replacement->id)->assertJsonPath('data.0.public_id', $publicId);
        $this->getAsToken($tokens['access_token'], '/api/v1/patients/'.$oldId)->assertNotFound();
    }

    public function test_capabilities_identify_the_billing_patient_consent_contract(): void
    {
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('oauth.billing_patient_consent', [
            'version' => 1, 'authorization_parameter' => 'patient_select',
            'token_response_parameter' => 'patient', 'patient_identifiers' => 'public_id',
            'selection_roles' => ['owner', 'manager'],
        ]);
    }

    public function test_the_capability_schema_refuses_missing_or_unsupported_contract_fields(): void
    {
        $schema = AgentApiResponseSchemaCatalog::schema('Capabilities')['properties']['oauth']['properties']['billing_patient_consent'] ?? [];
        $this->assertNotEmpty($schema);
        $capability = $this->getJson('/api/v1/capabilities')->assertOk()->json('oauth.billing_patient_consent');
        $validator = new SchemaValidator;
        $this->assertSame([], $validator->validateAgainstJsonSchema($capability, $schema));
        foreach (array_keys($capability) as $key) {
            $missing = $capability;
            unset($missing[$key]);
            $this->assertNotSame([], $validator->validateAgainstJsonSchema($missing, $schema));
        }
        $this->assertNotSame([], $validator->validateAgainstJsonSchema([...$capability, 'version' => 2], $schema));
        $this->assertNotSame([], $validator->validateAgainstJsonSchema([...$capability, 'extra' => true], $schema));
    }

    public function test_a_legacy_billing_grant_without_patient_choices_fails_closed(): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $patient = $this->patient($user, 'Synthetic Patient');
        $client = $this->client();
        $this->startSelection($user, $client, ['patient_select' => null])->assertOk();
        $tokens = $this->exchange($client, $this->approve($this->publicId($patient)))->assertOk()->json();
        $this->assertArrayNotHasKey('patient', $tokens);
        $this->getAsToken($tokens['access_token'], '/api/v1/patients')->assertOk()->assertJsonCount(0, 'data');
        $this->getAsToken($tokens['access_token'], '/api/v1/patients/'.$patient->id)->assertNotFound();
    }

    public function test_billing_consent_still_limits_a_credential_with_clinical_scopes(): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $first = $this->patient($user, 'Synthetic Selected Patient');
        $second = $this->patient($user, 'Synthetic Unselected Patient');
        $client = $this->client();
        $client->forceFill(['scopes' => [AgentApiScopes::IDENTITY_READ, AgentApiScopes::BILLING_READ, AgentApiScopes::CLINICAL_READ, AgentApiScopes::PATIENTS_READ]])->save();
        $this->startSelection($user, $client, ['scope' => implode(' ', $client->scopes)])->assertOk();
        $tokens = $this->exchange($client, $this->approve($this->publicId($first)))->assertOk()->json();
        $this->getAsToken($tokens['access_token'], '/api/v1/patients')->assertOk()->assertJsonCount(1, 'data');
        $this->getAsToken($tokens['access_token'], '/api/v1/patients/'.$second->id)->assertNotFound();
        $this->getAsToken($tokens['access_token'], '/api/v1/patients/'.$second->id.'/eobs')->assertNotFound();
    }

    public function test_selection_does_not_add_access_when_a_manager_grant_was_removed(): void
    {
        $user = $this->createUser(['name' => 'Synthetic Manager', 'email' => 'manager@example.test']);
        $owner = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $patient = $this->patient($owner, 'Synthetic Managed Patient');
        $this->grant($patient, $user, PhrPatientUserAccess::LEVEL_MANAGER);
        $client = $this->client();
        $tokens = $this->selectTokens($user, $client, $patient);
        $this->startSelection($user, $client)->assertOk();
        $code = $this->approve($this->publicId($patient));
        PhrPatientUserAccess::query()->where('patient_id', $patient->id)->where('user_id', $user->id)->delete();
        $this->exchange($client, $code)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->getAsToken($tokens['access_token'], '/api/v1/patients/'.$patient->id)->assertNotFound();
    }

    public function test_an_mcp_credential_withdraws_the_same_clients_rest_patient_consent(): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $first = $this->patient($user, 'Synthetic First Patient');
        $second = $this->patient($user, 'Synthetic Second Patient');
        $client = $this->client();
        $this->selectTokens($user, $client, $first);
        $restTokens = $this->selectTokens($user, $client, $second);
        $client->forceFill(['scopes' => [AgentApiScopes::IDENTITY_READ, AgentApiScopes::BILLING_READ, AgentApiScopes::MCP_USE]])->save();
        $resource = OAuthResourceIndicator::resource('mcp');
        $this->startSelection($user, $client, [
            'patient_select' => null, 'resource' => $resource,
            'scope' => AgentApiScopes::IDENTITY_READ.' '.AgentApiScopes::MCP_USE,
        ])->assertOk();
        $mcpTokens = $this->exchange($client, $this->approve($this->publicId($first)), $resource)->assertOk()->json();
        $this->assertArrayNotHasKey('patient', $mcpTokens);
        $this->revokePatient($mcpTokens['access_token'], $first)->assertNoContent();
        $this->getAsToken($restTokens['access_token'], '/api/v1/patients/'.$first->id)->assertNotFound();
        $this->getAsToken($restTokens['access_token'], '/api/v1/patients/'.$second->id)->assertOk();
    }

    #[DataProvider('unrelatedMcpCallers')]
    public function test_mcp_withdrawal_cannot_revoke_another_person_or_clients_patient_consent(string $caller): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $patient = $this->patient($user, 'Synthetic Patient');
        $client = $this->client();
        $restTokens = $this->selectTokens($user, $client, $patient);
        $actor = $caller === 'person'
            ? $this->createUser(['name' => 'Synthetic Other', 'email' => 'other@example.test'])
            : $user;
        $mcpClient = $caller === 'client' ? $this->client() : $client;
        $mcpClient->forceFill(['scopes' => [AgentApiScopes::IDENTITY_READ, AgentApiScopes::BILLING_READ, AgentApiScopes::MCP_USE]])->save();
        $resource = OAuthResourceIndicator::resource('mcp');
        $this->startSelection($actor, $mcpClient, [
            'patient_select' => null, 'resource' => $resource,
            'scope' => AgentApiScopes::IDENTITY_READ.' '.AgentApiScopes::MCP_USE,
        ])->assertOk();
        $mcpTokens = $this->exchange($mcpClient, $this->approve($this->publicId($patient)), $resource)->assertOk()->json();
        $this->revokePatient($mcpTokens['access_token'], $patient)->assertNoContent();
        $this->getAsToken($restTokens['access_token'], '/api/v1/patients/'.$patient->id)->assertOk();
    }

    public static function unrelatedMcpCallers(): array
    {
        return [['person'], ['client']];
    }

    public function test_revoking_an_unknown_patient_does_not_expose_existence_or_remove_other_grants(): void
    {
        $user = $this->createUser(['name' => 'Synthetic Owner', 'email' => 'owner@example.test']);
        $patient = $this->patient($user, 'Synthetic Selected Patient');
        $tokens = $this->selectTokens($user, $this->client(), $patient);
        Auth::forgetGuards();
        $this->withToken($tokens['access_token'])->deleteJson('/api/v1/oauth/consent/patients/00000000-0000-4000-8000-000000000001')->assertNoContent();
        $this->getAsToken($tokens['access_token'], '/api/v1/patients/'.$patient->id)->assertOk();
    }

    public function test_generated_openapi_includes_patient_consent_revocation(): void
    {
        self::assertOpenApiDocumentMatches(AgentOpenApi::checked()->full(), public_path(AgentOpenApi::CHECKED_DOCUMENT));
    }

    public function test_generated_capabilities_inventory_includes_patient_consent_revocation(): void
    {
        self::assertMatchesOperationSnapshot(
            $this->getJson('/api/v1/capabilities')->assertOk()->json('operations'),
            __DIR__.'/../Fixtures/agent-api/capabilities-operations.json',
        );
    }

    public function test_generated_scope_inventory_includes_patient_consent_revocation(): void
    {
        self::assertScopeInventory(app(AgentOperations::class)->registry(), __DIR__.'/../Fixtures/agent-api/scope-inventory.json');
    }

    private function selectTokens(User $user, Client $client, PhrPatient $patient): array
    {
        $this->startSelection($user, $client)->assertOk();

        return $this->exchange($client, $this->approve($this->publicId($patient)))->assertOk()->json();
    }

    private function getAsToken(string $token, string $path): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->getJson($path);
    }

    private function revokePatient(string $token, PhrPatient $patient): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->deleteJson('/api/v1/oauth/consent/patients/'.$this->publicId($patient));
    }

    private function refreshTokens(Client $client, string $refreshToken): TestResponse
    {
        Auth::forgetGuards();

        return $this->withoutToken()->postJson('/oauth/token', [
            'grant_type' => 'refresh_token', 'client_id' => $client->id,
            'client_secret' => $client->plainSecret, 'refresh_token' => $refreshToken,
        ]);
    }

    private function client(): Client
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Synthetic Billing Consumer', [self::CALLBACK], true, null);
        $client->forceFill(['scopes' => [AgentApiScopes::IDENTITY_READ, AgentApiScopes::BILLING_READ]])->save();
        config(['agent_api.first_party_oauth_clients' => [(string) $client->id]]);
        $secret = $client->plainSecret;
        $client = Client::query()->findOrFail($client->id);
        $client->plainSecret = $secret;

        return $client;
    }

    private function startSelection(User $user, Client $client, array $overrides = []): TestResponse
    {
        Auth::forgetGuards();
        // HTTP tests reuse routes; real requests construct a fresh controller/guard.
        app('router')->getRoutes()->getByName('passport.authorizations.authorize')?->flushController();

        return $this->withoutToken()->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id, 'redirect_uri' => self::CALLBACK, 'response_type' => 'code',
            'scope' => AgentApiScopes::IDENTITY_READ.' '.AgentApiScopes::BILLING_READ,
            'state' => 'synthetic-patient-state', 'patient_select' => '1',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            ...$overrides,
        ]));
    }

    private function approve(string $patient): string
    {
        $response = $this->post('/oauth/authorize', ['auth_token' => session('authToken'), 'patient_public_id' => $patient])
            ->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('synthetic-patient-state', $query['state'] ?? null);
        $this->assertSame(url('/'), $query['iss'] ?? null);
        $this->assertIsString($query['code'] ?? null);

        return $query['code'];
    }

    private function exchange(Client $client, string $code, ?string $resource = null): TestResponse
    {
        Auth::forgetGuards();

        return $this->withoutToken()->postJson('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client->id,
            'client_secret' => $client->plainSecret, 'redirect_uri' => self::CALLBACK,
            'code_verifier' => self::VERIFIER, 'code' => $code,
            ...($resource === null ? [] : ['resource' => $resource]),
        ]);
    }

    private function patient(User $owner, string $name): PhrPatient
    {
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id, 'display_name' => $name,
            'relationship' => 'self', 'birth_date' => '2000-01-01', 'sex_at_birth' => 'unknown',
        ]);
        $this->grant($patient, $owner, PhrPatientUserAccess::LEVEL_OWNER);

        return $patient;
    }

    private function grant(PhrPatient $patient, User $user, string $level): void
    {
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id, 'user_id' => $user->id, 'access_level' => $level,
            'granted_by_user_id' => $patient->owner_user_id, 'granted_at' => now(),
        ]);
    }

    private function publicId(PhrPatient $patient): string
    {
        return app(PhrPublicIds::class)->forPatient((int) $patient->id);
    }
}

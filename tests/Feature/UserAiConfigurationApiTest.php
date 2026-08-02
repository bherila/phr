<?php

namespace Tests\Feature;

use App\Models\UserAiConfiguration;
use App\Services\UserAiModelCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class UserAiConfigurationApiTest extends TestCase
{
    public function test_all_ai_configuration_endpoints_require_authentication(): void
    {
        $this->getJson('/api/user/ai-prefs')->assertUnauthorized();
        $this->postJson('/api/user/ai-prefs', [])->assertUnauthorized();
        $this->putJson('/api/user/ai-prefs/1', [])->assertUnauthorized();
        $this->deleteJson('/api/user/ai-prefs/1')->assertUnauthorized();
        $this->postJson('/api/user/ai-prefs/1/activate')->assertUnauthorized();
        $this->postJson('/api/user/ai-prefs/models', [])->assertUnauthorized();
    }

    public function test_index_is_user_scoped_and_never_returns_plaintext_credentials(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();
        $configuration = UserAiConfiguration::factory()->for($user)->bedrock()->create([
            'name' => 'Private configuration',
            'api_key' => 'unit-test-bearer-value',
            'session_token' => 'unit-test-session-value',
            'api_key_invalid_at' => now(),
            'api_key_invalid_reason' => 'internal-provider-detail',
        ]);
        UserAiConfiguration::factory()->for($otherUser)->gemini()->create([
            'name' => 'Other user configuration',
        ]);

        $response = $this->actingAs($user)->getJson('/api/user/ai-prefs');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $configuration->id)
            ->assertJsonPath('0.has_api_key', true)
            ->assertJsonPath('0.has_session_token', true)
            ->assertJsonPath('0.masked_key', '••••alue')
            ->assertJsonMissing(['name' => 'Other user configuration']);
        $this->assertStringNotContainsString('unit-test-bearer-value', $response->getContent());
        $this->assertStringNotContainsString('unit-test-session-value', $response->getContent());
        $this->assertStringNotContainsString('internal-provider-detail', $response->getContent());
        $this->assertNotSame(
            'unit-test-bearer-value',
            DB::table('user_ai_configurations')->where('id', $configuration->id)->value('api_key'),
        );
        $this->assertNotSame(
            'unit-test-session-value',
            DB::table('user_ai_configurations')->where('id', $configuration->id)->value('session_token'),
        );
    }

    public function test_create_validates_credentials_and_only_the_first_configuration_is_active(): void
    {
        $user = $this->createUser();
        $this->mock(UserAiModelCatalog::class, function (MockInterface $mock): void {
            $mock->shouldReceive('listModels')
                ->twice()
                ->andReturn(['gemini-2.5-flash', 'gemini-2.5-pro']);
        });

        foreach (['Primary', 'Fallback'] as $index => $name) {
            $response = $this->actingAs($user)->postJson('/api/user/ai-prefs', [
                'name' => $name,
                'provider' => 'gemini',
                'api_key' => "unit-test-key-{$index}",
                'model' => 'gemini-2.5-flash',
            ]);

            $response->assertCreated()
                ->assertJsonPath('name', $name)
                ->assertJsonPath('is_active', $index === 0)
                ->assertJsonPath('available_models.1', 'gemini-2.5-pro')
                ->assertJsonMissingPath('api_key');
        }

        $this->assertSame(1, $user->aiConfigurations()->where('is_active', true)->count());
        $this->assertSame(2, $user->aiConfigurations()->count());
    }

    public function test_create_rejects_a_model_not_returned_for_the_credentials(): void
    {
        $user = $this->createUser();
        $this->mock(UserAiModelCatalog::class, function (MockInterface $mock): void {
            $mock->shouldReceive('listModels')->once()->andReturn(['allowed-model']);
        });

        $this->actingAs($user)->postJson('/api/user/ai-prefs', [
            'name' => 'Unlisted model',
            'provider' => 'anthropic',
            'api_key' => 'unit-test-key',
            'model' => 'unlisted-model',
        ])->assertUnprocessable()
            ->assertJsonPath('error', 'The selected model is not available for these API credentials.');

        $this->assertSame(0, $user->aiConfigurations()->count());
    }

    public function test_update_keeps_blank_credentials_and_provider_is_immutable(): void
    {
        $user = $this->createUser();
        $configuration = UserAiConfiguration::factory()->for($user)->bedrock()->create([
            'api_key' => 'stored-unit-test-key',
            'session_token' => 'stored-unit-test-session',
            'region' => 'us-west-2',
            'model' => 'bedrock-model',
        ]);
        $this->mock(UserAiModelCatalog::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('listModels');
        });

        $this->actingAs($user)->putJson("/api/user/ai-prefs/{$configuration->id}", [
            'name' => 'Renamed',
            'provider' => 'bedrock',
            'api_key' => '',
            'session_token' => '',
            'region' => 'us-west-2',
            'model' => 'bedrock-model',
        ])->assertOk()
            ->assertJsonPath('name', 'Renamed');

        $configuration->refresh();
        $this->assertSame('stored-unit-test-key', $configuration->api_key);
        $this->assertSame('stored-unit-test-session', $configuration->session_token);

        $this->actingAs($user)->putJson("/api/user/ai-prefs/{$configuration->id}", [
            'name' => 'Wrong provider',
            'provider' => 'gemini',
            'model' => 'bedrock-model',
        ])->assertUnprocessable()
            ->assertJsonPath('error', 'Provider cannot be changed after an API key configuration is created.');
    }

    public function test_model_refresh_reuses_only_the_owners_saved_bedrock_credentials(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();
        $configuration = UserAiConfiguration::factory()->for($user)->bedrock()->create([
            'api_key' => 'stored-unit-test-key',
            'session_token' => 'stored-unit-test-session',
            'region' => 'us-west-2',
            'api_key_invalid_at' => now(),
            'api_key_invalid_reason' => 'Rejected',
        ]);
        $foreignConfiguration = UserAiConfiguration::factory()->for($otherUser)->bedrock()->create();

        $this->mock(UserAiModelCatalog::class, function (MockInterface $mock): void {
            $mock->shouldReceive('listModels')
                ->once()
                ->with('bedrock', 'stored-unit-test-key', 'us-west-2', 'stored-unit-test-session')
                ->andReturn(['bedrock-model']);
        });

        $this->actingAs($user)->postJson('/api/user/ai-prefs/models', [
            'provider' => 'bedrock',
            'config_id' => $configuration->id,
        ])->assertOk()
            ->assertJsonPath('models.0', 'bedrock-model');
        $this->assertFalse($configuration->refresh()->hasInvalidApiKey());

        $this->actingAs($user)->postJson('/api/user/ai-prefs/models', [
            'provider' => 'bedrock',
            'config_id' => $foreignConfiguration->id,
        ])->assertNotFound();
    }

    public function test_update_can_explicitly_clear_a_stored_bedrock_session_token(): void
    {
        $user = $this->createUser();
        $configuration = UserAiConfiguration::factory()->for($user)->bedrock()->create([
            'api_key' => 'stored-unit-test-key',
            'session_token' => 'stored-unit-test-session',
            'region' => 'us-west-2',
            'model' => 'bedrock-model',
        ]);
        $this->mock(UserAiModelCatalog::class, function (MockInterface $mock): void {
            $mock->shouldReceive('listModels')
                ->once()
                ->with('bedrock', 'stored-unit-test-key', 'us-west-2', '')
                ->andReturn(['bedrock-model']);
        });

        $this->actingAs($user)->putJson("/api/user/ai-prefs/{$configuration->id}", [
            'name' => $configuration->name,
            'provider' => 'bedrock',
            'region' => 'us-west-2',
            'model' => 'bedrock-model',
            'clear_session_token' => true,
        ])->assertOk()
            ->assertJsonPath('has_session_token', false);

        $this->assertNull($configuration->refresh()->session_token);
    }

    public function test_activation_rejects_invalid_and_expired_credentials_and_delete_promotes_a_fallback(): void
    {
        $user = $this->createUser();
        $active = UserAiConfiguration::factory()->for($user)->gemini()->active()->create();
        $valid = UserAiConfiguration::factory()->for($user)->anthropic()->create();
        $invalid = UserAiConfiguration::factory()->for($user)->anthropic()->create([
            'api_key_invalid_at' => now(),
            'api_key_invalid_reason' => 'Rejected',
        ]);
        $expired = UserAiConfiguration::factory()->for($user)->anthropic()->expiredAt(now()->subDay())->create();

        $this->actingAs($user)->postJson("/api/user/ai-prefs/{$invalid->id}/activate")
            ->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/user/ai-prefs/{$expired->id}/activate")
            ->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/user/ai-prefs/{$valid->id}/activate")
            ->assertOk()
            ->assertJsonPath('is_active', true);

        $this->assertFalse($active->refresh()->is_active);
        $this->actingAs($user)->deleteJson("/api/user/ai-prefs/{$valid->id}")
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertSame(1, $user->aiConfigurations()->where('is_active', true)->count());
    }

    public function test_mutating_another_users_configuration_returns_not_found(): void
    {
        $owner = $this->createUser();
        $otherUser = $this->createUser();
        $configuration = UserAiConfiguration::factory()->for($owner)->gemini()->create();

        $this->actingAs($otherUser)->putJson("/api/user/ai-prefs/{$configuration->id}", [
            'name' => 'Attempted overwrite',
            'provider' => 'gemini',
            'model' => $configuration->model,
        ])->assertNotFound();
        $this->actingAs($otherUser)->postJson("/api/user/ai-prefs/{$configuration->id}/activate")
            ->assertNotFound();
        $this->actingAs($otherUser)->deleteJson("/api/user/ai-prefs/{$configuration->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('user_ai_configurations', [
            'id' => $configuration->id,
            'name' => $configuration->name,
        ]);
    }

    public function test_provider_exception_details_are_not_returned_or_logged(): void
    {
        $user = $this->createUser();
        Log::spy();
        $this->mock(UserAiModelCatalog::class, function (MockInterface $mock): void {
            $mock->shouldReceive('listModels')
                ->once()
                ->andThrow(new RuntimeException('credential-marker-must-not-leak'));
        });

        $response = $this->actingAs($user)->postJson('/api/user/ai-prefs/models', [
            'provider' => 'gemini',
            'api_key' => 'unit-test-key',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error', 'Failed to fetch models. Please check your credentials and try again.');
        $this->assertStringNotContainsString('credential-marker-must-not-leak', $response->getContent());
        Log::shouldHaveReceived('warning')->once()->with(
            'Failed to fetch AI models',
            ['provider' => 'gemini', 'exception' => RuntimeException::class],
        );
    }
}

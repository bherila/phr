<?php

namespace Tests\Feature;

use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Client;
use Tests\TestCase;

final class AgentApiFirstPartyClientSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_prints_copyable_credentials_and_a_store_now_reminder(): void
    {
        $this->assertSame(0, Artisan::call('phr:agent-api:register-first-party-client', $this->arguments()));
        $output = Artisan::output();
        $client = Client::query()->sole();

        $this->assertMatchesRegularExpression('/^Client ID:\s+'.preg_quote((string) $client->id, '/').'$/m', $output);
        $this->assertSame(1, preg_match('/^Client secret: (\S+)$/m', $output, $matches));
        $this->assertTrue(Hash::check($matches[1], $client->secret));
        $this->assertStringContainsString('Store the secret now', $output);
        $this->assertStringContainsString('Optional: add the client id', $output);
        $this->assertStringNotContainsString($client->secret, $output);
    }

    public function test_json_output_contains_credentials_and_setup_guidance_without_prose(): void
    {
        $this->assertSame(0, Artisan::call('phr:agent-api:register-first-party-client', ['--json' => true] + $this->arguments()));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $client = Client::query()->sole();

        $this->assertSame((string) $client->id, $payload['client_id']);
        $this->assertTrue(Hash::check($payload['client_secret'], $client->secret));
        $this->assertSame([AgentApiScopes::BILLING_READ, AgentApiScopes::IDENTITY_READ], $payload['scopes']);
        $this->assertSame([], $payload['warnings']);
        $this->assertStringContainsString('Store the secret now', $payload['secret_notice']);
        $this->assertFalse($payload['repeat_consent_skip_required']);
        $this->assertStringNotContainsString($client->secret, Artisan::output());
    }

    /** @return array<string, mixed> */
    private function arguments(): array
    {
        return [
            'name' => 'Synthetic Finance Consumer',
            '--redirect-uri' => ['https://finance.example.test/callback'],
            '--scope' => [AgentApiScopes::BILLING_READ, AgentApiScopes::IDENTITY_READ],
        ];
    }
}

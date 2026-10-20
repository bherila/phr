<?php

namespace Tests\Feature;

use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Client;
use Symfony\Component\Process\Process;
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

    public function test_billing_registration_warns_about_additional_scopes_without_changing_them(): void
    {
        $arguments = $this->arguments();
        $arguments['--scope'][] = AgentApiScopes::PATIENTS_READ;
        $this->assertSame(0, Artisan::call('phr:agent-api:register-first-party-client', $arguments));
        $this->assertStringContainsString('A finance client should need only billing:read and identity:read', Artisan::output());
        $this->assertContains(AgentApiScopes::PATIENTS_READ, Client::query()->sole()->scopes);
    }

    public function test_json_keeps_the_scope_warning_in_the_json_document(): void
    {
        $arguments = $this->arguments();
        $arguments['--scope'][] = AgentApiScopes::CLINICAL_READ;
        $this->assertSame(0, Artisan::call('phr:agent-api:register-first-party-client', ['--json' => true] + $arguments));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $payload['warnings']);
        $this->assertStringContainsString('A finance client should need only billing:read and identity:read', $payload['warnings'][0]);
        $this->assertContains(AgentApiScopes::CLINICAL_READ, $payload['scopes']);
    }

    public function test_non_billing_clients_do_not_receive_the_finance_scope_warning(): void
    {
        $arguments = $this->arguments();
        $arguments['--scope'] = [AgentApiScopes::CLINICAL_READ, AgentApiScopes::PATIENTS_READ];
        $this->assertSame(0, Artisan::call('phr:agent-api:register-first-party-client', $arguments));
        $this->assertStringNotContainsString('A finance client should need only', Artisan::output());
    }

    public function test_the_documented_shell_command_preserves_callback_query_parameters(): void
    {
        $readme = file_get_contents(base_path('README.md'));
        $this->assertIsString($readme);
        $this->assertSame(1, preg_match('/^php artisan phr:agent-api:register-first-party-client .*$/m', $readme, $matches));
        $command = str_replace(['<App name>', '<callback>'], ['Synthetic Finance Consumer', 'https://consumer.example.test/callback?tenant=synthetic&mode=connect'], $matches[0]);
        $process = new Process(['bash', '-c', 'php() { printf "%s\\n" "$@"; }'."\n".$command]);
        $process->mustRun();
        $this->assertSame([
            'artisan', 'phr:agent-api:register-first-party-client', 'Synthetic Finance Consumer',
            '--redirect-uri=https://consumer.example.test/callback?tenant=synthetic&mode=connect',
            '--scope=billing:read', '--scope=identity:read',
        ], explode("\n", trim($process->getOutput())));
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

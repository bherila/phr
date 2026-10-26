<?php

namespace Database\Seeders;

use App\Models\PhrPatient;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\OAuthClientConsents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;

/**
 * Synthetic accounts for the isolated Playwright database.
 *
 * This seeder is never called by DatabaseSeeder or production deployment. The
 * E2E global setup invokes it explicitly after creating a fresh SQLite file.
 */
class PlaywrightSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->forceCreate([
            'id' => 1,
            'name' => 'E2E Reserved Administrator',
            'email' => 'reserved-admin@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('not-used'),
            'user_role' => 'admin',
        ]);

        $this->createOAuthUser(
            id: 2,
            subject: 'playwright-owner',
            name: 'E2E Record Owner',
            email: 'record-owner@example.test',
        );

        $this->createOAuthUser(
            id: 3,
            subject: 'playwright-outsider',
            name: 'E2E Unrelated User',
            email: 'unrelated-user@example.test',
        );

        PhrPatient::query()->create([
            'owner_user_id' => 2,
            'display_name' => 'Synthetic Bootstrap Patient',
            'relationship' => 'self',
        ]);

        $this->createConnectedApps();
    }

    private function createConnectedApps(): void
    {
        $clients = app(ClientRepository::class);
        $consents = app(OAuthClientConsents::class);
        $rest = url('/api/v1');
        $mcp = url('/api/v1/mcp');

        // A retry gets a fresh target because global setup does not rerun after
        // the first attempt has withdrawn its consent.
        foreach ([0, 1] as $attempt) {
            $target = $clients->createAuthorizationCodeGrantClient(
                "Synthetic Connected App {$attempt}", ['https://connected-app.example.test/callback'], true,
            );
            $consents->grant(2, (string) $target->id, $rest, [AgentApiScopes::IDENTITY_READ]);
            $consents->grant(2, (string) $target->id, $mcp, [AgentApiScopes::MCP_USE]);
            $consents->grant(3, (string) $target->id, $rest, [AgentApiScopes::IDENTITY_READ]);
        }

        $other = $clients->createAuthorizationCodeGrantClient(
            'Synthetic Other Connected App', ['https://other-app.example.test/callback'], true,
        );
        $consents->grant(2, (string) $other->id, $rest, [AgentApiScopes::IDENTITY_READ]);

        $outsiderOnly = $clients->createAuthorizationCodeGrantClient(
            'Synthetic Outsider Only App', ['https://outsider-app.example.test/callback'], true,
        );
        $consents->grant(3, (string) $outsiderOnly->id, $rest, [AgentApiScopes::IDENTITY_READ]);

        $revoked = $clients->createAuthorizationCodeGrantClient(
            'Synthetic Previously Revoked App', ['https://revoked-app.example.test/callback'], true,
        );
        $consents->grant(2, (string) $revoked->id, $rest, [AgentApiScopes::IDENTITY_READ])
            ?->forceFill(['revoked_at' => now()])->save();

        $tokenOnly = $clients->createAuthorizationCodeGrantClient(
            'Synthetic Token Only App', ['https://token-only-app.example.test/callback'], true,
        );
        Token::query()->create([
            'id' => 'synthetic-playwright-legacy-token',
            'user_id' => 2,
            'client_id' => $tokenOnly->id,
            'scopes' => [AgentApiScopes::IDENTITY_READ],
            'revoked' => false,
            'expires_at' => now()->addHour(),
        ]);
    }

    private function createOAuthUser(int $id, string $subject, string $name, string $email): void
    {
        User::query()->forceCreate([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make('not-used'),
            'user_role' => 'user',
            'oauth_provider' => 'playwright',
            'oauth_subject' => $subject,
        ]);
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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

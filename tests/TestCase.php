<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\WithCachedConfig;

/**
 * Base test case for all tests.
 *
 * Extends SafeTestCase which verifies at runtime that every test uses
 * an in-memory SQLite connection, preventing accidental use of MySQL.
 */
abstract class TestCase extends SafeTestCase
{
    use WithCachedConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Create a user with admin role for testing.
     *
     * @param  array  $attributes  Additional attributes for the user
     */
    protected function createAdminUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'user_role' => 'admin',
        ], $attributes));
    }

    /**
     * Create a regular user for testing.
     *
     * @param  array  $attributes  Additional attributes for the user
     */
    protected function createUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'user_role' => 'user',
        ], $attributes));
    }
}

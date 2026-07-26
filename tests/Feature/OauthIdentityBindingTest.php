<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Accounts here originate on another site and were copied across from its database. The
 * association back to that site must survive an email change, so it is keyed on the
 * provider's immutable subject rather than the address.
 *
 * These tests exist because the failure is silent: bind on email, and nothing breaks until
 * someone changes theirs — at which point the account is orphaned, or worse, a reassigned
 * address hands one person's saved data to another.
 */
class OauthIdentityBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_identity_survives_an_email_change(): void
    {
        $user = User::factory()->create(['email' => 'before@example.com']);
        DB::table('users')->where('id', $user->id)->update([
            'oauth_provider' => 'bherila',
            'oauth_subject' => '1',
        ]);

        $user->forceFill(['email' => 'after@example.com'])->save();

        $resolved = DB::table('users')
            ->where('oauth_provider', 'bherila')
            ->where('oauth_subject', '1')
            ->first();

        $this->assertNotNull($resolved, 'The provider subject must still resolve after an email change.');
        $this->assertSame($user->id, (int) $resolved->id);
        $this->assertSame('after@example.com', $resolved->email);
    }

    /**
     * Two accounts must not be able to claim the same provider identity — that is what would
     * let one person's saved data resolve to another.
     */
    public function test_a_provider_subject_cannot_be_claimed_twice(): void
    {
        $first = User::factory()->create();
        DB::table('users')->where('id', $first->id)->update([
            'oauth_provider' => 'bherila',
            'oauth_subject' => '1',
        ]);

        $second = User::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $second->id)->update([
            'oauth_provider' => 'bherila',
            'oauth_subject' => '1',
        ]);
    }

    /**
     * Nullable columns keep this safe to deploy before any authentication exists.
     */
    public function test_accounts_without_a_provider_identity_are_allowed(): void
    {
        User::factory()->create();
        User::factory()->create();

        $this->assertSame(2, DB::table('users')->whereNull('oauth_subject')->count());
    }
}

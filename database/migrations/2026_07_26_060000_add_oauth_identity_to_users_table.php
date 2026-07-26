<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bind accounts to the identity provider by an immutable subject, not by email.
 *
 * This app's users originate on another site (the identity provider), and its data was
 * copied across from that site's database. The obvious way to re-associate the two is
 * email — and it is wrong in both directions. If someone changes their address, the
 * account is orphaned from its owner. If an address is ever reassigned, whoever holds it
 * next inherits the previous owner's data. OIDC specifies the `sub` claim precisely
 * because of this, and states that clients must not rely on `email` being stable.
 *
 * `oauth_subject` stores the provider's immutable identifier for the account. Email stays
 * as a display and contact attribute, refreshed from the provider on login, never used to
 * match. Changing an address then propagates without re-binding anything.
 *
 * The columns are nullable so this is safe to apply before any authentication exists; the
 * unique index is composite so a future second provider cannot collide with the first.
 * A single provider is assumed for now — if that ever changes to several *per user*, this
 * wants promoting to its own `oauth_identities` table rather than more columns here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('oauth_provider', 64)->nullable()->after('email');
            $table->string('oauth_subject', 191)->nullable()->after('oauth_provider');

            $table->unique(['oauth_provider', 'oauth_subject'], 'users_oauth_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_oauth_identity_unique');
            $table->dropColumn(['oauth_provider', 'oauth_subject']);
        });
    }
};

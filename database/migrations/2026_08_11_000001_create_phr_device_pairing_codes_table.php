<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes minted by DevicePairingController::approve() and redeemed by
 * DevicePairingExchangeController for a per-device API key (phr_device_keys).
 *
 * This replaces `php artisan mcp:token:issue` + pasting a key over shell access:
 * the Mac app opens a browser URL, the signed-in user approves, and the app
 * exchanges the resulting code for its own key without a human ever handling
 * the credential.
 *
 * `code_hash` follows the same convention as `users.mcp_api_key`: a SHA-256 of a
 * 64-character random string, so a database read never yields a usable code.
 * `code_challenge` is the PKCE challenge the pairing app generated; the exchange
 * endpoint verifies it against the verifier the app kept to itself, so a code
 * intercepted in transit (e.g. from a URL handler log) is still useless without
 * the verifier that never left the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_device_pairing_codes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('device_id', 64)->comment('Client-chosen device identifier; not a secret.');
            $table->string('name', 100)->comment('Human-readable device name shown on the approve page.');
            $table->char('code_hash', 64)->comment('SHA-256 of the one-time plaintext code; never store the code itself.');
            $table->string('code_challenge', 128)->comment('PKCE S256 challenge; verified against code_verifier at exchange time.');
            // dateTime for the same MySQL reason as phr_device_keys.expires_at
            // (see that migration). This one only survived production's first
            // migrate because it happened to be the table's FIRST timestamp
            // column, which MySQL silently gifts CURRENT_TIMESTAMP — an
            // accident of column order, not a design.
            $table->dateTime('expires_at')->comment('5 minutes after approval. A code with no expiry is never issued.');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            // Explicit short identifiers: MySQL caps them at 64 chars and
            // Laravel's generated names are long for this table prefix.
            $table->foreign('user_id', 'phr_dpc_user_fk')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->unique('code_hash', 'phr_dpc_code_hash_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_device_pairing_codes');
    }
};

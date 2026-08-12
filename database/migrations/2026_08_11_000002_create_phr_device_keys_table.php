<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-device API keys minted by DevicePairingExchangeController, one per
 * (user, device_id) pair.
 *
 * These sit alongside `users.mcp_api_key` (a single per-user key, still
 * supported for the legacy manual-issue flow) rather than replacing it: a
 * device key is scoped to one paired device, so revoking or expiring it
 * cannot take out a user's other devices or their MCP integration.
 *
 * `token_hash` follows `users.mcp_api_key`'s convention (SHA-256 of a
 * 64-character random string via User::hashMcpToken()) and `expires_at` is
 * NOT NULL for the same reason `mcp_api_key_expires_at`'s comment gives: a key
 * that cannot expire is one nobody notices has been stolen, so PhrDeviceKey
 * fails closed on a missing expiry rather than treating it as eternal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_device_keys', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('device_id', 64)->comment('Client-chosen device identifier; not a secret.');
            $table->string('name', 100)->comment('Human-readable device name shown in device management.');
            $table->char('token_hash', 64)->comment('SHA-256 of the plaintext key, shown to the device exactly once.');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->comment('A key with no expiry is rejected; see isActive().');
            $table->timestamp('revoked_at')->nullable()->comment('Set by the user-facing device management endpoints.');
            $table->timestamps();

            // Explicit short identifiers: MySQL caps them at 64 chars and
            // Laravel's generated names are long for this table prefix.
            $table->foreign('user_id', 'phr_dk_user_fk')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->unique('token_hash', 'phr_dk_token_hash_uq');
            // Re-pairing the same device replaces its key: the exchange
            // controller deletes any existing row for this pair before
            // inserting, so this uniquely enforces "one key per device".
            $table->unique(['user_id', 'device_id'], 'phr_dk_user_device_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_device_keys');
    }
};

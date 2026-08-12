<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production ran create_phr_device_pairing_codes while expires_at was still a
 * TIMESTAMP. As that table's first timestamp column, MySQL silently gave it
 * DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP — so consuming a code
 * (an UPDATE) would quietly rewrite its expiry. Harmless today only because
 * consumed_at gates redemption before expiry is ever re-read, but that is an
 * accident, not a contract. Normalize to the DATETIME the (since-corrected)
 * create migration now specifies, so fresh installs and production agree.
 *
 * On a database created after the correction this is a DATETIME -> DATETIME
 * no-op; SQLite (the test suite) ignores the comment and tolerates the change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phr_device_pairing_codes', function (Blueprint $table): void {
            $table->dateTime('expires_at')
                ->comment('5 minutes after approval. A code with no expiry is never issued.')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('phr_device_pairing_codes', function (Blueprint $table): void {
            $table->timestamp('expires_at')->change();
        });
    }
};

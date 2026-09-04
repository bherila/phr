<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function table(): string
    {
        return (string) config('bherila-auth.passkeys.table', 'auth_passkeys');
    }

    public function up(): void
    {
        $table = $this->table();

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'rp_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $table): void {
            $table->string('rp_id', 255)->nullable()->after('aaguid');
        });
    }

    public function down(): void
    {
        $table = $this->table();

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'rp_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $table): void {
            $table->dropColumn('rp_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string INDEX = 'phr_docs_agent_hash_idx';

    public function up(): void
    {
        if (! Schema::hasIndex('phr_documents', self::INDEX)) {
            Schema::table('phr_documents', function (Blueprint $table): void {
                $table->index(['patient_id', 'import_source', 'file_hash'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('phr_documents', self::INDEX)) {
            Schema::table('phr_documents', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};

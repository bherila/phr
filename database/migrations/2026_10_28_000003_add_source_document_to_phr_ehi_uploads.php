<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An agent imports a stored ZIP document through imports.create: its bytes are
 * staged as an upload like a browser's. The document it came from makes that
 * idempotent, one upload per document, as the AI path keeps one job per document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phr_ehi_uploads', function (Blueprint $table): void {
            $table->foreignId('source_document_id')->nullable()->after('package_id')->constrained('phr_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('phr_ehi_uploads', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_document_id');
        });
    }
};

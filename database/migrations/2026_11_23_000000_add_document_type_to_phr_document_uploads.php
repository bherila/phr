<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A signed upload may name the document type it will be finalized as (phr#407),
 * so the choice is kept on the session between open and finalize. Additive and
 * nullable: an existing session falls back to the type its kind implies. The
 * table holds transient sessions only and is not part of a native backup.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('phr_document_uploads', 'document_type')) {
            return;
        }

        Schema::table('phr_document_uploads', function (Blueprint $table): void {
            $table->string('document_type', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phr_document_uploads', function (Blueprint $table): void {
            $table->dropColumn('document_type');
        });
    }
};

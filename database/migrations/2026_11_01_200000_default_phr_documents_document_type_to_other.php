<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `phr_documents.document_type` was created with the default `general`, which
 * is not in PhrDocument::DOCUMENT_TYPES. Every model write now names a type
 * (the model defaults it to `other`), but a raw insert that omitted the column
 * would still store `general`. This sets the column default to `other`.
 *
 * No stored row needs rewriting: a read-only count of production taken before
 * this change found every `document_type` already in the list.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('phr_documents')) {
            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // A metadata-only change: no table rebuild, and the column keeps
            // its type, nullability and collation exactly as they are.
            DB::statement("ALTER TABLE `phr_documents` ALTER COLUMN `document_type` SET DEFAULT 'other'");

            return;
        }

        Schema::table('phr_documents', function (Blueprint $table): void {
            $table->string('document_type', 80)->default('other')->change();
        });
    }

    public function down(): void
    {
        // Deliberately a no-op: `general` was never an allowed document type,
        // so restoring it as the default would only reopen the gap.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('phr_documents')) {
            return;
        }

        Schema::table('phr_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('phr_documents', 'observed_at')) {
                $table->timestamp('observed_at')->nullable()->after('document_type');
            }

            if (! Schema::hasColumn('phr_documents', 'byte_size')) {
                $table->unsignedBigInteger('byte_size')->default(0)->after('mime_type');
            }

            if (! Schema::hasColumn('phr_documents', 'file_hash')) {
                $table->string('file_hash', 64)->nullable()->after('byte_size');
            }

            if (! Schema::hasColumn('phr_documents', 'tags')) {
                $table->json('tags')->nullable()->after('summary');
            }

            if (! Schema::hasColumn('phr_documents', 'deleted_at')) {
                $table->softDeletes();
            }

            if (! Schema::hasIndex('phr_documents', 'phr_docs_patient_type_idx')) {
                $table->index(['patient_id', 'document_type'], 'phr_docs_patient_type_idx');
            }

            if (! Schema::hasIndex('phr_documents', 'phr_docs_patient_source_idx')) {
                $table->index(['patient_id', 'source'], 'phr_docs_patient_source_idx');
            }

            if (! Schema::hasIndex('phr_documents', 'phr_docs_patient_observed_idx')) {
                $table->index(['patient_id', 'observed_at'], 'phr_docs_patient_observed_idx');
            }
        });

        if (Schema::hasColumn('phr_documents', 'file_size_bytes')) {
            DB::table('phr_documents')
                ->where('byte_size', 0)
                ->update(['byte_size' => DB::raw('file_size_bytes')]);
        }

        if (Schema::hasColumn('phr_documents', 'sha256')) {
            DB::table('phr_documents')
                ->whereNull('file_hash')
                ->update(['file_hash' => DB::raw('sha256')]);
        }

        DB::table('phr_documents')
            ->whereNull('source')
            ->update(['source' => 'manual_upload']);

        DB::table('phr_documents')
            ->whereIn('source', ['genai', 'genai_import'])
            ->update(['source' => 'genai_import']);

        DB::table('phr_documents')
            ->whereIn('source', ['cli', 'cli_pdf'])
            ->update(['source' => 'manual_upload']);

        DB::table('phr_documents')
            ->whereNotIn('document_type', [
                'lab_report',
                'office_visit_note',
                'discharge_summary',
                'imaging_report',
                'prescription',
                'insurance',
                'consent',
                'other',
            ])
            ->update(['document_type' => 'other']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('phr_documents')) {
            return;
        }

        Schema::table('phr_documents', function (Blueprint $table): void {
            if (Schema::hasIndex('phr_documents', 'phr_docs_patient_observed_idx')) {
                $table->dropIndex('phr_docs_patient_observed_idx');
            }

            if (Schema::hasIndex('phr_documents', 'phr_docs_patient_source_idx')) {
                $table->dropIndex('phr_docs_patient_source_idx');
            }

            if (Schema::hasIndex('phr_documents', 'phr_docs_patient_type_idx')) {
                $table->dropIndex('phr_docs_patient_type_idx');
            }

            foreach (['observed_at', 'byte_size', 'file_hash', 'tags', 'deleted_at'] as $column) {
                if (Schema::hasColumn('phr_documents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

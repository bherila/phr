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

        if (Schema::hasColumn('phr_documents', 'file_size_bytes') && Schema::hasColumn('phr_documents', 'byte_size')) {
            DB::table('phr_documents')
                ->where('file_size_bytes', '>', 0)
                ->where('byte_size', 0)
                ->update(['byte_size' => DB::raw('file_size_bytes')]);
        }

        if (Schema::hasColumn('phr_documents', 'sha256') && Schema::hasColumn('phr_documents', 'file_hash')) {
            DB::table('phr_documents')
                ->whereNotNull('sha256')
                ->whereNull('file_hash')
                ->update(['file_hash' => DB::raw('sha256')]);
        }

        Schema::table('phr_documents', function (Blueprint $table): void {
            if (Schema::hasColumn('phr_documents', 'file_size_bytes')) {
                $table->dropColumn('file_size_bytes');
            }

            if (Schema::hasColumn('phr_documents', 'sha256')) {
                $table->dropColumn('sha256');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('phr_documents')) {
            return;
        }

        Schema::table('phr_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('phr_documents', 'file_size_bytes')) {
                $table->unsignedBigInteger('file_size_bytes')->default(0)->after('file_hash');
            }

            if (! Schema::hasColumn('phr_documents', 'sha256')) {
                $table->string('sha256', 64)->nullable()->after('file_size_bytes');
            }
        });

        if (Schema::hasColumn('phr_documents', 'file_size_bytes') && Schema::hasColumn('phr_documents', 'byte_size')) {
            DB::table('phr_documents')
                ->where('byte_size', '>', 0)
                ->where('file_size_bytes', 0)
                ->update(['file_size_bytes' => DB::raw('byte_size')]);
        }

        if (Schema::hasColumn('phr_documents', 'sha256') && Schema::hasColumn('phr_documents', 'file_hash')) {
            DB::table('phr_documents')
                ->whereNotNull('file_hash')
                ->whereNull('sha256')
                ->update(['sha256' => DB::raw('file_hash')]);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('genai_execution_mode', 16)->default('api')->after('genai_daily_quota_limit');
        });

        Schema::table('genai_import_jobs', function (Blueprint $table): void {
            $table->string('execution_mode', 16)->default('api')->after('processing_tier');
            $table->unsignedInteger('mcp_generation')->default(0)->after('execution_mode');
            $table->uuid('mcp_request_id')->nullable()->unique()->after('mcp_generation');
            $table->foreign('mcp_request_id')
                ->references('id')
                ->on('genai_mcp_requests')
                ->nullOnDelete();
            $table->index(['user_id', 'execution_mode', 'status'], 'genai_import_execution_idx');
        });
    }

    public function down(): void
    {
        Schema::table('genai_import_jobs', function (Blueprint $table): void {
            $table->dropForeign(['mcp_request_id']);
            $table->dropIndex('genai_import_execution_idx');
            $table->dropUnique(['mcp_request_id']);
            $table->dropColumn(['execution_mode', 'mcp_generation', 'mcp_request_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('genai_execution_mode');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('gemini_api_key')->nullable()->after('password');
            $table->string('mcp_api_key')->nullable()->after('gemini_api_key');
            $table->unsignedInteger('genai_daily_quota_limit')->nullable()->after('mcp_api_key')
                ->comment('Per-user GenAI daily quota limit. NULL = use system default.');
            $table->string('user_role')->default('')->after('genai_daily_quota_limit');
            $table->timestamp('last_login_date')->nullable()->after('user_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['gemini_api_key', 'mcp_api_key', 'genai_daily_quota_limit', 'user_role', 'last_login_date']);
        });
    }
};

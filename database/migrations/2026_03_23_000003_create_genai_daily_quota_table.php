<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genai_daily_quota', function (Blueprint $table) {
            $table->date('usage_date')->primary();
            $table->unsignedInteger('request_count')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('genai_daily_quota');
    }
};

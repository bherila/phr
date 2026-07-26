<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genai_import_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('ai_configuration_id')->nullable();
            $table->string('ai_provider', 32)->nullable();
            $table->string('ai_model', 255)->nullable();
            $table->string('job_type', 64);
            $table->string('file_hash', 64);
            $table->string('original_filename');
            $table->string('s3_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size_bytes');
            $table->text('context_json')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('error_message')->nullable();
            $table->longText('raw_response')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->date('scheduled_for')->nullable();
            $table->timestamp('parsed_at')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            // Which tier handled the document. PHR has no deterministic-parser tier (unlike
            // the monorepo's shared version), so this is always ai_only for now — the column
            // is kept so the shape matches GenAiImportJob::TIER_* constants if PHR ever adds one.
            $table->string('processing_tier', 32)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('ai_configuration_id')
                ->references('id')
                ->on('user_ai_configurations')
                ->nullOnDelete();

            $table->index(['user_id', 'status']);
            $table->index('file_hash');
            $table->index(['scheduled_for', 'status']);
            $table->index('ai_configuration_id');
            $table->index(['ai_configuration_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('genai_import_jobs');
    }
};

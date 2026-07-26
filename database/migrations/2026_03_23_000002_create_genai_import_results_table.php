<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genai_import_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_id');
            $table->unsignedInteger('result_index');
            $table->longText('result_json');
            $table->string('status', 32)->default('pending_review');
            $table->string('produced_by', 16)->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->foreign('job_id')->references('id')->on('genai_import_jobs')->onDelete('cascade');
            $table->index(['job_id', 'result_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('genai_import_results');
    }
};

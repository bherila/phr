<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_native_restore_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_storage_disk', 64)->default('phr_exports');
            $table->string('source_storage_path', 1024)->nullable();
            $table->unsignedBigInteger('source_file_size_bytes');
            $table->unsignedBigInteger('uploaded_bytes')->default(0);
            // Unknown until the queued, streamed validation pass completes.
            $table->char('archive_sha256', 64)->nullable();
            $table->unsignedSmallInteger('schema_version')->nullable();
            $table->uuid('patient_native_id')->nullable();
            // Audit identity deliberately has no patient FK: the restored root may
            // be deleted later without erasing proof of the restore operation.
            $table->unsignedBigInteger('target_patient_root_id')->nullable();
            $table->char('plan_digest', 64)->nullable();
            $table->json('plan_counts_json')->nullable();
            $table->unsignedInteger('access_grant_count')->default(0);
            $table->boolean('restore_access_grants')->default(false);
            $table->string('status', 32);
            $table->string('failure_category', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['actor_user_id', 'status'], 'phr_native_restores_actor_status_idx');
            $table->index(['status', 'expires_at'], 'phr_native_restores_status_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_native_restore_attempts');
    }
};

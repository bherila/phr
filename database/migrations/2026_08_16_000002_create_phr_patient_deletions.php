<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phr_native_backup_audits', function (Blueprint $table): void {
            $table->unsignedBigInteger('actor_user_id')->nullable()->change();
        });

        Schema::create('phr_patient_deletions', function (Blueprint $table): void {
            $table->id();
            // The actor is nullable so full-account deletion can anonymize the
            // durable event without erasing proof of the patient deletion.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('patient_root_id');
            $table->char('preview_digest', 64);
            $table->json('record_counts_json');
            $table->unsignedInteger('active_share_count');
            $table->unsignedInteger('artifact_count');
            $table->unsignedBigInteger('artifact_bytes');
            $table->string('status', 32);
            $table->string('failure_category', 64)->nullable();
            $table->timestamp('deleted_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['actor_user_id', 'created_at'], 'phr_patient_deletions_actor_created_idx');
            $table->index(['patient_root_id', 'created_at'], 'phr_patient_deletions_patient_created_idx');
            $table->index(['status', 'created_at'], 'phr_patient_deletions_status_created_idx');
        });

        Schema::create('phr_patient_deletion_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deletion_id')->constrained('phr_patient_deletions')->cascadeOnDelete();
            $table->string('storage_disk', 64);
            $table->string('storage_key', 1024);
            $table->char('storage_key_hash', 64);
            $table->unsignedBigInteger('expected_bytes')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('status', 24)->default('pending');
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['deletion_id', 'storage_disk', 'storage_key_hash'],
                'phr_patient_deletion_artifact_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_patient_deletion_artifacts');
        Schema::dropIfExists('phr_patient_deletions');

        // Actor anonymization is intentionally irreversible. Restoring the old
        // NOT NULL constraint would make rollback fail as soon as any user had
        // been deleted after this migration, so the compatible schema remains
        // nullable even when the Phase 3 tables are rolled back.
    }
};

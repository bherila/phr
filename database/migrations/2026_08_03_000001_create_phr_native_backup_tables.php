<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_native_backups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 24);
            $table->unsignedSmallInteger('schema_version');
            $table->string('storage_disk', 64)->default('phr_exports');
            $table->string('storage_path', 1024)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->char('archive_sha256', 64)->nullable();
            $table->json('counts_json')->nullable();
            $table->string('failure_category', 64)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'created_at'], 'phr_native_backups_patient_created_idx');
            $table->index('expires_at', 'phr_native_backups_expires_idx');
        });

        Schema::create('phr_native_record_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->string('record_table', 96);
            $table->unsignedBigInteger('record_id');
            $table->uuid('native_id');
            $table->timestamps();

            $table->unique(['patient_id', 'record_table', 'record_id'], 'phr_native_identity_source_uq');
            $table->unique(['patient_id', 'native_id'], 'phr_native_identity_native_uq');
        });

        // Audit rows deliberately have no patient/user foreign keys: a later aggregate
        // deletion must not erase the minimal proof that the operation occurred.
        Schema::create('phr_native_backup_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_user_id');
            $table->unsignedBigInteger('patient_root_id');
            $table->string('operation', 32);
            $table->unsignedSmallInteger('schema_version');
            $table->char('archive_sha256', 64)->nullable();
            $table->json('counts_json')->nullable();
            $table->string('outcome', 24);
            $table->string('failure_category', 64)->nullable();
            $table->timestamps();

            $table->index(['patient_root_id', 'created_at'], 'phr_native_audits_patient_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_native_backup_audits');
        Schema::dropIfExists('phr_native_record_identities');
        Schema::dropIfExists('phr_native_backups');
    }
};

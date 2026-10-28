<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Browser uploads of an EHI Tables ZIP (issue #235, PR 1). A row stages the
 * archive in chunks, then a queued job runs the same intake service as the CLI.
 * The staged archive is transient: intake preserves every member as its own blob,
 * so the archive is deleted once intake finishes, and an abandoned one expires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_ehi_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            // The package intake created or replayed. A package can outlive the upload.
            $table->foreignId('package_id')->nullable()->constrained('phr_ehi_packages')->nullOnDelete();
            $table->string('storage_disk', 64);
            // Null once the staged archive is deleted.
            $table->string('storage_path', 1024)->nullable();
            $table->unsignedBigInteger('file_size_bytes');
            $table->unsignedBigInteger('uploaded_bytes')->default(0);
            $table->string('status', 32);
            // A fixed refusal or failure code, never a message.
            $table->string('failure_reason', 64)->nullable();
            // replayed, resumed and repaired from the intake result.
            $table->json('result_json')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'status'], 'phr_ehi_uploads_patient_status_idx');
            $table->index(['status', 'expires_at'], 'phr_ehi_uploads_status_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_ehi_uploads');
    }
};

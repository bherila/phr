<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_blob_migrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->string('artifact_class', 32);
            $table->string('storage_disk', 64);
            $table->string('reference_table', 64);
            $table->unsignedBigInteger('reference_id');
            $table->string('reference_column', 64);
            $table->string('source_key', 1024);
            $table->string('destination_key', 1024);
            $table->unsignedBigInteger('source_size_bytes');
            $table->char('source_sha256', 64);
            // cPanel's MariaDB configuration rejects consecutive non-null
            // TIMESTAMP columns because it assigns an invalid implicit zero
            // default to the second one. These are application timestamps, so
            // DATETIME preserves the intended values without server defaults.
            $table->dateTime('migrated_at');
            $table->dateTime('retain_until');
            $table->dateTime('legacy_deleted_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['reference_table', 'reference_id', 'reference_column'],
                'phr_blob_migrations_reference_uq',
            );
            $table->index(['storage_disk', 'legacy_deleted_at'], 'phr_blob_migrations_disk_live_idx');
            $table->index(['retain_until', 'legacy_deleted_at'], 'phr_blob_migrations_retention_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_blob_migrations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phr_eobs', function (Blueprint $table): void {
            $table->unsignedInteger('correction_revision')->default(0);
        });
        Schema::create('phr_eob_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('eob_id')->constrained('phr_eobs')->cascadeOnDelete();
            $table->unsignedInteger('ordinal');
            $table->text('corrected_at');
            $table->text('previous_version');
            $table->json('actor_provenance');
            $table->json('client_provenance');
            $table->json('before_data');
            $table->json('after_data');
            $table->foreignId('before_document_id')->nullable()->constrained('phr_documents')->nullOnDelete();
            $table->foreignId('after_document_id')->nullable()->constrained('phr_documents')->nullOnDelete();
            $table->json('before_document_provenance')->nullable();
            $table->json('after_document_provenance')->nullable();
            $table->unique(['eob_id', 'ordinal']);
        });
        Schema::create('phr_eob_correction_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('correction_id')->constrained('phr_eob_corrections')->cascadeOnDelete();
            $table->string('side', 6);
            $table->unsignedInteger('position');
            $table->json('payload');
            $table->unique(['correction_id', 'side', 'position'], 'phr_eob_history_line_position_uid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_eob_correction_lines');
        Schema::dropIfExists('phr_eob_corrections');
        Schema::table('phr_eobs', fn (Blueprint $table) => $table->dropColumn('correction_revision'));
    }
};

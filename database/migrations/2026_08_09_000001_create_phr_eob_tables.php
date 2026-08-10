<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_eobs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('source_document_id')->nullable();
            $table->string('import_source', 50);
            $table->string('external_id');
            $table->string('claim_number', 80)->nullable();
            $table->string('claim_type', 30)->default('unknown');
            $table->string('administrator', 255)->nullable();
            $table->string('carrier', 255)->nullable();
            $table->string('plan_name', 255)->nullable();
            $table->string('group_number', 100)->nullable();
            $table->string('member_id', 100)->nullable();
            $table->string('participant_name', 255)->nullable();
            $table->string('patient_name', 255)->nullable();
            $table->string('provider_name', 255)->nullable();
            $table->string('payment_to', 255)->nullable();
            $table->string('provider_tin', 50)->nullable();
            $table->string('check_number', 100)->nullable();
            $table->decimal('check_amount', 14, 2)->nullable();
            $table->date('print_date')->nullable();
            $table->date('processed_date')->nullable();
            $table->decimal('total_charges', 14, 2)->nullable();
            $table->decimal('total_provider_discount', 14, 2)->nullable();
            $table->decimal('total_ineligible_amount', 14, 2)->nullable();
            $table->decimal('total_deductible_applied', 14, 2)->nullable();
            $table->decimal('total_copay_applied', 14, 2)->nullable();
            $table->decimal('total_benefit_percent', 7, 2)->nullable();
            $table->decimal('total_carrier_payment', 14, 2)->nullable();
            $table->decimal('total_plan_payment', 14, 2)->nullable();
            $table->decimal('total_patient_responsibility', 14, 2)->nullable();
            $table->json('parsed_data')->nullable();
            $table->longText('raw_text')->nullable();
            $table->string('parser_version', 50)->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'processed_date'], 'phr_eobs_patient_processed_idx');
            $table->index(['patient_id', 'claim_number'], 'phr_eobs_patient_claim_idx');
            $table->unique(['patient_id', 'import_source', 'external_id'], 'phr_eobs_import_uid');

            $table->foreign('patient_id', 'phr_eobs_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('user_id', 'phr_eobs_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('source_document_id', 'phr_eobs_source_document_fk')->references('id')->on('phr_documents')->nullOnDelete();
        });

        Schema::create('phr_eob_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('eob_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedInteger('line_number');
            $table->string('procedure_code', 30);
            $table->string('revenue_code', 30)->nullable();
            $table->string('code_type', 40)->default('unknown');
            $table->string('description', 255)->nullable();
            $table->date('service_start')->nullable();
            $table->date('service_end')->nullable();
            $table->decimal('total_charges', 14, 2)->nullable();
            $table->decimal('provider_discount', 14, 2)->nullable();
            $table->decimal('ineligible_amount', 14, 2)->nullable();
            $table->json('notes_applied')->nullable();
            $table->decimal('deductible_applied', 14, 2)->nullable();
            $table->decimal('copay_applied', 14, 2)->nullable();
            $table->decimal('benefit_percent', 7, 2)->nullable();
            $table->decimal('carrier_payment', 14, 2)->nullable();
            $table->decimal('plan_payment', 14, 2)->nullable();
            $table->decimal('patient_responsibility', 14, 2)->nullable();
            $table->json('parsed_data')->nullable();
            $table->text('raw_text')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'service_start'], 'phr_eob_lines_patient_service_idx');
            $table->index(['patient_id', 'procedure_code'], 'phr_eob_lines_patient_code_idx');
            $table->unique(['eob_id', 'line_number'], 'phr_eob_lines_eob_line_uid');

            $table->foreign('eob_id', 'phr_eob_lines_eob_fk')->references('id')->on('phr_eobs')->cascadeOnDelete();
            $table->foreign('patient_id', 'phr_eob_lines_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_eob_lines');
        Schema::dropIfExists('phr_eobs');
    }
};

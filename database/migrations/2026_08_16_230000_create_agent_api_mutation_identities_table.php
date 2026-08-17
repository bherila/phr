<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_api_mutation_identities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->uuid('oauth_client_id');
            $table->string('operation', 64);
            // External identifiers may themselves be sensitive. A keyed digest
            // supports client-scoped retry lookup without retaining or exposing it.
            $table->char('external_id_hash', 64);
            $table->char('request_hash', 64);
            $table->string('target_table', 64);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->unique(
                ['patient_id', 'oauth_client_id', 'operation', 'external_id_hash'],
                'agent_mutation_identity_uq',
            );
            $table->index(['target_table', 'target_id'], 'agent_mutation_target_idx');
            $table->foreign('patient_id', 'agent_mutation_patient_fk')
                ->references('id')->on('phr_patients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_api_mutation_identities');
    }
};

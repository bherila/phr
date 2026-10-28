<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Queued EHI previews requested from the browser (issue #235, PR 1). A preview
 * can outlast a proxied web request, so the route records a run and a queued job
 * runs EhiProposalService::preview. The run carries the outcome the browser
 * polls for; the preview itself is recorded on the package and its proposals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_ehi_preview_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('phr_ehi_packages')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32);
            // A fixed refusal or failure code, never a message.
            $table->string('failure_reason', 64)->nullable();
            // Proposal counts, statuses, finding counts and the preview digest.
            $table->json('result_json')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['package_id', 'status'], 'phr_ehi_preview_runs_package_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_ehi_preview_runs');
    }
};

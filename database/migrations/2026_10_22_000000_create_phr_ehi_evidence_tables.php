<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Epic EHI Tables evidence, proposals, and record links (issue #213, milestone 2).
 *
 * Entities and revisions exist only for tables a mapping contract covers; every
 * other table stays preserved bytes plus accounting. An entity is identified by
 * its full dictionary primary key within one declared source, never by a
 * physical row number; a revision is an immutable content snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_ehi_entities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('phr_ehi_sources')->cascadeOnDelete();
            $table->string('table_name', 128);
            // sha256 of the canonical JSON of the full primary key (column => raw value).
            $table->char('key_digest', 64);
            $table->json('key_json');
            $table->timestamps();

            $table->unique(['source_id', 'table_name', 'key_digest'], 'phr_ehi_entities_source_key_uq');
        });

        Schema::create('phr_ehi_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained('phr_ehi_entities')->cascadeOnDelete();
            $table->foreignId('first_package_id')->nullable()->constrained('phr_ehi_packages')->nullOnDelete();
            // sha256 of the canonical JSON of every column's raw value.
            $table->char('content_digest', 64);
            // Column => raw string, or null for an explicit null marker. Key order is
            // not preserved (MySQL JSON reorders keys); the source column order is the
            // table account's header_columns_json, and digests sort keys anyway.
            $table->json('values_json');
            $table->char('dictionary_fingerprint', 64)->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'content_digest'], 'phr_ehi_revisions_entity_content_uq');
        });

        // Where a revision was seen: physical coordinates are locators, not identity.
        Schema::create('phr_ehi_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('phr_ehi_packages')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('phr_ehi_package_members')->cascadeOnDelete(); // sensitive-scan-ignore: package member FK, not an insurance member id
            $table->foreignId('revision_id')->constrained('phr_ehi_revisions')->cascadeOnDelete();
            $table->unsignedBigInteger('line_number');
            $table->unsignedBigInteger('byte_offset');
            $table->string('disposition', 32);
            $table->timestamps();

            $table->unique(['member_id', 'line_number'], 'phr_ehi_occurrences_member_line_uq');
            $table->index(['package_id', 'disposition'], 'phr_ehi_occurrences_package_disp_idx');
        });

        Schema::create('phr_ehi_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('phr_ehi_packages')->cascadeOnDelete();
            $table->string('mapping', 64);
            $table->string('mapping_version', 32);
            // Stable identity of what is proposed (mapping + root entity), so a
            // re-preview of the same package converges on the same row.
            $table->char('subject_key', 64);
            // sha256 over the involved revisions' content digests, sorted. The
            // revisions themselves are rows of phr_ehi_proposal_revisions.
            $table->char('revision_digest', 64);
            $table->string('kind', 32);
            $table->string('target_table', 64)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            // sha256 of the target row as previewed; apply refuses if it changed.
            $table->char('target_digest', 64)->nullable();
            $table->json('proposed_json')->nullable();
            $table->json('before_json')->nullable();
            $table->json('candidate_ids_json')->nullable();
            $table->string('status', 32)->default('pending');
            $table->char('preview_digest', 64);
            // The preview run that last produced this proposal (see
            // phr_ehi_packages.preview_generation_*); null until one does.
            $table->unsignedInteger('preview_generation')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();

            // A mapping version bump is a new proposal: decisions made under an
            // earlier version stay attributable to it.
            $table->unique(['package_id', 'mapping', 'mapping_version', 'subject_key'], 'phr_ehi_proposals_subject_uq');
            $table->index(['patient_id', 'status'], 'phr_ehi_proposals_patient_status_idx');
        });

        // Every revision a proposal relies on; position is the preview's order
        // (ascending revision id). A row per revision keeps a note with thousands
        // of text lines queryable in chunks and every backup record small.
        Schema::create('phr_ehi_proposal_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('proposal_id')->constrained('phr_ehi_proposals')->cascadeOnDelete();
            $table->foreignId('revision_id')->constrained('phr_ehi_revisions')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['proposal_id', 'revision_id'], 'phr_ehi_proposal_revisions_uq');
        });

        // Append-only review history; prior decisions are never rewritten.
        Schema::create('phr_ehi_proposal_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('proposal_id')->constrained('phr_ehi_proposals')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 32);
            $table->string('previous_status', 32);
            $table->string('mapping_version', 32);
            $table->char('revision_digest', 64);
            $table->foreignId('linked_immunization_id')->nullable()->constrained('phr_immunizations')->nullOnDelete();
            $table->foreignId('linked_document_id')->nullable()->constrained('phr_documents')->nullOnDelete();
            $table->timestamps();
        });

        // Explicit evidence links. One typed, nullable FK per clinical target keeps
        // backup relationships and deletion cascades declarative; exactly one is set.
        Schema::create('phr_ehi_record_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('revision_id')->constrained('phr_ehi_revisions')->cascadeOnDelete();
            $table->foreignId('proposal_id')->nullable()->constrained('phr_ehi_proposals')->nullOnDelete();
            $table->foreignId('immunization_id')->nullable()->constrained('phr_immunizations')->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('phr_documents')->cascadeOnDelete();
            $table->string('link_kind', 32);
            $table->string('mapping_version', 32);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['revision_id', 'immunization_id', 'link_kind'], 'phr_ehi_links_rev_imm_uq');
            $table->unique(['revision_id', 'document_id', 'link_kind'], 'phr_ehi_links_rev_doc_uq');
        });

        // Preview runs of a package. A preview takes the next started generation
        // and records it as completed only once every mapping, supersession, and
        // finding step succeeded; review and listings trust a pending proposal
        // only when it carries the completed generation of a package whose last
        // started preview completed. Null until the first preview.
        Schema::table('phr_ehi_packages', function (Blueprint $table): void {
            $table->unsignedInteger('preview_generation_started')->nullable();
            $table->unsignedInteger('preview_generation_completed')->nullable();
        });

        Schema::table('phr_immunizations', function (Blueprint $table): void {
            // Source date/time text exactly as supplied, with its precision and
            // whether it was local or UTC; administered_on stays the display date.
            $table->string('administered_at_source', 64)->nullable();
            $table->string('administered_at_precision', 16)->nullable();
            $table->string('administered_at_timezone', 16)->nullable();
            // Exact decimal text: a dose amount/volume is never a dose number.
            $table->string('dose_amount', 32)->nullable();
            $table->string('dose_unit', 32)->nullable();
            $table->string('administration_status', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phr_immunizations', function (Blueprint $table): void {
            $table->dropColumn([
                'administered_at_source',
                'administered_at_precision',
                'administered_at_timezone',
                'dose_amount',
                'dose_unit',
                'administration_status',
            ]);
        });
        Schema::table('phr_ehi_packages', function (Blueprint $table): void {
            $table->dropColumn(['preview_generation_started', 'preview_generation_completed']);
        });
        Schema::dropIfExists('phr_ehi_record_links');
        Schema::dropIfExists('phr_ehi_proposal_decisions');
        Schema::dropIfExists('phr_ehi_proposal_revisions');
        Schema::dropIfExists('phr_ehi_proposals');
        Schema::dropIfExists('phr_ehi_occurrences');
        Schema::dropIfExists('phr_ehi_revisions');
        Schema::dropIfExists('phr_ehi_entities');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Epic EHI Tables intake (issue #213, milestone 1).
 *
 * These tables preserve and account for a source package without projecting any
 * clinical data. They are deliberately generic: one row per package member, per
 * table account, and per finding -- never one database table per export table.
 * Every table carries a direct, cascading patient FK so native backup coverage and
 * patient deletion discover it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A source installation and source patient, declared by a person at binding.
        // Its identity is this row, never a filename, an export date, or a vendor name.
        Schema::create('phr_ehi_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label', 120);
            // The source system's patient identifier, kept verbatim as a string.
            $table->string('source_patient_key', 128);
            // Hints only: a later package whose dictionary matches may be suggested
            // for this source, but binding is always an explicit choice.
            $table->char('dictionary_fingerprint', 64)->nullable();
            $table->timestamps();

            $table->unique(['patient_id', 'label'], 'phr_ehi_sources_patient_label_uq');
        });

        Schema::create('phr_ehi_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('phr_ehi_sources')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('bound_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // sha256 of the canonical member manifest (sorted normalized path, size,
            // sha256). Repacked archives with identical members converge on one row.
            $table->char('manifest_digest', 64);
            $table->string('intake_kind', 16);
            // Metadata about the supplied archive only. Members are stored
            // individually; nothing reconstructed is ever described as the original.
            $table->char('original_archive_sha256', 64)->nullable();
            $table->unsignedBigInteger('original_archive_bytes')->nullable();
            $table->unsignedInteger('member_count')->default(0);
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->unsignedInteger('intake_count')->default(1);
            $table->dateTime('last_intake_at')->nullable();
            // The single source patient identifier observed in the package, if any.
            $table->string('detected_source_patient_key', 128)->nullable();
            $table->char('dictionary_fingerprint', 64)->nullable();
            $table->string('dictionary_version', 64)->nullable();
            $table->string('source_version', 64)->nullable();
            // Three independent states: archiving a package says nothing about
            // accounting, and neither says anything about clinical import.
            $table->string('preservation_status', 32)->default('pending');
            $table->string('accounting_status', 32)->default('pending');
            $table->string('clinical_status', 32)->default('not_started');
            $table->string('binding_status', 32)->default('unbound');
            $table->dateTime('bound_at')->nullable();
            $table->string('failure_category', 64)->nullable();
            // Operational: an unexpired lease blocks patient deletion mid-intake.
            $table->uuid('lease_token')->nullable();
            $table->dateTime('lease_expires_at')->nullable();
            $table->json('checkpoint_json')->nullable();
            $table->timestamps();

            $table->unique(['patient_id', 'manifest_digest'], 'phr_ehi_packages_patient_manifest_uq');
            $table->index(['patient_id', 'lease_expires_at'], 'phr_ehi_packages_patient_lease_idx');
        });

        // Content-addressed per patient: identical member bytes are stored once.
        Schema::create('phr_ehi_blobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->char('sha256', 64);
            $table->unsignedBigInteger('byte_size');
            $table->string('storage_disk', 64);
            $table->string('storage_path', 1024);
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['patient_id', 'sha256'], 'phr_ehi_blobs_patient_sha_uq');
        });

        // Immutable member manifest.
        Schema::create('phr_ehi_package_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('phr_ehi_packages')->cascadeOnDelete();
            $table->foreignId('blob_id')->constrained('phr_ehi_blobs')->cascadeOnDelete();
            $table->string('path', 1024);
            $table->char('path_sha256', 64);
            $table->string('original_path', 1024);
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->string('kind', 32);
            $table->string('table_name', 128)->nullable();
            $table->timestamps();

            $table->unique(['package_id', 'path_sha256'], 'phr_ehi_members_package_path_uq');
            $table->index(['package_id', 'kind'], 'phr_ehi_members_package_kind_idx');
        });

        Schema::create('phr_ehi_table_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('phr_ehi_packages')->cascadeOnDelete();
            // Null when the dictionary declares a table the package does not supply.
            $table->foreignId('member_id')->nullable()->constrained('phr_ehi_package_members')->cascadeOnDelete(); // sensitive-scan-ignore: package member FK, not an insurance member id
            $table->string('table_name', 128);
            $table->string('state', 32);
            $table->json('header_columns_json')->nullable();
            $table->json('unknown_columns_json')->nullable();
            $table->json('missing_columns_json')->nullable();
            // 64-bit: a 10 GiB package of short records can exceed 2^32 rows.
            $table->unsignedBigInteger('source_row_count')->default(0);
            $table->unsignedBigInteger('malformed_row_count')->default(0);
            $table->json('disposition_counts_json')->nullable();
            $table->string('parser_version', 32);
            $table->char('dictionary_fingerprint', 64)->nullable();
            $table->timestamps();

            $table->unique(['package_id', 'table_name'], 'phr_ehi_accounts_package_table_uq');
        });

        // Structural findings only: locators and fixed codes, never cell values.
        Schema::create('phr_ehi_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('phr_ehi_packages')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('phr_ehi_package_members')->cascadeOnDelete(); // sensitive-scan-ignore: package member FK, not an insurance member id
            $table->string('code', 64);
            $table->string('severity', 16);
            $table->unsignedBigInteger('line_number')->nullable();
            $table->unsignedBigInteger('byte_offset')->nullable();
            $table->string('column_name', 128)->nullable();
            $table->json('detail_json')->nullable();
            $table->timestamps();

            $table->index(['package_id', 'code'], 'phr_ehi_findings_package_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_ehi_findings');
        Schema::dropIfExists('phr_ehi_table_accounts');
        Schema::dropIfExists('phr_ehi_package_members');
        Schema::dropIfExists('phr_ehi_blobs');
        Schema::dropIfExists('phr_ehi_packages');
        Schema::dropIfExists('phr_ehi_sources');
    }
};

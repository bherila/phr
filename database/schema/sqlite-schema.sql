CREATE TABLE "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE "users"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar not null,
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "gemini_api_key" varchar,
  "mcp_api_key" varchar,
  "genai_daily_quota_limit" integer,
  "user_role" varchar not null default '',
  "last_login_date" datetime,
  "mcp_api_key_expires_at" datetime,
  "mcp_api_key_last_used_at" datetime,
  "oauth_provider" varchar,
  "oauth_subject" varchar,
  "oauth_security_version" integer not null default '0',
  "genai_execution_mode" varchar not null default 'api',
  "genai_execution_mode_chosen_at" datetime,
  "display_preferences" text
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE TABLE "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_expiration_index" on "cache"("expiration");
CREATE TABLE "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_locks_expiration_index" on "cache_locks"("expiration");
CREATE TABLE "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" varchar not null,
  "queue" varchar not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE INDEX "failed_jobs_connection_queue_failed_at_index" on "failed_jobs"(
  "connection",
  "queue",
  "failed_at"
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE "user_ai_configurations"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "name" varchar not null,
  "provider" varchar check("provider" in('gemini', 'anthropic', 'bedrock')) not null,
  "api_key" text not null,
  "region" varchar,
  "session_token" text,
  "model" varchar not null,
  "is_active" tinyint(1) not null default '0',
  "expires_at" datetime,
  "api_key_invalid_at" datetime,
  "api_key_invalid_reason" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE TABLE "genai_import_results"(
  "id" integer primary key autoincrement not null,
  "job_id" integer not null,
  "result_index" integer not null,
  "result_json" text not null,
  "status" varchar not null default 'pending_review',
  "produced_by" varchar,
  "imported_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("job_id") references "genai_import_jobs"("id") on delete cascade
);
CREATE INDEX "genai_import_results_job_id_result_index_index" on "genai_import_results"(
  "job_id",
  "result_index"
);
CREATE TABLE "genai_daily_quota"(
  "usage_date" date not null,
  "request_count" integer not null default '0',
  "updated_at" datetime,
  primary key("usage_date")
);
CREATE TABLE "auth_two_factor_attempts"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "token" varchar not null,
  "code" varchar not null,
  "is_used" tinyint(1) not null default '0',
  "is_suspicious" tinyint(1) not null default '0',
  "ip_address" varchar,
  "user_agent" text,
  "expires_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "auth_two_factor_attempts_user_id_is_used_index" on "auth_two_factor_attempts"(
  "user_id",
  "is_used"
);
CREATE INDEX "auth_two_factor_attempts_expires_at_index" on "auth_two_factor_attempts"(
  "expires_at"
);
CREATE UNIQUE INDEX "auth_two_factor_attempts_token_unique" on "auth_two_factor_attempts"(
  "token"
);
CREATE TABLE "auth_passkeys"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "credential_id" varchar not null,
  "credential_id_hash" varchar,
  "public_key" text not null,
  "counter" integer not null default '0',
  "aaguid" varchar,
  "name" varchar not null default 'Passkey',
  "transports" text,
  "last_used_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "rp_id" varchar,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "auth_passkeys_user_id_index" on "auth_passkeys"("user_id");
CREATE UNIQUE INDEX "auth_passkeys_credential_id_hash_unique" on "auth_passkeys"(
  "credential_id_hash"
);
CREATE TABLE "phr_patients"(
  "id" integer primary key autoincrement not null,
  "owner_user_id" integer not null,
  "display_name" varchar,
  "relationship" varchar,
  "birth_date" date,
  "sex_at_birth" varchar,
  "notes" text,
  "archived_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "real_names" text,
  foreign key("owner_user_id") references "users"("id") on delete cascade
);
CREATE INDEX "phr_patients_owner_name_idx" on "phr_patients"(
  "owner_user_id",
  "display_name"
);
CREATE INDEX "phr_patients_archived_idx" on "phr_patients"("archived_at");
CREATE TABLE "phr_patient_user_access"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "user_id" integer not null,
  "access_level" varchar not null default 'viewer',
  "granted_by_user_id" integer,
  "granted_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("granted_by_user_id") references "users"("id") on delete set null
);
CREATE UNIQUE INDEX "phr_patient_access_patient_user_unique" on "phr_patient_user_access"(
  "patient_id",
  "user_id"
);
CREATE INDEX "phr_patient_access_user_level_idx" on "phr_patient_user_access"(
  "user_id",
  "access_level"
);
CREATE TABLE "phr_lab_results"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "user_id" integer not null,
  "test_name" varchar,
  "collection_datetime" datetime,
  "result_datetime" datetime,
  "result_status" varchar,
  "ordering_provider" varchar,
  "resulting_lab" varchar,
  "analyte" varchar,
  "value" varchar,
  "value_numeric" numeric,
  "unit" varchar,
  "range_min" numeric,
  "range_max" numeric,
  "range_unit" varchar,
  "reference_range_text" varchar,
  "normal_value" varchar,
  "abnormal_flag" varchar,
  "message_from_provider" text,
  "result_comment" text,
  "lab_director" varchar,
  "source" varchar,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "import_source" varchar,
  "external_id" varchar,
  "source_document_id" integer,
  "review_status" varchar not null default 'confirmed',
  "deleted_at" datetime,
  "retracted_at" datetime,
  "range_min_inclusive" tinyint(1),
  "range_max_inclusive" tinyint(1),
  "value_raw" varchar,
  "value_comparator" varchar,
  "analyte_code" varchar,
  "analyte_code_system" varchar,
  "collected_at_source" varchar,
  "collected_at_precision" varchar,
  "collected_at_timezone" varchar,
  "resulted_at_source" varchar,
  "resulted_at_precision" varchar,
  "resulted_at_timezone" varchar,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "phr_labs_patient_result_dt_idx" on "phr_lab_results"(
  "patient_id",
  "result_datetime"
);
CREATE INDEX "phr_labs_user_result_dt_idx" on "phr_lab_results"(
  "user_id",
  "result_datetime"
);
CREATE INDEX "phr_labs_patient_analyte_idx" on "phr_lab_results"(
  "patient_id",
  "analyte"
);
CREATE TABLE "phr_patient_vitals"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "user_id" integer not null,
  "vital_name" varchar,
  "vital_date" date,
  "observed_at" datetime,
  "vital_value" varchar,
  "value_numeric" numeric,
  "value_numeric_secondary" numeric,
  "unit" varchar,
  "secondary_unit" varchar,
  "body_site" varchar,
  "source" varchar,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "import_source" varchar,
  "external_id" varchar,
  "source_document_id" integer,
  "review_status" varchar not null default 'confirmed',
  "deleted_at" datetime,
  "retracted_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "phr_vitals_patient_date_idx" on "phr_patient_vitals"(
  "patient_id",
  "vital_date"
);
CREATE INDEX "phr_vitals_user_date_idx" on "phr_patient_vitals"(
  "user_id",
  "vital_date"
);
CREATE INDEX "phr_vitals_patient_name_idx" on "phr_patient_vitals"(
  "patient_id",
  "vital_name"
);
CREATE TABLE "phr_dicom_uploads"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "uploaded_by_user_id" integer not null,
  "status" varchar not null default 'pending',
  "original_root_name" varchar,
  "total_files" integer not null default '0',
  "stored_files" integer not null default '0',
  "skipped_files" integer not null default '0',
  "total_bytes" integer not null default '0',
  "stored_bytes" integer not null default '0',
  "r2_prefix" varchar not null,
  "manifest_json" text,
  "skipped_files_json" text,
  "error_message" text,
  "created_at" datetime,
  "updated_at" datetime,
  "signed_url_expires_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("uploaded_by_user_id") references "users"("id") on delete cascade
);
CREATE INDEX "phr_dicom_uploads_patient_created_idx" on "phr_dicom_uploads"(
  "patient_id",
  "created_at"
);
CREATE INDEX "phr_dicom_uploads_user_idx" on "phr_dicom_uploads"(
  "uploaded_by_user_id"
);
CREATE TABLE "phr_dicom_studies"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "upload_id" integer,
  "study_instance_uid" varchar not null,
  "study_date" date,
  "study_time" varchar,
  "accession_number" varchar,
  "description" varchar,
  "modalities" varchar,
  "metadata_json" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("upload_id") references "phr_dicom_uploads"("id") on delete set null
);
CREATE UNIQUE INDEX "phr_dicom_studies_patient_uid_unique" on "phr_dicom_studies"(
  "patient_id",
  "study_instance_uid"
);
CREATE INDEX "phr_dicom_studies_patient_date_idx" on "phr_dicom_studies"(
  "patient_id",
  "study_date"
);
CREATE TABLE "phr_dicom_series"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "study_id" integer not null,
  "series_instance_uid" varchar not null,
  "modality" varchar,
  "series_number" integer,
  "description" varchar,
  "body_part" varchar,
  "metadata_json" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("study_id") references "phr_dicom_studies"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_dicom_series_study_uid_unique" on "phr_dicom_series"(
  "study_id",
  "series_instance_uid"
);
CREATE INDEX "phr_dicom_series_patient_modality_idx" on "phr_dicom_series"(
  "patient_id",
  "modality"
);
CREATE TABLE "phr_dicom_files"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "upload_id" integer not null,
  "file_kind" varchar not null,
  "r2_key" varchar not null,
  "original_relative_path" varchar not null,
  "original_path_hash" varchar not null,
  "original_filename" varchar not null,
  "mime_type" varchar,
  "file_size_bytes" integer not null default '0',
  "sha256" varchar not null,
  "metadata_json" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("upload_id") references "phr_dicom_uploads"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_dicom_files_upload_path_unique" on "phr_dicom_files"(
  "upload_id",
  "original_path_hash"
);
CREATE INDEX "phr_dicom_files_patient_sha_idx" on "phr_dicom_files"(
  "patient_id",
  "sha256"
);
CREATE TABLE "phr_dicom_instances"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "study_id" integer not null,
  "series_id" integer not null,
  "upload_id" integer not null,
  "file_id" integer not null,
  "sop_instance_uid" varchar not null,
  "sop_class_uid" varchar,
  "instance_number" integer,
  "transfer_syntax_uid" varchar,
  "rows" integer,
  "columns" integer,
  "number_of_frames" integer,
  "metadata_json" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("study_id") references "phr_dicom_studies"("id") on delete cascade,
  foreign key("series_id") references "phr_dicom_series"("id") on delete cascade,
  foreign key("upload_id") references "phr_dicom_uploads"("id") on delete cascade,
  foreign key("file_id") references "phr_dicom_files"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_dicom_instances_patient_sop_unique" on "phr_dicom_instances"(
  "patient_id",
  "sop_instance_uid"
);
CREATE INDEX "phr_dicom_instances_series_num_idx" on "phr_dicom_instances"(
  "series_id",
  "instance_number"
);
CREATE TABLE "phr_conditions"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "user_id" integer not null,
  "name" varchar not null,
  "icd10_code" varchar,
  "snomed_code" varchar,
  "onset_date" date,
  "abated_date" date,
  "clinical_status" varchar not null default 'active',
  "verification_status" varchar not null default 'confirmed',
  "severity" varchar,
  "notes" text,
  "raw_text" text,
  "created_at" datetime,
  "updated_at" datetime,
  "import_source" varchar,
  "external_id" varchar,
  "source_document_id" integer,
  "review_status" varchar not null default 'confirmed',
  "deleted_at" datetime,
  "retracted_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "phr_conditions_patient_id_index" on "phr_conditions"(
  "patient_id"
);
CREATE INDEX "phr_conditions_user_id_index" on "phr_conditions"("user_id");
CREATE TABLE "phr_medications"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "user_id" integer not null,
  "name" varchar not null,
  "rxnorm_code" varchar,
  "dose" varchar,
  "dose_unit" varchar,
  "route" varchar,
  "frequency" varchar,
  "started_on" date,
  "ended_on" date,
  "status" varchar not null default 'active',
  "prescriber_name" varchar,
  "reason_for_use" text,
  "raw_text" text,
  "created_at" datetime,
  "updated_at" datetime,
  "import_source" varchar,
  "external_id" varchar,
  "source_document_id" integer,
  "review_status" varchar not null default 'confirmed',
  "deleted_at" datetime,
  "retracted_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "phr_medications_patient_id_index" on "phr_medications"(
  "patient_id"
);
CREATE INDEX "phr_medications_user_id_index" on "phr_medications"("user_id");
CREATE TABLE "phr_office_visits"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "user_id" integer not null,
  "visit_date" date,
  "visit_started_at" datetime,
  "visit_ended_at" datetime,
  "visit_type" varchar,
  "provider_name" varchar,
  "provider_specialty" varchar,
  "facility_name" varchar,
  "chief_complaint" text,
  "assessment" text,
  "plan" text,
  "subjective" text,
  "objective" text,
  "icd10_codes" text,
  "cpt_codes" text,
  "raw_text" text,
  "created_at" datetime,
  "updated_at" datetime,
  "import_source" varchar,
  "external_id" varchar,
  "source_document_id" integer,
  "review_status" varchar not null default 'confirmed',
  "deleted_at" datetime,
  "retracted_at" datetime,
  "encounter_category" varchar,
  "encounter_status" varchar,
  "encounter_diagnoses" text,
  "visit_date_source" varchar,
  "visit_started_source" varchar,
  "visit_started_precision" varchar,
  "visit_started_timezone" varchar,
  "visit_ended_source" varchar,
  "visit_ended_precision" varchar,
  "visit_ended_timezone" varchar,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "phr_office_visits_patient_id_index" on "phr_office_visits"(
  "patient_id"
);
CREATE INDEX "phr_office_visits_user_id_index" on "phr_office_visits"(
  "user_id"
);
CREATE TABLE "phr_procedures"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "user_id" integer not null,
  "name" varchar not null,
  "cpt_code" varchar,
  "snomed_code" varchar,
  "performed_at" datetime,
  "performed_on" date,
  "performer_name" varchar,
  "performer_specialty" varchar,
  "facility_name" varchar,
  "status" varchar not null default 'completed',
  "reason" text,
  "outcome" text,
  "notes" text,
  "raw_text" text,
  "created_at" datetime,
  "updated_at" datetime,
  "import_source" varchar,
  "external_id" varchar,
  "source_document_id" integer,
  "review_status" varchar not null default 'confirmed',
  "deleted_at" datetime,
  "retracted_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "phr_procedures_patient_id_index" on "phr_procedures"(
  "patient_id"
);
CREATE INDEX "phr_procedures_user_id_index" on "phr_procedures"("user_id");
CREATE TABLE "phr_allergies"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "user_id" integer not null,
  "substance" varchar not null,
  "rxnorm_code" varchar,
  "snomed_code" varchar,
  "category" varchar,
  "criticality" varchar,
  "clinical_status" varchar not null default 'active',
  "verification_status" varchar not null default 'confirmed',
  "reaction" varchar,
  "severity" varchar,
  "notes" text,
  "raw_text" text,
  "created_at" datetime,
  "updated_at" datetime,
  "import_source" varchar,
  "external_id" varchar,
  "source_document_id" integer,
  "review_status" varchar not null default 'confirmed',
  "deleted_at" datetime,
  "retracted_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "phr_allergies_patient_id_index" on "phr_allergies"("patient_id");
CREATE INDEX "phr_allergies_user_id_index" on "phr_allergies"("user_id");
CREATE TABLE "phr_immunizations"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "user_id" integer not null,
  "vaccine_name" varchar not null,
  "cvx_code" varchar,
  "manufacturer" varchar,
  "lot_number" varchar,
  "administered_on" date,
  "dose_number" integer,
  "series_doses" integer,
  "site" varchar,
  "route" varchar,
  "administered_by" varchar,
  "facility_name" varchar,
  "notes" text,
  "raw_text" text,
  "created_at" datetime,
  "updated_at" datetime,
  "import_source" varchar,
  "external_id" varchar,
  "source_document_id" integer,
  "review_status" varchar not null default 'confirmed',
  "deleted_at" datetime,
  "retracted_at" datetime,
  "administered_at_source" varchar,
  "administered_at_precision" varchar,
  "administered_at_timezone" varchar,
  "dose_amount" varchar,
  "dose_unit" varchar,
  "administration_status" varchar,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "phr_immunizations_patient_id_index" on "phr_immunizations"(
  "patient_id"
);
CREATE INDEX "phr_immunizations_user_id_index" on "phr_immunizations"(
  "user_id"
);
CREATE UNIQUE INDEX "phr_labs_imp_uid" on "phr_lab_results"(
  "patient_id",
  "import_source",
  "external_id"
);
CREATE UNIQUE INDEX "phr_vitals_imp_uid" on "phr_patient_vitals"(
  "patient_id",
  "import_source",
  "external_id"
);
CREATE UNIQUE INDEX "phr_visits_imp_uid" on "phr_office_visits"(
  "patient_id",
  "import_source",
  "external_id"
);
CREATE UNIQUE INDEX "phr_meds_imp_uid" on "phr_medications"(
  "patient_id",
  "import_source",
  "external_id"
);
CREATE UNIQUE INDEX "phr_conds_imp_uid" on "phr_conditions"(
  "patient_id",
  "import_source",
  "external_id"
);
CREATE UNIQUE INDEX "phr_procs_imp_uid" on "phr_procedures"(
  "patient_id",
  "import_source",
  "external_id"
);
CREATE UNIQUE INDEX "phr_imms_imp_uid" on "phr_immunizations"(
  "patient_id",
  "import_source",
  "external_id"
);
CREATE UNIQUE INDEX "phr_allergies_imp_uid" on "phr_allergies"(
  "patient_id",
  "import_source",
  "external_id"
);
CREATE TABLE "phr_exports"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "user_id" integer not null,
  "requested_by_user_id" integer not null,
  "format" varchar not null default 'zip',
  "formats_json" text,
  "status" varchar not null default 'pending',
  "storage_disk" varchar not null default 'phr_exports',
  "storage_path" varchar,
  "filename" varchar,
  "file_size_bytes" integer,
  "error_message" text,
  "generated_at" datetime,
  "expires_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("requested_by_user_id") references "users"("id") on delete cascade
);
CREATE INDEX "phr_exports_patient_created_idx" on "phr_exports"(
  "patient_id",
  "created_at"
);
CREATE INDEX "phr_exports_status_exp_idx" on "phr_exports"(
  "status",
  "expires_at"
);
CREATE INDEX "phr_labs_src_doc_idx" on "phr_lab_results"("source_document_id");
CREATE INDEX "phr_vitals_src_doc_idx" on "phr_patient_vitals"(
  "source_document_id"
);
CREATE INDEX "phr_visits_src_doc_idx" on "phr_office_visits"(
  "source_document_id"
);
CREATE INDEX "phr_meds_src_doc_idx" on "phr_medications"("source_document_id");
CREATE INDEX "phr_conds_src_doc_idx" on "phr_conditions"("source_document_id");
CREATE INDEX "phr_procs_src_doc_idx" on "phr_procedures"("source_document_id");
CREATE INDEX "phr_imms_src_doc_idx" on "phr_immunizations"(
  "source_document_id"
);
CREATE INDEX "phr_allergies_src_doc_idx" on "phr_allergies"(
  "source_document_id"
);
CREATE UNIQUE INDEX "passkey_credential_id_hash_unique" on "auth_passkeys"(
  "credential_id_hash"
);
CREATE TABLE "auth_audit_log"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "acting_user_id" integer,
  "email" varchar,
  "event" varchar not null,
  "auth_method" varchar,
  "succeeded" tinyint(1) not null,
  "reason" varchar,
  "ip_address" blob,
  "user_agent" text,
  "session_id" varchar,
  "is_suspicious" tinyint(1) not null default '0',
  "metadata" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete set null,
  foreign key("acting_user_id") references "users"("id") on delete set null
);
CREATE INDEX "auth_audit_log_user_id_created_at_index" on "auth_audit_log"(
  "user_id",
  "created_at"
);
CREATE INDEX "auth_audit_log_event_index" on "auth_audit_log"("event");
CREATE INDEX "auth_audit_log_email_index" on "auth_audit_log"("email");
CREATE INDEX "auth_audit_log_created_at_index" on "auth_audit_log"(
  "created_at"
);
CREATE TABLE "phr_negative_assertions"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "user_id" integer not null,
  "import_source" varchar,
  "external_id" varchar,
  "source_document_id" integer,
  "assertion_type" varchar not null,
  "statement" text not null,
  "scope" varchar,
  "observed_on" date,
  "notes" text,
  "source_refs" text,
  "raw_text" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("source_document_id") references "phr_documents"("id") on delete set null
);
CREATE INDEX "phr_na_patient_type_idx" on "phr_negative_assertions"(
  "patient_id",
  "assertion_type"
);
CREATE INDEX "phr_na_src_doc_idx" on "phr_negative_assertions"(
  "source_document_id"
);
CREATE UNIQUE INDEX "phr_na_imp_uid" on "phr_negative_assertions"(
  "patient_id",
  "import_source",
  "external_id"
);
CREATE TABLE "phr_portal_messages"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "user_id" integer not null,
  "import_source" varchar,
  "external_id" varchar,
  "source_document_id" integer,
  "message_at" datetime,
  "direction" varchar,
  "subject" varchar,
  "sender_name" varchar,
  "recipient_name" varchar,
  "summary" text,
  "clinical_relevance" text,
  "source_refs" text,
  "raw_text" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("source_document_id") references "phr_documents"("id") on delete set null
);
CREATE INDEX "phr_pm_patient_at_idx" on "phr_portal_messages"(
  "patient_id",
  "message_at"
);
CREATE INDEX "phr_pm_src_doc_idx" on "phr_portal_messages"(
  "source_document_id"
);
CREATE UNIQUE INDEX "phr_pm_imp_uid" on "phr_portal_messages"(
  "patient_id",
  "import_source",
  "external_id"
);
CREATE TABLE "phr_respiratory_events"(
  "id" integer primary key autoincrement not null,
  "phr_patient_id" integer not null,
  "client_event_uuid" varchar not null,
  "event_type" varchar not null,
  "occurred_at" datetime not null,
  "tz_offset_min" integer not null default '0',
  "duration_ms" integer,
  "confidence" float,
  "burst_count" integer not null default '1',
  "source" varchar,
  "device_id" varchar,
  "model_version" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "peak_dbfs" float,
  "mean_dbfs" float,
  "noise_floor_dbfs" float,
  "false_positive_at" datetime,
  "corrected_to_event_type" varchar,
  "corrected_at" datetime,
  foreign key("phr_patient_id") references "phr_patients"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_resp_evt_patient_uuid_uq" on "phr_respiratory_events"(
  "phr_patient_id",
  "client_event_uuid"
);
CREATE INDEX "phr_resp_evt_patient_time_ix" on "phr_respiratory_events"(
  "phr_patient_id",
  "occurred_at"
);
CREATE INDEX "phr_resp_evt_patient_type_ix" on "phr_respiratory_events"(
  "phr_patient_id",
  "event_type"
);
CREATE TABLE "phr_health_logs"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "user_id" integer not null,
  "created_by_user_id" integer,
  "name" varchar not null,
  "kind" varchar not null,
  "description" varchar,
  "archived_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("created_by_user_id") references "users"("id") on delete set null
);
CREATE UNIQUE INDEX "phr_hl_patient_name_uid" on "phr_health_logs"(
  "patient_id",
  "name"
);
CREATE INDEX "phr_hl_patient_arch_idx" on "phr_health_logs"(
  "patient_id",
  "archived_at"
);
CREATE INDEX "phr_hl_user_idx" on "phr_health_logs"("user_id");
CREATE INDEX "phr_hl_creator_idx" on "phr_health_logs"("created_by_user_id");
CREATE TABLE "phr_health_log_entries"(
  "id" integer primary key autoincrement not null,
  "health_log_id" integer not null,
  "patient_id" integer not null,
  "user_id" integer not null,
  "recorded_by_user_id" integer,
  "occurred_at" datetime not null,
  "title" varchar,
  "notes" text,
  "intensity" integer,
  "tags" text,
  "details" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("health_log_id") references "phr_health_logs"("id") on delete cascade,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("recorded_by_user_id") references "users"("id") on delete set null
);
CREATE INDEX "phr_hle_log_at_idx" on "phr_health_log_entries"(
  "health_log_id",
  "occurred_at"
);
CREATE INDEX "phr_hle_patient_at_idx" on "phr_health_log_entries"(
  "patient_id",
  "occurred_at"
);
CREATE INDEX "phr_hle_user_idx" on "phr_health_log_entries"("user_id");
CREATE INDEX "phr_hle_recorder_idx" on "phr_health_log_entries"(
  "recorded_by_user_id"
);
CREATE INDEX "phr_resp_evt_patient_fp_ix" on "phr_respiratory_events"(
  "phr_patient_id",
  "false_positive_at"
);
CREATE TABLE "phr_sinus_enrollments"(
  "id" integer primary key autoincrement not null,
  "phr_patient_id" integer not null,
  "client_enrollment_uuid" blob not null,
  "class" varchar not null,
  "is_negative" tinyint(1) not null default '0',
  "negative_scoped" tinyint(1) not null default '0',
  "embedding" blob not null,
  "embedding_dim" integer not null,
  "model_version" varchar,
  "similarity" float,
  "separation" float,
  "peak_dbfs" float,
  "source_event_uuid" varchar,
  "device_id" varchar,
  "captured_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("phr_patient_id") references "phr_patients"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_sinus_enr_patient_uuid_uq" on "phr_sinus_enrollments"(
  "phr_patient_id",
  "client_enrollment_uuid"
);
CREATE INDEX "phr_sinus_enr_patient_class_ix" on "phr_sinus_enrollments"(
  "phr_patient_id",
  "class"
);
CREATE TABLE "phr_sinus_settings"(
  "id" integer primary key autoincrement not null,
  "phr_patient_id" integer not null,
  "settings" text not null,
  "settings_updated_at" datetime not null,
  "received_at" datetime not null,
  "updated_by_device" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("phr_patient_id") references "phr_patients"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_sinus_set_patient_uq" on "phr_sinus_settings"(
  "phr_patient_id"
);
CREATE UNIQUE INDEX "users_oauth_identity_unique" on "users"(
  "oauth_provider",
  "oauth_subject"
);
CREATE TABLE "uptime_runs"(
  "id" integer primary key autoincrement not null,
  "job_name" varchar not null,
  "status" varchar not null,
  "started_at" datetime not null,
  "finished_at" datetime,
  "duration_ms" integer,
  "exit_code" integer
);
CREATE INDEX "uptime_runs_job_name_started_at_index" on "uptime_runs"(
  "job_name",
  "started_at"
);
CREATE INDEX "uptime_runs_job_name_index" on "uptime_runs"("job_name");
CREATE INDEX "uptime_runs_status_index" on "uptime_runs"("status");
CREATE TABLE "phr_native_backups"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "requested_by_user_id" integer not null,
  "status" varchar not null,
  "schema_version" integer not null,
  "storage_disk" varchar not null default 'phr_exports',
  "storage_path" varchar,
  "file_size_bytes" integer,
  "archive_sha256" varchar,
  "counts_json" text,
  "failure_category" varchar,
  "generated_at" datetime,
  "expires_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("requested_by_user_id") references "users"("id") on delete cascade
);
CREATE INDEX "phr_native_backups_patient_created_idx" on "phr_native_backups"(
  "patient_id",
  "created_at"
);
CREATE INDEX "phr_native_backups_expires_idx" on "phr_native_backups"(
  "expires_at"
);
CREATE TABLE "phr_eobs"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "user_id" integer not null,
  "source_document_id" integer,
  "import_source" varchar not null,
  "external_id" varchar not null,
  "claim_number" varchar,
  "claim_type" varchar not null default 'unknown',
  "administrator" varchar,
  "carrier" varchar,
  "plan_name" varchar,
  "group_number" varchar,
  "member_id" varchar,
  "participant_name" varchar,
  "patient_name" varchar,
  "provider_name" varchar,
  "payment_to" varchar,
  "provider_tin" varchar,
  "check_number" varchar,
  "check_amount" numeric,
  "print_date" date,
  "processed_date" date,
  "total_charges" numeric,
  "total_provider_discount" numeric,
  "total_ineligible_amount" numeric,
  "total_deductible_applied" numeric,
  "total_copay_applied" numeric,
  "total_benefit_percent" numeric,
  "total_carrier_payment" numeric,
  "total_plan_payment" numeric,
  "total_patient_responsibility" numeric,
  "parsed_data" text,
  "raw_text" text,
  "parser_version" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "claim_fingerprint" varchar,
  "provider_phone" varchar,
  "submission_date" date,
  "total_accepted_fee" numeric,
  "review_status" varchar not null default 'confirmed',
  "lines_digest" varchar,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("source_document_id") references "phr_documents"("id") on delete set null
);
CREATE INDEX "phr_eobs_patient_processed_idx" on "phr_eobs"(
  "patient_id",
  "processed_date"
);
CREATE INDEX "phr_eobs_patient_claim_idx" on "phr_eobs"(
  "patient_id",
  "claim_number"
);
CREATE UNIQUE INDEX "phr_eobs_import_uid" on "phr_eobs"(
  "patient_id",
  "import_source",
  "external_id"
);
CREATE TABLE "phr_eob_lines"(
  "id" integer primary key autoincrement not null,
  "eob_id" integer not null,
  "patient_id" integer not null,
  "line_number" integer not null,
  "procedure_code" varchar not null,
  "revenue_code" varchar,
  "code_type" varchar not null default 'unknown',
  "description" varchar,
  "service_start" date,
  "service_end" date,
  "total_charges" numeric,
  "provider_discount" numeric,
  "ineligible_amount" numeric,
  "notes_applied" text,
  "deductible_applied" numeric,
  "copay_applied" numeric,
  "benefit_percent" numeric,
  "carrier_payment" numeric,
  "plan_payment" numeric,
  "patient_responsibility" numeric,
  "parsed_data" text,
  "raw_text" text,
  "created_at" datetime,
  "updated_at" datetime,
  "accepted_fee" numeric,
  foreign key("eob_id") references "phr_eobs"("id") on delete cascade,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade
);
CREATE INDEX "phr_eob_lines_patient_service_idx" on "phr_eob_lines"(
  "patient_id",
  "service_start"
);
CREATE INDEX "phr_eob_lines_patient_code_idx" on "phr_eob_lines"(
  "patient_id",
  "procedure_code"
);
CREATE UNIQUE INDEX "phr_eob_lines_eob_line_uid" on "phr_eob_lines"(
  "eob_id",
  "line_number"
);
CREATE UNIQUE INDEX "phr_eobs_claim_fingerprint_uid" on "phr_eobs"(
  "patient_id",
  "import_source",
  "claim_fingerprint"
);
CREATE TABLE "phr_office_visit_eobs"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "office_visit_id" integer not null,
  "eob_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("office_visit_id") references "phr_office_visits"("id") on delete cascade,
  foreign key("eob_id") references "phr_eobs"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_visit_eobs_visit_eob_uid" on "phr_office_visit_eobs"(
  "office_visit_id",
  "eob_id"
);
CREATE INDEX "phr_visit_eobs_patient_visit_idx" on "phr_office_visit_eobs"(
  "patient_id",
  "office_visit_id"
);
CREATE INDEX "phr_visit_eobs_eob_idx" on "phr_office_visit_eobs"("eob_id");
CREATE TABLE "phr_procedure_eobs"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "procedure_id" integer not null,
  "eob_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("procedure_id") references "phr_procedures"("id") on delete cascade,
  foreign key("eob_id") references "phr_eobs"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_procedure_eobs_procedure_eob_uid" on "phr_procedure_eobs"(
  "procedure_id",
  "eob_id"
);
CREATE INDEX "phr_procedure_eobs_patient_procedure_idx" on "phr_procedure_eobs"(
  "patient_id",
  "procedure_id"
);
CREATE INDEX "phr_procedure_eobs_eob_idx" on "phr_procedure_eobs"("eob_id");
CREATE TABLE "phr_device_keys"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "device_id" varchar not null,
  "name" varchar not null,
  "token_hash" varchar not null,
  "last_used_at" datetime,
  "expires_at" datetime not null,
  "revoked_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_dk_token_hash_uq" on "phr_device_keys"("token_hash");
CREATE UNIQUE INDEX "phr_dk_user_device_uq" on "phr_device_keys"(
  "user_id",
  "device_id"
);
CREATE TABLE "phr_device_pairing_codes"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "device_id" varchar not null,
  "name" varchar not null,
  "code_hash" varchar not null,
  "code_challenge" varchar not null,
  "expires_at" datetime not null,
  "consumed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade on update no action
);
CREATE UNIQUE INDEX "phr_dpc_code_hash_uq" on "phr_device_pairing_codes"(
  "code_hash"
);
CREATE INDEX "phr_eobs_patient_submission_idx" on "phr_eobs"(
  "patient_id",
  "submission_date"
);
CREATE TABLE "phr_blob_migrations"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "artifact_class" varchar not null,
  "storage_disk" varchar not null,
  "reference_table" varchar not null,
  "reference_id" integer not null,
  "reference_column" varchar not null,
  "source_key" varchar not null,
  "destination_key" varchar not null,
  "source_size_bytes" integer not null,
  "source_sha256" varchar not null,
  "migrated_at" datetime not null,
  "retain_until" datetime not null,
  "legacy_deleted_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_blob_migrations_reference_uq" on "phr_blob_migrations"(
  "reference_table",
  "reference_id",
  "reference_column"
);
CREATE INDEX "phr_blob_migrations_disk_live_idx" on "phr_blob_migrations"(
  "storage_disk",
  "legacy_deleted_at"
);
CREATE INDEX "phr_blob_migrations_retention_idx" on "phr_blob_migrations"(
  "retain_until",
  "legacy_deleted_at"
);
CREATE TABLE "phr_native_backup_audits"(
  "id" integer primary key autoincrement not null,
  "actor_user_id" integer,
  "patient_root_id" integer not null,
  "operation" varchar not null,
  "schema_version" integer not null,
  "archive_sha256" varchar,
  "counts_json" text,
  "outcome" varchar not null,
  "failure_category" varchar,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "phr_native_audits_patient_created_idx" on "phr_native_backup_audits"(
  "patient_root_id",
  "created_at"
);
CREATE TABLE "phr_patient_deletions"(
  "id" integer primary key autoincrement not null,
  "actor_user_id" integer,
  "patient_root_id" integer not null,
  "preview_digest" varchar not null,
  "record_counts_json" text not null,
  "active_share_count" integer not null,
  "artifact_count" integer not null,
  "artifact_bytes" integer not null,
  "status" varchar not null,
  "failure_category" varchar,
  "deleted_at" datetime not null,
  "completed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("actor_user_id") references "users"("id") on delete set null
);
CREATE INDEX "phr_patient_deletions_actor_created_idx" on "phr_patient_deletions"(
  "actor_user_id",
  "created_at"
);
CREATE INDEX "phr_patient_deletions_patient_created_idx" on "phr_patient_deletions"(
  "patient_root_id",
  "created_at"
);
CREATE INDEX "phr_patient_deletions_status_created_idx" on "phr_patient_deletions"(
  "status",
  "created_at"
);
CREATE TABLE "phr_patient_deletion_artifacts"(
  "id" integer primary key autoincrement not null,
  "deletion_id" integer not null,
  "storage_disk" varchar not null,
  "storage_key" varchar not null,
  "storage_key_hash" varchar not null,
  "expected_bytes" integer,
  "attempt_count" integer not null default '0',
  "status" varchar not null default 'pending',
  "last_attempt_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("deletion_id") references "phr_patient_deletions"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_patient_deletion_artifact_uq" on "phr_patient_deletion_artifacts"(
  "deletion_id",
  "storage_disk",
  "storage_key_hash"
);
CREATE TABLE "phr_native_restore_attempts"(
  "id" integer primary key autoincrement not null,
  "actor_user_id" integer,
  "source_storage_disk" varchar not null default 'phr_exports',
  "source_storage_path" varchar,
  "source_file_size_bytes" integer not null,
  "uploaded_bytes" integer not null default '0',
  "archive_sha256" varchar,
  "schema_version" integer,
  "patient_native_id" varchar,
  "target_patient_root_id" integer,
  "plan_digest" varchar,
  "plan_counts_json" text,
  "access_grant_count" integer not null default '0',
  "restore_access_grants" tinyint(1) not null default '0',
  "status" varchar not null,
  "failure_category" varchar,
  "expires_at" datetime not null,
  "completed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("actor_user_id") references "users"("id") on delete set null
);
CREATE INDEX "phr_native_restores_actor_status_idx" on "phr_native_restore_attempts"(
  "actor_user_id",
  "status"
);
CREATE INDEX "phr_native_restores_status_expires_idx" on "phr_native_restore_attempts"(
  "status",
  "expires_at"
);
CREATE TABLE "oauth_auth_codes"(
  "id" varchar not null,
  "user_id" integer not null,
  "client_id" varchar not null,
  "scopes" text,
  "revoked" tinyint(1) not null,
  "oauth_security_version" integer,
  "expires_at" datetime,
  "resource_uri" varchar,
  "provider_subject" varchar,
  "provider_generation" integer,
  "oauth_consent_version" integer,
  "patient_public_id" varchar,
  "patient_consent_version" integer,
  primary key("id")
);
CREATE INDEX "oauth_auth_codes_user_id_index" on "oauth_auth_codes"("user_id");
CREATE TABLE "oauth_access_tokens"(
  "id" varchar not null,
  "user_id" integer,
  "client_id" varchar not null,
  "name" varchar,
  "scopes" text,
  "revoked" tinyint(1) not null,
  "oauth_security_version" integer,
  "oauth_family_id" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "expires_at" datetime,
  "resource_uri" varchar,
  "provider_subject" varchar,
  "provider_generation" integer,
  "oauth_consent_version" integer,
  primary key("id")
);
CREATE INDEX "oauth_access_tokens_user_id_index" on "oauth_access_tokens"(
  "user_id"
);
CREATE INDEX "oauth_access_tokens_oauth_family_id_index" on "oauth_access_tokens"(
  "oauth_family_id"
);
CREATE TABLE "oauth_refresh_tokens"(
  "id" varchar not null,
  "access_token_id" varchar not null,
  "revoked" tinyint(1) not null,
  "expires_at" datetime,
  "resource_uri" text,
  "provider_subject" varchar,
  "provider_generation" integer,
  "provider_user_id" varchar,
  "client_id" varchar,
  primary key("id")
);
CREATE INDEX "oauth_refresh_tokens_access_token_id_index" on "oauth_refresh_tokens"(
  "access_token_id"
);
CREATE TABLE "oauth_clients"(
  "id" varchar not null,
  "owner_type" varchar,
  "owner_id" integer,
  "name" varchar not null,
  "secret" varchar,
  "provider" varchar,
  "redirect_uris" text not null,
  "grant_types" text not null,
  "revoked" tinyint(1) not null,
  "created_at" datetime,
  "updated_at" datetime,
  "dynamically_registered_at" datetime,
  "first_authorized_at" datetime,
  "scopes" text,
  "last_used_at" datetime,
  primary key("id")
);
CREATE INDEX "oauth_clients_owner_type_owner_id_index" on "oauth_clients"(
  "owner_type",
  "owner_id"
);
CREATE TABLE "agent_api_audits"(
  "id" integer primary key autoincrement not null,
  "request_id" varchar not null,
  "actor_user_id" integer,
  "oauth_client_id" varchar,
  "oauth_token_hash" varchar,
  "event" varchar not null,
  "route_name" varchar not null,
  "http_method" varchar not null,
  "response_status" integer not null,
  "duration_ms" integer not null,
  "sampling_key" varchar,
  "created_at" datetime not null
);
CREATE UNIQUE INDEX "agent_api_audits_request_id_unique" on "agent_api_audits"(
  "request_id"
);
CREATE INDEX "agent_api_audits_actor_user_id_index" on "agent_api_audits"(
  "actor_user_id"
);
CREATE INDEX "agent_api_audits_oauth_client_id_index" on "agent_api_audits"(
  "oauth_client_id"
);
CREATE INDEX "agent_api_audits_oauth_token_hash_index" on "agent_api_audits"(
  "oauth_token_hash"
);
CREATE UNIQUE INDEX "agent_api_audits_sampling_key_unique" on "agent_api_audits"(
  "sampling_key"
);
CREATE INDEX "agent_api_audits_created_at_index" on "agent_api_audits"(
  "created_at"
);
CREATE TRIGGER users_increment_oauth_security_version AFTER UPDATE OF user_role ON users FOR EACH ROW WHEN OLD.user_role IS NOT NEW.user_role BEGIN UPDATE users SET oauth_security_version = OLD.oauth_security_version + 1 WHERE id = NEW.id; END;
CREATE TABLE "oauth_token_families"(
  "id" varchar not null,
  "user_id" integer,
  "client_id" varchar not null,
  "oauth_security_version" integer,
  "revoked" tinyint(1) not null default '0',
  "expires_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  "oauth_consent_version" integer,
  "patient_public_id" varchar,
  "patient_consent_version" integer,
  primary key("id")
);
CREATE INDEX "oauth_token_families_user_id_index" on "oauth_token_families"(
  "user_id"
);
CREATE INDEX "oauth_token_families_client_id_index" on "oauth_token_families"(
  "client_id"
);
CREATE INDEX "oauth_token_families_revoked_index" on "oauth_token_families"(
  "revoked"
);
CREATE INDEX "oauth_token_families_expires_at_index" on "oauth_token_families"(
  "expires_at"
);
CREATE TABLE "phr_native_record_identities"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "record_table" varchar not null,
  "record_id" integer not null,
  "native_id" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "restored_at" datetime,
  "restore_attempt_id" integer,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade on update no action,
  foreign key("restore_attempt_id") references "phr_native_restore_attempts"("id") on delete set null
);
CREATE UNIQUE INDEX "phr_native_identity_native_uq" on "phr_native_record_identities"(
  "patient_id",
  "native_id"
);
CREATE UNIQUE INDEX "phr_native_identity_source_uq" on "phr_native_record_identities"(
  "patient_id",
  "record_table",
  "record_id"
);
CREATE INDEX "phr_native_identity_restored_idx" on "phr_native_record_identities"(
  "record_table",
  "restored_at"
);
CREATE INDEX "oauth_clients_dynamically_registered_at_index" on "oauth_clients"(
  "dynamically_registered_at"
);
CREATE INDEX "oauth_access_tokens_resource_uri_index" on "oauth_access_tokens"(
  "resource_uri"
);
CREATE TABLE "phr_office_visit_dicom_studies"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "office_visit_id" integer not null,
  "dicom_study_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("office_visit_id") references "phr_office_visits"("id") on delete cascade,
  foreign key("dicom_study_id") references "phr_dicom_studies"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_visit_dicom_visit_study_uid" on "phr_office_visit_dicom_studies"(
  "office_visit_id",
  "dicom_study_id"
);
CREATE INDEX "phr_visit_dicom_patient_visit_idx" on "phr_office_visit_dicom_studies"(
  "patient_id",
  "office_visit_id"
);
CREATE INDEX "phr_visit_dicom_study_idx" on "phr_office_visit_dicom_studies"(
  "dicom_study_id"
);
CREATE INDEX "phr_office_visits_review_status_index" on "phr_office_visits"(
  "review_status"
);
CREATE INDEX "phr_procedures_review_status_index" on "phr_procedures"(
  "review_status"
);
CREATE TABLE "agent_api_mutation_identities"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "oauth_client_id" varchar not null,
  "operation" varchar not null,
  "external_id_hash" varchar not null,
  "request_hash" varchar not null,
  "target_table" varchar not null,
  "target_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade
);
CREATE UNIQUE INDEX "agent_mutation_identity_uq" on "agent_api_mutation_identities"(
  "patient_id",
  "oauth_client_id",
  "operation",
  "external_id_hash"
);
CREATE INDEX "agent_mutation_target_idx" on "agent_api_mutation_identities"(
  "target_table",
  "target_id"
);
CREATE INDEX "phr_immunizations_review_status_index" on "phr_immunizations"(
  "review_status"
);
CREATE INDEX "phr_medications_review_status_index" on "phr_medications"(
  "review_status"
);
CREATE INDEX "phr_conditions_review_status_index" on "phr_conditions"(
  "review_status"
);
CREATE INDEX "phr_allergies_review_status_index" on "phr_allergies"(
  "review_status"
);
CREATE INDEX "phr_lab_results_review_status_index" on "phr_lab_results"(
  "review_status"
);
CREATE INDEX "phr_patient_vitals_review_status_index" on "phr_patient_vitals"(
  "review_status"
);
CREATE INDEX "phr_lab_results_lifecycle_idx" on "phr_lab_results"(
  "patient_id",
  "deleted_at",
  "retracted_at"
);
CREATE INDEX "phr_patient_vitals_lifecycle_idx" on "phr_patient_vitals"(
  "patient_id",
  "deleted_at",
  "retracted_at"
);
CREATE INDEX "phr_office_visits_lifecycle_idx" on "phr_office_visits"(
  "patient_id",
  "deleted_at",
  "retracted_at"
);
CREATE INDEX "phr_medications_lifecycle_idx" on "phr_medications"(
  "patient_id",
  "deleted_at",
  "retracted_at"
);
CREATE INDEX "phr_conditions_lifecycle_idx" on "phr_conditions"(
  "patient_id",
  "deleted_at",
  "retracted_at"
);
CREATE INDEX "phr_procedures_lifecycle_idx" on "phr_procedures"(
  "patient_id",
  "deleted_at",
  "retracted_at"
);
CREATE INDEX "phr_immunizations_lifecycle_idx" on "phr_immunizations"(
  "patient_id",
  "deleted_at",
  "retracted_at"
);
CREATE INDEX "phr_allergies_lifecycle_idx" on "phr_allergies"(
  "patient_id",
  "deleted_at",
  "retracted_at"
);
CREATE TABLE "bherila_auth_delegated_nonces"(
  "key" varchar not null,
  "expires_at" integer not null,
  primary key("key")
);
CREATE INDEX "bherila_auth_delegated_nonces_expires_at_index" on "bherila_auth_delegated_nonces"(
  "expires_at"
);
CREATE TABLE "genai_mcp_mailboxes"(
  "id" varchar not null,
  "owner_type" varchar not null,
  "owner_id" varchar not null,
  "name" varchar not null,
  "enabled" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE UNIQUE INDEX "genai_mcp_mailboxes_owner_type_owner_id_name_unique" on "genai_mcp_mailboxes"(
  "owner_type",
  "owner_id",
  "name"
);
CREATE TABLE "genai_mcp_tokens"(
  "id" varchar not null,
  "mailbox_id" varchar not null,
  "name" varchar not null,
  "token_hash" varchar not null,
  "scopes" text not null,
  "expires_at" datetime,
  "last_used_at" datetime,
  "revoked_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("mailbox_id") references "genai_mcp_mailboxes"("id") on delete cascade,
  primary key("id")
);
CREATE UNIQUE INDEX "genai_mcp_tokens_token_hash_unique" on "genai_mcp_tokens"(
  "token_hash"
);
CREATE TABLE "genai_mcp_attachments"(
  "id" varchar not null,
  "request_id" varchar not null,
  "disk" varchar,
  "path" text,
  "host_reference" text,
  "name" varchar not null,
  "mime_type" varchar not null,
  "size" integer not null,
  "sha256" varchar not null,
  "package_owned" tinyint(1) not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("request_id") references "genai_mcp_requests"("id") on delete cascade,
  primary key("id")
);
CREATE TABLE "genai_import_jobs"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "ai_configuration_id" integer,
  "ai_provider" varchar,
  "ai_model" varchar,
  "job_type" varchar not null,
  "file_hash" varchar not null,
  "original_filename" varchar not null,
  "s3_path" varchar not null,
  "mime_type" varchar,
  "file_size_bytes" integer not null,
  "context_json" text,
  "status" varchar not null default('pending'),
  "error_message" text,
  "raw_response" text,
  "retry_count" integer not null default('0'),
  "scheduled_for" date,
  "parsed_at" datetime,
  "input_tokens" integer,
  "output_tokens" integer,
  "processing_tier" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "execution_mode" varchar not null default 'api',
  "mcp_generation" integer not null default '0',
  "mcp_request_id" varchar,
  foreign key("ai_configuration_id") references "user_ai_configurations"("id") on delete set null on update no action,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("mcp_request_id") references "genai_mcp_requests"("id") on delete set null
);
CREATE INDEX "genai_import_jobs_ai_configuration_id_created_at_index" on "genai_import_jobs"(
  "ai_configuration_id",
  "created_at"
);
CREATE INDEX "genai_import_jobs_ai_configuration_id_index" on "genai_import_jobs"(
  "ai_configuration_id"
);
CREATE INDEX "genai_import_jobs_file_hash_index" on "genai_import_jobs"(
  "file_hash"
);
CREATE INDEX "genai_import_jobs_scheduled_for_status_index" on "genai_import_jobs"(
  "scheduled_for",
  "status"
);
CREATE INDEX "genai_import_jobs_user_id_status_index" on "genai_import_jobs"(
  "user_id",
  "status"
);
CREATE INDEX "genai_import_execution_idx" on "genai_import_jobs"(
  "user_id",
  "execution_mode",
  "status"
);
CREATE UNIQUE INDEX "genai_import_jobs_mcp_request_id_unique" on "genai_import_jobs"(
  "mcp_request_id"
);
CREATE TABLE "bherila_auth_delegated_receipts"(
  "application" varchar not null,
  "operation_key" varchar not null,
  "operation_id" varchar not null,
  "actor" varchar not null,
  "request_hash" varchar not null,
  "status" integer,
  "response" text,
  "claimed_at" integer not null,
  "created_at" integer not null,
  primary key("application", "operation_key")
);
CREATE INDEX "bherila_auth_delegated_receipts_created_at_index" on "bherila_auth_delegated_receipts"(
  "created_at"
);
CREATE INDEX "oauth_refresh_tokens_provider_user_id_index" on "oauth_refresh_tokens"(
  "provider_user_id"
);
CREATE INDEX "oauth_refresh_tokens_client_id_index" on "oauth_refresh_tokens"(
  "client_id"
);
CREATE TABLE "oauth_client_consents"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "client_id" varchar not null,
  "resource" varchar not null,
  "scopes" text not null,
  "version" integer not null default '1',
  "granted_at" datetime not null,
  "revoked_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "oauth_client_consents_user_id_client_id_resource_unique" on "oauth_client_consents"(
  "user_id",
  "client_id",
  "resource"
);
CREATE INDEX "oauth_client_consents_client_id_index" on "oauth_client_consents"(
  "client_id"
);
CREATE UNIQUE INDEX "phr_native_identity_public_uq" on "phr_native_record_identities"(
  "record_table",
  "native_id"
);
CREATE TABLE "oauth_patient_consents"(
  "id" integer primary key autoincrement not null,
  "consent_id" integer not null,
  "patient_public_id" varchar not null,
  "client_consent_version" integer not null,
  "version" integer not null default '1',
  "granted_at" datetime not null,
  "revoked_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("consent_id") references "oauth_client_consents"("id") on delete cascade
);
CREATE UNIQUE INDEX "oauth_patient_consent_unique" on "oauth_patient_consents"(
  "consent_id",
  "patient_public_id"
);
CREATE TABLE "phr_ehi_sources"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "created_by_user_id" integer,
  "label" varchar not null,
  "source_patient_key" varchar not null,
  "dictionary_fingerprint" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("created_by_user_id") references "users"("id") on delete set null
);
CREATE UNIQUE INDEX "phr_ehi_sources_patient_label_uq" on "phr_ehi_sources"(
  "patient_id",
  "label"
);
CREATE TABLE "phr_ehi_packages"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "source_id" integer,
  "created_by_user_id" integer,
  "bound_by_user_id" integer,
  "manifest_digest" varchar not null,
  "intake_kind" varchar not null,
  "original_archive_sha256" varchar,
  "original_archive_bytes" integer,
  "member_count" integer not null default '0',
  "total_bytes" integer not null default '0',
  "intake_count" integer not null default '1',
  "last_intake_at" datetime,
  "detected_source_patient_key" varchar,
  "dictionary_fingerprint" varchar,
  "dictionary_version" varchar,
  "source_version" varchar,
  "preservation_status" varchar not null default 'pending',
  "accounting_status" varchar not null default 'pending',
  "clinical_status" varchar not null default 'not_started',
  "binding_status" varchar not null default 'unbound',
  "bound_at" datetime,
  "failure_category" varchar,
  "lease_token" varchar,
  "lease_expires_at" datetime,
  "checkpoint_json" text,
  "created_at" datetime,
  "updated_at" datetime,
  "preview_generation_started" integer,
  "preview_generation_completed" integer,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("source_id") references "phr_ehi_sources"("id") on delete set null,
  foreign key("created_by_user_id") references "users"("id") on delete set null,
  foreign key("bound_by_user_id") references "users"("id") on delete set null
);
CREATE UNIQUE INDEX "phr_ehi_packages_patient_manifest_uq" on "phr_ehi_packages"(
  "patient_id",
  "manifest_digest"
);
CREATE INDEX "phr_ehi_packages_patient_lease_idx" on "phr_ehi_packages"(
  "patient_id",
  "lease_expires_at"
);
CREATE TABLE "phr_ehi_blobs"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "sha256" varchar not null,
  "byte_size" integer not null,
  "storage_disk" varchar not null,
  "storage_path" varchar not null,
  "verified_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_ehi_blobs_patient_sha_uq" on "phr_ehi_blobs"(
  "patient_id",
  "sha256"
);
CREATE TABLE "phr_ehi_package_members"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "package_id" integer not null,
  "blob_id" integer not null,
  "path" varchar not null,
  "path_sha256" varchar not null,
  "original_path" varchar not null,
  "byte_size" integer not null,
  "sha256" varchar not null,
  "kind" varchar not null,
  "table_name" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("package_id") references "phr_ehi_packages"("id") on delete cascade,
  foreign key("blob_id") references "phr_ehi_blobs"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_ehi_members_package_path_uq" on "phr_ehi_package_members"(
  "package_id",
  "path_sha256"
);
CREATE INDEX "phr_ehi_members_package_kind_idx" on "phr_ehi_package_members"(
  "package_id",
  "kind"
);
CREATE TABLE "phr_ehi_table_accounts"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "package_id" integer not null,
  "member_id" integer,
  "table_name" varchar not null,
  "state" varchar not null,
  "header_columns_json" text,
  "unknown_columns_json" text,
  "missing_columns_json" text,
  "source_row_count" integer not null default '0',
  "malformed_row_count" integer not null default '0',
  "disposition_counts_json" text,
  "parser_version" varchar not null,
  "dictionary_fingerprint" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("package_id") references "phr_ehi_packages"("id") on delete cascade,
  foreign key("member_id") references "phr_ehi_package_members"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_ehi_accounts_package_table_uq" on "phr_ehi_table_accounts"(
  "package_id",
  "table_name"
);
CREATE TABLE "phr_ehi_findings"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "package_id" integer not null,
  "member_id" integer,
  "code" varchar not null,
  "severity" varchar not null,
  "line_number" integer,
  "byte_offset" integer,
  "column_name" varchar,
  "detail_json" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("package_id") references "phr_ehi_packages"("id") on delete cascade,
  foreign key("member_id") references "phr_ehi_package_members"("id") on delete cascade
);
CREATE INDEX "phr_ehi_findings_package_code_idx" on "phr_ehi_findings"(
  "package_id",
  "code"
);
CREATE TABLE "phr_ehi_entities"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "source_id" integer not null,
  "table_name" varchar not null,
  "key_digest" varchar not null,
  "key_json" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("source_id") references "phr_ehi_sources"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_ehi_entities_source_key_uq" on "phr_ehi_entities"(
  "source_id",
  "table_name",
  "key_digest"
);
CREATE TABLE "phr_ehi_revisions"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "entity_id" integer not null,
  "first_package_id" integer,
  "content_digest" varchar not null,
  "values_json" text not null,
  "dictionary_fingerprint" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("entity_id") references "phr_ehi_entities"("id") on delete cascade,
  foreign key("first_package_id") references "phr_ehi_packages"("id") on delete set null
);
CREATE UNIQUE INDEX "phr_ehi_revisions_entity_content_uq" on "phr_ehi_revisions"(
  "entity_id",
  "content_digest"
);
CREATE TABLE "phr_ehi_occurrences"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "package_id" integer not null,
  "member_id" integer not null,
  "revision_id" integer not null,
  "line_number" integer not null,
  "byte_offset" integer not null,
  "disposition" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("package_id") references "phr_ehi_packages"("id") on delete cascade,
  foreign key("member_id") references "phr_ehi_package_members"("id") on delete cascade,
  foreign key("revision_id") references "phr_ehi_revisions"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_ehi_occurrences_member_line_uq" on "phr_ehi_occurrences"(
  "member_id",
  "line_number"
);
CREATE INDEX "phr_ehi_occurrences_package_disp_idx" on "phr_ehi_occurrences"(
  "package_id",
  "disposition"
);
CREATE TABLE "phr_ehi_proposals"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "package_id" integer not null,
  "mapping" varchar not null,
  "mapping_version" varchar not null,
  "subject_key" varchar not null,
  "revision_digest" varchar not null,
  "kind" varchar not null,
  "target_table" varchar,
  "target_id" integer,
  "target_digest" varchar,
  "proposed_json" text,
  "before_json" text,
  "candidate_ids_json" text,
  "status" varchar not null default 'pending',
  "preview_digest" varchar not null,
  "preview_generation" integer,
  "decided_by_user_id" integer,
  "decided_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("package_id") references "phr_ehi_packages"("id") on delete cascade,
  foreign key("decided_by_user_id") references "users"("id") on delete set null
);
CREATE UNIQUE INDEX "phr_ehi_proposals_subject_uq" on "phr_ehi_proposals"(
  "package_id",
  "mapping",
  "mapping_version",
  "subject_key"
);
CREATE INDEX "phr_ehi_proposals_patient_status_idx" on "phr_ehi_proposals"(
  "patient_id",
  "status"
);
CREATE TABLE "phr_ehi_proposal_revisions"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "proposal_id" integer not null,
  "revision_id" integer not null,
  "position" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("proposal_id") references "phr_ehi_proposals"("id") on delete cascade,
  foreign key("revision_id") references "phr_ehi_revisions"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_ehi_proposal_revisions_uq" on "phr_ehi_proposal_revisions"(
  "proposal_id",
  "revision_id"
);
CREATE TABLE "genai_mcp_requests"(
  "id" varchar not null,
  "mailbox_id" varchar not null,
  "queue" varchar not null default('default'),
  "status" varchar not null default('pending'),
  "priority" integer not null default('0'),
  "payload" text not null,
  "metadata" text,
  "idempotency_key" varchar,
  "enqueue_hash" varchar,
  "available_at" datetime not null,
  "expires_at" datetime,
  "attempt_count" integer not null default('0'),
  "max_attempts" integer not null default('3'),
  "leased_at" datetime,
  "lease_expires_at" datetime,
  "lease_token_hash" varchar,
  "lease_principal" varchar,
  "completed_at" datetime,
  "failed_at" datetime,
  "result" text,
  "error" text,
  "completion_hash" varchar,
  "completion_lease_hash" varchar,
  "completion_principal" varchar,
  "completion_receipt_id" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("mailbox_id") references "genai_mcp_mailboxes"("id") on delete cascade on update no action,
  primary key("id")
);
CREATE INDEX "genai_mcp_claim_idx" on "genai_mcp_requests"(
  "mailbox_id",
  "queue",
  "status",
  "available_at",
  "priority"
);
CREATE UNIQUE INDEX "genai_mcp_requests_mailbox_id_idempotency_key_unique" on "genai_mcp_requests"(
  "mailbox_id",
  "idempotency_key"
);
CREATE TABLE "genai_mcp_deliveries"(
  "id" varchar not null,
  "request_id" varchar not null,
  "type" varchar not null,
  "payload" text not null,
  "attempt_count" integer not null default('0'),
  "available_at" datetime not null,
  "acknowledged_at" datetime,
  "leased_until" datetime,
  "lease_owner" varchar,
  "last_error" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("request_id") references "genai_mcp_requests"("id") on delete cascade on update no action,
  primary key("id")
);
CREATE INDEX "genai_mcp_deliveries_pending_idx" on "genai_mcp_deliveries"(
  "acknowledged_at",
  "available_at",
  "leased_until"
);
CREATE UNIQUE INDEX "genai_mcp_deliveries_request_id_type_unique" on "genai_mcp_deliveries"(
  "request_id",
  "type"
);
CREATE TABLE "genai_mcp_claim_receipts"(
  "id" varchar not null,
  "request_id" varchar not null,
  "mailbox_id" varchar not null,
  "principal_key" varchar not null,
  "idempotency_key" varchar not null,
  "queue_filter" varchar,
  "expires_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("mailbox_id") references "genai_mcp_mailboxes"("id") on delete cascade on update no action,
  foreign key("request_id") references "genai_mcp_requests"("id") on delete cascade on update no action,
  primary key("id")
);
CREATE UNIQUE INDEX "genai_mcp_claim_receipt_unique" on "genai_mcp_claim_receipts"(
  "principal_key",
  "idempotency_key"
);
CREATE TABLE "phr_ehi_preview_runs"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "package_id" integer not null,
  "requested_by_user_id" integer,
  "status" varchar not null,
  "failure_reason" varchar,
  "result_json" text,
  "started_at" datetime,
  "completed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("package_id") references "phr_ehi_packages"("id") on delete cascade,
  foreign key("requested_by_user_id") references "users"("id") on delete set null
);
CREATE INDEX "phr_ehi_preview_runs_package_status_idx" on "phr_ehi_preview_runs"(
  "package_id",
  "status"
);
CREATE TABLE "phr_ehi_uploads"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "actor_user_id" integer,
  "package_id" integer,
  "storage_disk" varchar not null,
  "storage_path" varchar,
  "file_size_bytes" integer not null,
  "uploaded_bytes" integer not null default('0'),
  "status" varchar not null,
  "failure_reason" varchar,
  "result_json" text,
  "expires_at" datetime not null,
  "completed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "format" varchar,
  "source_document_id" integer,
  "transport" varchar not null default 'chunks',
  "multipart_upload_id" varchar,
  "part_size_bytes" integer,
  "part_url_expires_at" datetime,
  foreign key("package_id") references "phr_ehi_packages"("id") on delete set null on update no action,
  foreign key("actor_user_id") references "users"("id") on delete set null on update no action,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade on update no action,
  foreign key("source_document_id") references "phr_documents"("id") on delete set null
);
CREATE INDEX "phr_ehi_uploads_patient_status_idx" on "phr_ehi_uploads"(
  "patient_id",
  "status"
);
CREATE INDEX "phr_ehi_uploads_status_expires_idx" on "phr_ehi_uploads"(
  "status",
  "expires_at"
);
CREATE TABLE "phr_medication_fills"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "user_id" integer not null,
  "medication_id" integer,
  "import_source" varchar,
  "external_id" varchar,
  "source_document_id" integer,
  "review_status" varchar not null default 'confirmed',
  "patient_name_as_printed" varchar,
  "ndc_as_captured" varchar,
  "ndc_10" varchar,
  "ndc_11" varchar,
  "ndc_status" varchar not null default 'manual',
  "substance_names" text,
  "strengths" text,
  "brand_name" varchar,
  "dosage_form" varchar,
  "route" varchar,
  "labeler_name" varchar,
  "package_description" varchar,
  "rxcui" varchar,
  "drug_as_printed" varchar,
  "fda_snapshot" text,
  "fda_fetched_at" datetime,
  "filled_on" date not null,
  "quantity" numeric not null,
  "quantity_unit" varchar,
  "days_supply" integer,
  "rx_number" varchar,
  "refills_remaining" integer,
  "directions" text,
  "prescriber_name" varchar,
  "paid_cents" integer,
  "retail_cents" integer,
  "pharmacy_name" varchar,
  "pharmacy_location" varchar,
  "source_kind" varchar not null default 'manual',
  "source_locator" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "retracted_at" datetime,
  "ndc_source" varchar,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("medication_id") references "phr_medications"("id") on delete set null
);
CREATE UNIQUE INDEX "phr_fills_imp_uid" on "phr_medication_fills"(
  "patient_id",
  "import_source",
  "external_id"
);
CREATE INDEX "phr_fills_patient_filled_idx" on "phr_medication_fills"(
  "patient_id",
  "filled_on"
);
CREATE INDEX "phr_medication_fills_lifecycle_idx" on "phr_medication_fills"(
  "patient_id",
  "deleted_at",
  "retracted_at"
);
CREATE INDEX "phr_medication_fills_patient_id_index" on "phr_medication_fills"(
  "patient_id"
);
CREATE INDEX "phr_medication_fills_user_id_index" on "phr_medication_fills"(
  "user_id"
);
CREATE INDEX "phr_medication_fills_medication_id_index" on "phr_medication_fills"(
  "medication_id"
);
CREATE INDEX "phr_medication_fills_source_document_id_index" on "phr_medication_fills"(
  "source_document_id"
);
CREATE INDEX "phr_medication_fills_review_status_index" on "phr_medication_fills"(
  "review_status"
);
CREATE TABLE "phr_document_uploads"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "patient_id" integer not null,
  "actor_user_id" integer not null,
  "import_source" varchar not null,
  "kind" varchar not null,
  "filename" varchar not null,
  "content_type" varchar not null,
  "byte_size" integer not null,
  "sha256" varchar,
  "status" varchar not null default 'pending',
  "staging_path" varchar,
  "received_size" integer,
  "received_sha256" varchar,
  "expires_at" datetime not null,
  "uploaded_at" datetime,
  "finalized_at" datetime,
  "document_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "storage_disk" varchar not null default 'phr_documents',
  "transport" varchar not null default 'local',
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("actor_user_id") references "users"("id") on delete cascade,
  foreign key("document_id") references "phr_documents"("id") on delete set null
);
CREATE UNIQUE INDEX "phr_document_uploads_public_id_unique" on "phr_document_uploads"(
  "public_id"
);
CREATE INDEX "phr_document_uploads_patient_id_index" on "phr_document_uploads"(
  "patient_id"
);
CREATE INDEX "phr_document_uploads_status_index" on "phr_document_uploads"(
  "status"
);
CREATE INDEX "phr_document_uploads_expires_at_index" on "phr_document_uploads"(
  "expires_at"
);
CREATE INDEX "phr_document_uploads_document_id_index" on "phr_document_uploads"(
  "document_id"
);
CREATE INDEX "phr_eobs_patient_review_idx" on "phr_eobs"(
  "patient_id",
  "review_status"
);
CREATE TABLE "phr_medication_fill_evidence"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "fill_id" integer not null,
  "document_id" integer,
  "eob_line_id" integer,
  "source_kind" varchar not null,
  "source_locator" varchar,
  "evidence_key" varchar not null,
  "import_source" varchar,
  "external_id" varchar,
  "related_fill_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("fill_id") references "phr_medication_fills"("id") on delete cascade,
  foreign key("document_id") references "phr_documents"("id") on delete set null,
  foreign key("eob_line_id") references "phr_eob_lines"("id") on delete set null,
  foreign key("related_fill_id") references "phr_medication_fills"("id") on delete set null
);
CREATE UNIQUE INDEX "phr_fill_evidence_uid" on "phr_medication_fill_evidence"(
  "fill_id",
  "evidence_key"
);
CREATE INDEX "phr_fill_evidence_alias_idx" on "phr_medication_fill_evidence"(
  "patient_id",
  "import_source",
  "external_id"
);
CREATE INDEX "phr_medication_fill_evidence_patient_id_index" on "phr_medication_fill_evidence"(
  "patient_id"
);
CREATE INDEX "phr_medication_fill_evidence_document_id_index" on "phr_medication_fill_evidence"(
  "document_id"
);
CREATE INDEX "phr_medication_fill_evidence_eob_line_id_index" on "phr_medication_fill_evidence"(
  "eob_line_id"
);
CREATE INDEX "phr_medication_fill_evidence_related_fill_id_index" on "phr_medication_fill_evidence"(
  "related_fill_id"
);
CREATE TABLE "phr_storage_settings"(
  "id" integer not null,
  "global_limit_bytes" integer,
  "default_user_limit_bytes" integer,
  primary key("id")
);
CREATE TABLE "phr_user_storage_limits"(
  "user_id" integer not null,
  "limit_bytes" integer,
  "unlimited" tinyint(1) not null default '0',
  foreign key("user_id") references "users"("id") on delete cascade,
  primary key("user_id")
);
CREATE TABLE "phr_documents"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "user_id" integer not null,
  "uploaded_by_user_id" integer,
  "genai_job_id" integer,
  "title" varchar,
  "document_type" varchar not null default 'other',
  "original_filename" varchar,
  "storage_disk" varchar not null default('phr_documents'),
  "storage_path" varchar,
  "mime_type" varchar,
  "extracted_text" text,
  "summary" text,
  "source" varchar,
  "import_source" varchar,
  "external_id" varchar,
  "imported_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "observed_at" datetime,
  "byte_size" integer not null default('0'),
  "file_hash" varchar,
  "tags" text,
  "deleted_at" datetime,
  "retracted_at" datetime,
  foreign key("genai_job_id") references "genai_import_jobs"("id") on delete set null on update no action,
  foreign key("uploaded_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade on update no action
);
CREATE INDEX "phr_docs_agent_hash_idx" on "phr_documents"(
  "patient_id",
  "import_source",
  "file_hash"
);
CREATE INDEX "phr_docs_genai_idx" on "phr_documents"("genai_job_id");
CREATE UNIQUE INDEX "phr_docs_imp_uid" on "phr_documents"(
  "patient_id",
  "import_source",
  "external_id"
);
CREATE INDEX "phr_docs_patient_idx" on "phr_documents"("patient_id");
CREATE INDEX "phr_docs_patient_observed_idx" on "phr_documents"(
  "patient_id",
  "observed_at"
);
CREATE INDEX "phr_docs_patient_source_idx" on "phr_documents"(
  "patient_id",
  "source"
);
CREATE INDEX "phr_docs_patient_type_idx" on "phr_documents"(
  "patient_id",
  "document_type"
);
CREATE INDEX "phr_docs_user_idx" on "phr_documents"("user_id");
CREATE INDEX "phr_documents_lifecycle_idx" on "phr_documents"(
  "patient_id",
  "deleted_at",
  "retracted_at"
);
CREATE TABLE "phr_ehi_record_links"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "revision_id" integer not null,
  "proposal_id" integer,
  "immunization_id" integer,
  "document_id" integer,
  "link_kind" varchar not null,
  "mapping_version" varchar not null,
  "created_by_user_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "lab_result_id" integer,
  "bridge_version" varchar,
  "bridge_rule" varchar,
  "office_visit_id" integer,
  foreign key("lab_result_id") references "phr_lab_results"("id") on delete cascade on update no action,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade on update no action,
  foreign key("revision_id") references "phr_ehi_revisions"("id") on delete cascade on update no action,
  foreign key("proposal_id") references "phr_ehi_proposals"("id") on delete set null on update no action,
  foreign key("immunization_id") references "phr_immunizations"("id") on delete cascade on update no action,
  foreign key("document_id") references "phr_documents"("id") on delete cascade on update no action,
  foreign key("created_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("office_visit_id") references "phr_office_visits"("id") on delete cascade
);
CREATE UNIQUE INDEX "phr_ehi_links_rev_doc_uq" on "phr_ehi_record_links"(
  "revision_id",
  "document_id",
  "link_kind"
);
CREATE UNIQUE INDEX "phr_ehi_links_rev_imm_uq" on "phr_ehi_record_links"(
  "revision_id",
  "immunization_id",
  "link_kind"
);
CREATE UNIQUE INDEX "phr_ehi_links_rev_lab_uq" on "phr_ehi_record_links"(
  "revision_id",
  "lab_result_id",
  "link_kind"
);
CREATE UNIQUE INDEX "phr_ehi_links_rev_visit_uq" on "phr_ehi_record_links"(
  "revision_id",
  "office_visit_id",
  "link_kind"
);
CREATE TABLE "phr_ehi_proposal_decisions"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "proposal_id" integer not null,
  "actor_user_id" integer,
  "decision" varchar not null,
  "previous_status" varchar not null,
  "mapping_version" varchar not null,
  "revision_digest" varchar not null,
  "linked_immunization_id" integer,
  "linked_document_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "linked_lab_result_id" integer,
  "linked_office_visit_id" integer,
  foreign key("linked_lab_result_id") references "phr_lab_results"("id") on delete set null on update no action,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade on update no action,
  foreign key("proposal_id") references "phr_ehi_proposals"("id") on delete cascade on update no action,
  foreign key("actor_user_id") references "users"("id") on delete set null on update no action,
  foreign key("linked_immunization_id") references "phr_immunizations"("id") on delete set null on update no action,
  foreign key("linked_document_id") references "phr_documents"("id") on delete set null on update no action,
  foreign key("linked_office_visit_id") references "phr_office_visits"("id") on delete set null
);
CREATE TABLE "phr_dicom_upload_reservations"(
  "id" integer primary key autoincrement not null,
  "patient_id" integer not null,
  "upload_id" integer not null,
  "staging_key" varchar not null,
  "byte_size" integer not null,
  "expires_at" datetime not null,
  "closed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("patient_id") references "phr_patients"("id") on delete cascade,
  foreign key("upload_id") references "phr_dicom_uploads"("id") on delete cascade
);
CREATE INDEX "phr_dicom_reservations_patient_idx" on "phr_dicom_upload_reservations"(
  "patient_id"
);
CREATE INDEX "phr_dicom_reservations_upload_idx" on "phr_dicom_upload_reservations"(
  "upload_id",
  "expires_at"
);
CREATE UNIQUE INDEX "phr_dicom_upload_reservations_staging_key_unique" on "phr_dicom_upload_reservations"(
  "staging_key"
);

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(4,'2026_03_22_000001_create_user_ai_configurations_table',1);
INSERT INTO migrations VALUES(5,'2026_03_23_000001_create_genai_import_jobs_table',1);
INSERT INTO migrations VALUES(6,'2026_03_23_000002_create_genai_import_results_table',1);
INSERT INTO migrations VALUES(7,'2026_03_23_000003_create_genai_daily_quota_table',1);
INSERT INTO migrations VALUES(8,'2026_03_23_000004_add_genai_columns_to_users_table',1);
INSERT INTO migrations VALUES(9,'2026_05_03_000000_create_bherila_auth_tables',1);
INSERT INTO migrations VALUES(10,'2026_05_17_042848_create_missing_phr_tables_if_needed',1);
INSERT INTO migrations VALUES(11,'2026_05_17_042849_normalize_phr_patient_schema',1);
INSERT INTO migrations VALUES(12,'2026_05_17_060948_create_phr_dicom_tables',1);
INSERT INTO migrations VALUES(13,'2026_05_17_184730_create_phr_conditions_table',1);
INSERT INTO migrations VALUES(14,'2026_05_17_184730_create_phr_medications_table',1);
INSERT INTO migrations VALUES(15,'2026_05_17_184730_create_phr_office_visits_table',1);
INSERT INTO migrations VALUES(16,'2026_05_17_184730_create_phr_procedures_table',1);
INSERT INTO migrations VALUES(17,'2026_05_17_184731_create_phr_allergies_table',1);
INSERT INTO migrations VALUES(18,'2026_05_17_184731_create_phr_immunizations_table',1);
INSERT INTO migrations VALUES(19,'2026_05_17_225900_add_import_source_columns_to_phr_tables',1);
INSERT INTO migrations VALUES(20,'2026_05_17_225901_create_phr_documents_table',1);
INSERT INTO migrations VALUES(21,'2026_05_17_225902_create_phr_exports_table',1);
INSERT INTO migrations VALUES(22,'2026_05_18_073239_normalize_phr_documents_browser_schema',1);
INSERT INTO migrations VALUES(23,'2026_05_18_073242_add_source_document_links_to_phr_tables',1);
INSERT INTO migrations VALUES(24,'2026_05_18_201245_drop_legacy_phr_documents_columns',1);
INSERT INTO migrations VALUES(25,'2026_06_03_110000_add_passkey_credential_id_hash',1);
INSERT INTO migrations VALUES(26,'2026_06_05_000000_create_auth_audit_log_table',1);
INSERT INTO migrations VALUES(27,'2026_06_14_090102_create_phr_negative_assertions_table',1);
INSERT INTO migrations VALUES(28,'2026_06_14_090102_create_phr_portal_messages_table',1);
INSERT INTO migrations VALUES(29,'2026_07_13_000000_create_phr_respiratory_events_table',1);
INSERT INTO migrations VALUES(30,'2026_07_13_193359_create_phr_health_logs_and_entries_tables',1);
INSERT INTO migrations VALUES(31,'2026_07_22_081116_alter_phr_respiratory_events_add_intensity_and_correction',1);
INSERT INTO migrations VALUES(32,'2026_07_22_081116_create_phr_sinus_enrollments_table',1);
INSERT INTO migrations VALUES(33,'2026_07_22_081116_create_phr_sinus_settings_table',1);
INSERT INTO migrations VALUES(34,'2026_07_26_052603_add_mcp_api_key_lifecycle_to_users_table',1);
INSERT INTO migrations VALUES(35,'2026_07_26_060000_add_oauth_identity_to_users_table',1);
INSERT INTO migrations VALUES(36,'2026_08_02_000000_create_uptime_runs_table',1);
INSERT INTO migrations VALUES(37,'2026_08_03_000001_create_phr_native_backup_tables',1);
INSERT INTO migrations VALUES(38,'2026_08_09_000001_create_phr_eob_tables',1);
INSERT INTO migrations VALUES(39,'2026_08_10_000001_add_claim_fingerprint_to_phr_eobs',1);
INSERT INTO migrations VALUES(40,'2026_08_10_000002_create_phr_office_visit_eobs_table',1);
INSERT INTO migrations VALUES(41,'2026_08_10_000003_create_phr_procedure_eobs_table',1);
INSERT INTO migrations VALUES(42,'2026_08_11_000001_create_phr_device_pairing_codes_table',1);
INSERT INTO migrations VALUES(43,'2026_08_11_000002_create_phr_device_keys_table',1);
INSERT INTO migrations VALUES(44,'2026_08_12_000000_normalize_device_pairing_expires_at',1);
INSERT INTO migrations VALUES(45,'2026_08_15_000001_add_delta_dental_fields_to_phr_eobs',1);
INSERT INTO migrations VALUES(46,'2026_08_16_000001_create_phr_blob_migrations_table',1);
INSERT INTO migrations VALUES(47,'2026_08_16_000002_create_phr_patient_deletions',1);
INSERT INTO migrations VALUES(48,'2026_08_16_000003_create_phr_native_restore_attempts',1);
INSERT INTO migrations VALUES(49,'2026_08_16_093224_create_oauth_auth_codes_table',1);
INSERT INTO migrations VALUES(50,'2026_08_16_093225_create_oauth_access_tokens_table',1);
INSERT INTO migrations VALUES(51,'2026_08_16_093226_create_oauth_refresh_tokens_table',1);
INSERT INTO migrations VALUES(52,'2026_08_16_093227_create_oauth_clients_table',1);
INSERT INTO migrations VALUES(53,'2026_08_16_100000_create_agent_api_audits_table',1);
INSERT INTO migrations VALUES(54,'2026_08_16_100001_add_oauth_security_version_to_users',1);
INSERT INTO migrations VALUES(55,'2026_08_16_100002_create_oauth_token_families_table',1);
INSERT INTO migrations VALUES(56,'2026_08_16_100003_add_restored_at_to_phr_native_record_identities',1);
INSERT INTO migrations VALUES(57,'2026_08_16_100004_add_oauth_client_registration_and_resource_audience',1);
INSERT INTO migrations VALUES(58,'2026_08_16_180000_create_phr_office_visit_dicom_studies_table',1);
INSERT INTO migrations VALUES(59,'2026_08_16_210000_add_review_status_to_agent_writable_clinical_records',1);
INSERT INTO migrations VALUES(60,'2026_08_16_220000_index_agent_document_deduplication',1);
INSERT INTO migrations VALUES(61,'2026_08_16_230000_create_agent_api_mutation_identities_table',1);
INSERT INTO migrations VALUES(62,'2026_08_20_120000_add_review_status_to_remaining_agent_clinical_records',1);
INSERT INTO migrations VALUES(63,'2026_08_22_090000_reset_agent_self_asserted_review_status',1);
INSERT INTO migrations VALUES(64,'2026_08_22_120000_make_agent_external_id_binary',1);
INSERT INTO migrations VALUES(65,'2026_08_23_100000_add_record_lifecycle_columns',1);
INSERT INTO migrations VALUES(66,'2026_08_24_120000_add_rp_id_to_passkey_credentials',1);
INSERT INTO migrations VALUES(67,'2026_09_02_000000_add_oauth_server_metadata',1);
INSERT INTO migrations VALUES(68,'2026_09_07_000000_create_delegated_access_nonces',1);
INSERT INTO migrations VALUES(69,'2026_09_15_000000_create_genai_mcp_tables',1);
INSERT INTO migrations VALUES(70,'2026_09_15_100000_add_external_genai_execution',1);
INSERT INTO migrations VALUES(71,'2026_10_10_000000_create_delegated_access_receipts',1);
INSERT INTO migrations VALUES(72,'2026_10_11_000000_add_provider_identity_to_oauth_credentials',1);
INSERT INTO migrations VALUES(73,'2026_10_11_120000_add_client_id_to_oauth_refresh_tokens',1);
INSERT INTO migrations VALUES(74,'2026_10_19_000000_create_oauth_client_consents',1);
INSERT INTO migrations VALUES(75,'2026_10_20_000000_make_native_record_identities_globally_unique',1);
INSERT INTO migrations VALUES(76,'2026_10_20_120000_add_oauth_patient_consents',1);
INSERT INTO migrations VALUES(77,'2026_10_21_000000_create_phr_ehi_tables',1);
INSERT INTO migrations VALUES(78,'2026_10_22_000000_create_phr_ehi_evidence_tables',1);
INSERT INTO migrations VALUES(79,'2026_10_22_000000_preserve_genai_mcp_scheduling_instants',1);
INSERT INTO migrations VALUES(80,'2026_10_28_000000_create_phr_ehi_uploads',1);
INSERT INTO migrations VALUES(81,'2026_10_28_000001_create_phr_ehi_preview_runs',1);
INSERT INTO migrations VALUES(82,'2026_10_28_000002_add_format_to_phr_ehi_uploads',1);
INSERT INTO migrations VALUES(83,'2026_10_28_000003_add_source_document_to_phr_ehi_uploads',1);
INSERT INTO migrations VALUES(84,'2026_10_29_000000_add_genai_execution_mode_chosen_at_to_users',1);
INSERT INTO migrations VALUES(85,'2026_10_31_000000_normalize_legacy_phr_document_sources',1);
INSERT INTO migrations VALUES(86,'2026_10_31_000001_undouble_phr_lab_reference_range_text',1);
INSERT INTO migrations VALUES(87,'2026_10_31_000002_drop_repeated_units_from_phr_lab_reference_range_text',1);
INSERT INTO migrations VALUES(88,'2026_10_31_211137_add_oauth_identity_to_users_table',1);
INSERT INTO migrations VALUES(89,'2026_11_01_000000_create_phr_medication_fills_table',1);
INSERT INTO migrations VALUES(90,'2026_11_01_000001_add_force_lowercase_display_to_users',1);
INSERT INTO migrations VALUES(91,'2026_11_01_000002_backfill_ndc_10_on_phr_medication_fills',1);
INSERT INTO migrations VALUES(92,'2026_11_01_000003_run_fill_ndc_text_renormalize',1);
INSERT INTO migrations VALUES(93,'2026_11_01_000004_drop_force_lowercase_display_from_users',1);
INSERT INTO migrations VALUES(94,'2026_11_01_000005_add_ndc_source_to_phr_medication_fills',1);
INSERT INTO migrations VALUES(95,'2026_11_01_000006_run_fill_resolve_discontinued',1);
INSERT INTO migrations VALUES(96,'2026_11_01_100000_create_phr_document_uploads_table',1);
INSERT INTO migrations VALUES(97,'2026_11_01_100001_add_review_status_to_phr_eobs',1);
INSERT INTO migrations VALUES(98,'2026_11_01_100002_create_phr_medication_fill_evidence_table',1);
INSERT INTO migrations VALUES(99,'2026_11_01_120000_add_range_bound_inclusive_to_phr_lab_results',1);
INSERT INTO migrations VALUES(100,'2026_11_01_200000_add_blob_storage_limits',1);
INSERT INTO migrations VALUES(101,'2026_11_01_200000_default_phr_documents_document_type_to_other',1);
INSERT INTO migrations VALUES(102,'2026_11_02_000000_add_display_preferences_to_users',1);
INSERT INTO migrations VALUES(103,'2026_11_02_100000_add_real_names_to_phr_patients',1);
INSERT INTO migrations VALUES(104,'2026_11_02_200000_make_genai_import_context_json_on_postgresql',1);
INSERT INTO migrations VALUES(105,'2026_11_02_300000_add_ehi_lab_provenance',1);
INSERT INTO migrations VALUES(106,'2026_11_02_300001_add_ehi_encounter_context',1);
INSERT INTO migrations VALUES(107,'2026_11_03_000000_add_direct_transport_to_phr_ehi_uploads',1);
INSERT INTO migrations VALUES(108,'2026_11_03_000001_add_signed_write_windows_to_uploads',1);
INSERT INTO migrations VALUES(109,'2026_11_03_000002_create_phr_dicom_upload_reservations',1);

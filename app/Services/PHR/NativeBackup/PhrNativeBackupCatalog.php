<?php

namespace App\Services\PHR\NativeBackup;

/**
 * Explicit patient-scoped native-backup contract.
 *
 * This mirrors PhrStorageMap's declaration style but answers a different question:
 * which patient rows belong in a lossless archive. Every migration-reachable table
 * must be included here or excluded with a reason; the coverage test enforces that.
 */
final class PhrNativeBackupCatalog
{
    public const int SCHEMA_VERSION = 1;

    public const string FORMAT = 'phr-native-v1';

    /**
     * @return array<string, array{
     *   patient_column: string,
     *   root?: bool,
     *   relationships?: array<string, array{target: string, kind: 'record'|'actor', nullable: bool}>,
     *   excluded_columns?: array<string, string>,
     *   row_policy?: array{code: string, because: string}
     * }>
     */
    public static function included(): array
    {
        $clinical = static fn (): array => [
            'user_id' => self::actor(false),
            'source_document_id' => self::record('phr_documents', true),
        ];

        return [
            'phr_patients' => [
                'patient_column' => 'id',
                'root' => true,
                'relationships' => ['owner_user_id' => self::actor(false)],
            ],
            // Documents intentionally use DB::table(), so soft-deleted rows and their
            // deleted_at values are preserved rather than silently dropped by Eloquent.
            'phr_documents' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'user_id' => self::actor(false),
                    'uploaded_by_user_id' => self::actor(true),
                ],
                'excluded_columns' => [
                    'genai_job_id' => 'Operational import job; restore sets it to null.',
                    'storage_disk' => 'Archive reader selects the owned document disk.',
                    'storage_path' => 'Storage keys are regenerated from opaque artifact paths.',
                ],
            ],
            'phr_eobs' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'user_id' => self::actor(false),
                    'source_document_id' => self::record('phr_documents', true),
                ],
            ],
            'phr_eob_lines' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'eob_id' => self::record('phr_eobs', false),
                ],
            ],
            'phr_dicom_uploads' => [
                'patient_column' => 'patient_id',
                'relationships' => ['uploaded_by_user_id' => self::actor(false)],
                'excluded_columns' => [
                    'r2_prefix' => 'Storage prefixes are regenerated during restore.',
                    'error_message' => 'Operational failure detail is not authoritative patient data.',
                ],
                'row_policy' => [
                    'code' => 'authoritative_uploads',
                    'because' => 'Include processed uploads and any upload anchoring an original DICOM; omit empty temporary attempts.',
                ],
            ],
            'phr_dicom_studies' => [
                'patient_column' => 'patient_id',
                'relationships' => ['upload_id' => self::record('phr_dicom_uploads', true)],
            ],
            'phr_dicom_series' => [
                'patient_column' => 'patient_id',
                'relationships' => ['study_id' => self::record('phr_dicom_studies', false)],
            ],
            'phr_dicom_files' => [
                'patient_column' => 'patient_id',
                'relationships' => ['upload_id' => self::record('phr_dicom_uploads', false)],
                'excluded_columns' => [
                    'r2_key' => 'Storage keys are regenerated from opaque artifact paths.',
                ],
                'row_policy' => [
                    'code' => 'original_dicom_only',
                    'because' => 'Derived volume caches are reproducible; DICOM and DICOMDIR sources are authoritative.',
                ],
            ],
            'phr_dicom_instances' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'study_id' => self::record('phr_dicom_studies', false),
                    'series_id' => self::record('phr_dicom_series', false),
                    'upload_id' => self::record('phr_dicom_uploads', false),
                    'file_id' => self::record('phr_dicom_files', false),
                ],
                'row_policy' => [
                    'code' => 'original_dicom_instances',
                    'because' => 'Only instances backed by authoritative original DICOM files are retained.',
                ],
            ],
            'phr_health_logs' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'user_id' => self::actor(false),
                    'created_by_user_id' => self::actor(true),
                ],
            ],
            'phr_health_log_entries' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'health_log_id' => self::record('phr_health_logs', false),
                    'user_id' => self::actor(false),
                    'recorded_by_user_id' => self::actor(true),
                ],
            ],
            'phr_lab_results' => ['patient_column' => 'patient_id', 'relationships' => $clinical()],
            'phr_patient_vitals' => ['patient_column' => 'patient_id', 'relationships' => $clinical()],
            'phr_office_visits' => ['patient_column' => 'patient_id', 'relationships' => $clinical()],
            'phr_office_visit_eobs' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'office_visit_id' => self::record('phr_office_visits', false),
                    'eob_id' => self::record('phr_eobs', false),
                ],
            ],
            'phr_office_visit_dicom_studies' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'office_visit_id' => self::record('phr_office_visits', false),
                    'dicom_study_id' => self::record('phr_dicom_studies', false),
                ],
            ],
            'phr_medications' => ['patient_column' => 'patient_id', 'relationships' => $clinical()],
            'phr_conditions' => ['patient_column' => 'patient_id', 'relationships' => $clinical()],
            'phr_procedures' => ['patient_column' => 'patient_id', 'relationships' => $clinical()],
            'phr_procedure_eobs' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'procedure_id' => self::record('phr_procedures', false),
                    'eob_id' => self::record('phr_eobs', false),
                ],
            ],
            'phr_immunizations' => ['patient_column' => 'patient_id', 'relationships' => $clinical()],
            'phr_allergies' => ['patient_column' => 'patient_id', 'relationships' => $clinical()],
            'phr_portal_messages' => ['patient_column' => 'patient_id', 'relationships' => $clinical()],
            'phr_negative_assertions' => ['patient_column' => 'patient_id', 'relationships' => $clinical()],
            'phr_respiratory_events' => ['patient_column' => 'phr_patient_id'],
            'phr_sinus_settings' => ['patient_column' => 'phr_patient_id'],
            'phr_sinus_enrollments' => ['patient_column' => 'phr_patient_id'],
            // EHI Tables intake (#213). Parents first: restore inserts in this order.
            // Member bytes are archived once per content-addressed blob row.
            'phr_ehi_sources' => [
                'patient_column' => 'patient_id',
                'relationships' => ['created_by_user_id' => self::actor(true)],
            ],
            'phr_ehi_packages' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'source_id' => self::record('phr_ehi_sources', true),
                    'created_by_user_id' => self::actor(true),
                    'bound_by_user_id' => self::actor(true),
                ],
                'excluded_columns' => [
                    'lease_token' => 'Operational intake lease; restore sets it to null.',
                    'lease_expires_at' => 'Operational intake lease; restore sets it to null.',
                    'checkpoint_json' => 'Operational resume state, not authoritative patient data.',
                    'preview_generation_started' => 'Preview-run bookkeeping; a restored package must be re-previewed before review.',
                    'preview_generation_completed' => 'Preview-run bookkeeping; a restored package must be re-previewed before review.',
                ],
            ],
            'phr_ehi_blobs' => [
                'patient_column' => 'patient_id',
                'excluded_columns' => [
                    'storage_disk' => 'Archive reader selects the configured EHI disk.',
                    'storage_path' => 'Storage keys are regenerated from the content address.',
                ],
            ],
            'phr_ehi_package_members' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'package_id' => self::record('phr_ehi_packages', false),
                    'blob_id' => self::record('phr_ehi_blobs', false),
                ],
            ],
            'phr_ehi_table_accounts' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'package_id' => self::record('phr_ehi_packages', false),
                    'member_id' => self::record('phr_ehi_package_members', true),
                ],
            ],
            'phr_ehi_findings' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'package_id' => self::record('phr_ehi_packages', false),
                    'member_id' => self::record('phr_ehi_package_members', true),
                ],
            ],
            // EHI evidence, proposals, and review (#213 M2), after the clinical
            // tables they link to. Proposal target_id and candidate_ids_json are
            // preview-time ids kept verbatim; preview generations are not archived,
            // so a restored pending proposal is refused until it is re-previewed.
            'phr_ehi_entities' => [
                'patient_column' => 'patient_id',
                'relationships' => ['source_id' => self::record('phr_ehi_sources', false)],
            ],
            'phr_ehi_revisions' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'entity_id' => self::record('phr_ehi_entities', false),
                    'first_package_id' => self::record('phr_ehi_packages', true),
                ],
            ],
            'phr_ehi_occurrences' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'package_id' => self::record('phr_ehi_packages', false),
                    'member_id' => self::record('phr_ehi_package_members', false),
                    'revision_id' => self::record('phr_ehi_revisions', false),
                ],
            ],
            'phr_ehi_proposals' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'package_id' => self::record('phr_ehi_packages', false),
                    'decided_by_user_id' => self::actor(true),
                ],
                'excluded_columns' => [
                    'preview_generation' => 'Preview-run bookkeeping; a restored pending proposal must be re-previewed before review.',
                ],
            ],
            'phr_ehi_proposal_revisions' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'proposal_id' => self::record('phr_ehi_proposals', false),
                    'revision_id' => self::record('phr_ehi_revisions', false),
                ],
            ],
            'phr_ehi_proposal_decisions' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'proposal_id' => self::record('phr_ehi_proposals', false),
                    'actor_user_id' => self::actor(true),
                    'linked_immunization_id' => self::record('phr_immunizations', true),
                    'linked_document_id' => self::record('phr_documents', true),
                ],
            ],
            'phr_ehi_record_links' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    'revision_id' => self::record('phr_ehi_revisions', false),
                    'proposal_id' => self::record('phr_ehi_proposals', true),
                    'immunization_id' => self::record('phr_immunizations', true),
                    'document_id' => self::record('phr_documents', true),
                    'created_by_user_id' => self::actor(true),
                ],
            ],
            'phr_patient_user_access' => [
                'patient_column' => 'patient_id',
                'relationships' => [
                    // Access-grant actors are stable opaque UUIDs. No third-party
                    // email, name, or source database id enters the archive.
                    'user_id' => self::actor(false),
                    'granted_by_user_id' => self::actor(true),
                ],
            ],
        ];
    }

    /** @return array<string, array{patient_column: string, because: string}> */
    public static function excluded(): array
    {
        return [
            'agent_api_mutation_identities' => [
                'patient_column' => 'patient_id',
                'because' => 'OAuth-client retry ledger; restored clinical rows receive fresh integration identities.',
            ],
            'phr_exports' => [
                'patient_column' => 'patient_id',
                'because' => 'Generated interoperability output is reproducible and keeps its existing contract.',
            ],
            'phr_native_backups' => [
                'patient_column' => 'patient_id',
                'because' => 'Generated native archives are operational output, never recursive archive input.',
            ],
            'phr_native_record_identities' => [
                'patient_column' => 'patient_id',
                'because' => 'Mappings are materialized as each record nativeId and recreated beside restored rows.',
            ],
            'phr_blob_migrations' => [
                'patient_column' => 'patient_id',
                'because' => 'Operational rollout and rollback ledger; restored blobs are written directly to canonical keys.',
            ],
            'phr_ehi_uploads' => [
                'patient_column' => 'patient_id',
                'because' => 'Transient browser staging of an EHI ZIP; the package intake produced from it is backed up with its preserved members.',
            ],
        ];
    }

    /** @return array<string, string> table => storage-key column */
    public static function artifactBearingTables(): array
    {
        return [
            'phr_documents' => 'storage_path',
            'phr_dicom_files' => 'r2_key',
            'phr_ehi_blobs' => 'storage_path',
        ];
    }

    /**
     * Manifest artifact kind => the table whose row owns and describes its bytes.
     *
     * @return array<string, string>
     */
    public static function artifactKinds(): array
    {
        return [
            'document' => 'phr_documents',
            'dicom' => 'phr_dicom_files',
            'dicomdir' => 'phr_dicom_files',
            'ehi' => 'phr_ehi_blobs',
        ];
    }

    public static function artifactPath(string $kind, string $recordNativeId): string
    {
        $directory = match ($kind) {
            'document' => 'documents',
            'dicom', 'dicomdir' => 'dicom',
            'ehi' => 'ehi',
            default => throw new \InvalidArgumentException('Unknown native artifact kind.'),
        };

        return 'artifacts/'.$directory.'/'.$recordNativeId.'.blob';
    }

    /** @return array{target: string, kind: 'record', nullable: bool} */
    private static function record(string $target, bool $nullable): array
    {
        return ['target' => $target, 'kind' => 'record', 'nullable' => $nullable];
    }

    /** @return array{target: string, kind: 'actor', nullable: bool} */
    private static function actor(bool $nullable): array
    {
        return ['target' => 'users', 'kind' => 'actor', 'nullable' => $nullable];
    }
}

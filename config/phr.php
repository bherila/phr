<?php

use App\Services\PHR\Import\Ehi\Mapping\EhiDocumentMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiImmunizationMapping;

return [
    // The PHR navbar's "back" affordance exits PHR entirely and returns to the
    // parent site. This used to be derived from the OAuth provider base URL,
    // which was correct only while identity was served from the parent site
    // itself. Since identity moved to its own subdomain the two are different
    // hosts, and the back link must not follow the identity provider.
    'parent_site_url' => rtrim((string) env('PHR_PARENT_SITE_URL', 'https://bherila.net'), '/'),

    'exports_retention_days' => (int) env('PHR_EXPORTS_RETENTION_DAYS', 30),
    // Native archives are denser than interoperability summaries. Seven days is
    // enough to retrieve an owner-requested backup without retaining another full
    // copy of source artifacts for a month.
    'native_backup_retention_days' => max(1, (int) env('PHR_NATIVE_BACKUP_RETENTION_DAYS', 7)),
    // R6: Zip64 supports multi-gigabyte studies, while this source-byte ceiling
    // fails closed before one request can exhaust shared cPanel storage.
    'native_backup_max_uncompressed_bytes' => max(1, (int) env('PHR_NATIVE_BACKUP_MAX_UNCOMPRESSED_BYTES', 20 * 1024 * 1024 * 1024)),
    // Rows read per keyset page while a native backup (or restore preview) streams
    // a patient's tables. Memory is bounded by this, not by the patient's row count.
    'native_backup_chunk_size' => max(1, (int) env('PHR_NATIVE_BACKUP_CHUNK_SIZE', 500)),
    'native_restore_source_retention_days' => max(1, (int) env('PHR_NATIVE_RESTORE_SOURCE_RETENTION_DAYS', 7)),
    'native_restore_max_record_bytes' => max(1024, (int) env('PHR_NATIVE_RESTORE_MAX_RECORD_BYTES', 2 * 1024 * 1024)),
    'native_restore_chunk_bytes' => max(1024 * 1024, (int) env('PHR_NATIVE_RESTORE_CHUNK_BYTES', 8 * 1024 * 1024)),
    // Epic EHI Tables intake (#213). Every limit fails closed before bytes are
    // stored; a package over any of them is refused, never partially preserved.
    // Not env-configurable: patient deletion and storage pruning allowlist the
    // disks they sweep, and an EHI blob on any other disk could not be cleaned up.
    'ehi_disk' => 'phr_documents',
    'ehi_max_package_bytes' => max(1, (int) env('PHR_EHI_MAX_PACKAGE_BYTES', 10 * 1024 * 1024 * 1024)),
    'ehi_max_member_bytes' => max(1, (int) env('PHR_EHI_MAX_MEMBER_BYTES', 2 * 1024 * 1024 * 1024)),
    'ehi_max_members' => max(1, (int) env('PHR_EHI_MAX_MEMBERS', 50000)),
    'ehi_max_expansion_ratio' => max(1, (int) env('PHR_EHI_MAX_EXPANSION_RATIO', 200)),
    'ehi_max_path_depth' => max(1, (int) env('PHR_EHI_MAX_PATH_DEPTH', 16)),
    'ehi_max_path_bytes' => max(16, (int) env('PHR_EHI_MAX_PATH_BYTES', 512)),
    'ehi_max_row_bytes' => max(1024, (int) env('PHR_EHI_MAX_ROW_BYTES', 8 * 1024 * 1024)),
    'ehi_max_value_bytes' => max(1024, (int) env('PHR_EHI_MAX_VALUE_BYTES', 4 * 1024 * 1024)),
    'ehi_max_dictionary_bytes' => max(1024, (int) env('PHR_EHI_MAX_DICTIONARY_BYTES', 64 * 1024 * 1024)),
    'ehi_max_findings_per_member' => max(1, (int) env('PHR_EHI_MAX_FINDINGS_PER_MEMBER', 1000)),
    'ehi_lease_minutes' => max(1, (int) env('PHR_EHI_LEASE_MINUTES', 15)),
    // Versioned EHI mapping contracts (milestone 2), resolved through the
    // container by EhiMappingRegistry. Each needs a unique name; mappings that
    // share a source table must expect the same primary key for it.
    'ehi_mappings' => [
        EhiImmunizationMapping::class,
        EhiDocumentMapping::class,
    ],
    'documents_retention_days' => (int) env('PHR_DOCUMENTS_RETENTION_DAYS', 30),
    // A migrated reference keeps its verified legacy object until a separate,
    // explicit cleanup phase runs after this rollback window.
    'blob_migration_rollback_days' => max(1, (int) env('PHR_BLOB_MIGRATION_ROLLBACK_DAYS', 30)),

    // Metadata-only successful Data Hub audit events are retained for seven years;
    // durable failures are retained for one year. Read-only/pre-mutation no-ops are
    // not persisted, and pending cleanup work is never pruned.
    'data_hub_audit_success_retention_days' => max(1, (int) env('PHR_DATA_HUB_AUDIT_SUCCESS_RETENTION_DAYS', 2555)),
    'data_hub_audit_failure_retention_days' => max(1, (int) env('PHR_DATA_HUB_AUDIT_FAILURE_RETENTION_DAYS', 365)),
    'dicom_max_file_bytes' => (int) env('PHR_DICOM_MAX_FILE_BYTES', 1024 * 1024 * 1024),
    'dicom_viewer_direct_signed_urls' => filter_var(env('PHR_DICOM_VIEWER_DIRECT_SIGNED_URLS', false), FILTER_VALIDATE_BOOL),
    'dicom_viewer_url_ttl_minutes' => max(1, (int) env('PHR_DICOM_VIEWER_URL_TTL_MINUTES', 30)),
    'volume_cache_pipeline_version' => (int) env('PHR_VOLUME_CACHE_PIPELINE_VERSION', 1),
    'volume_cache_max_bytes' => (int) env('PHR_VOLUME_CACHE_MAX_BYTES', 67108864),
];

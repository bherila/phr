<?php

return [
    'exports_retention_days' => (int) env('PHR_EXPORTS_RETENTION_DAYS', 30),
    // Native archives are denser than interoperability summaries. Seven days is
    // enough to retrieve an owner-requested backup without retaining another full
    // copy of source artifacts for a month.
    'native_backup_retention_days' => max(1, (int) env('PHR_NATIVE_BACKUP_RETENTION_DAYS', 7)),
    // R6: Zip64 supports multi-gigabyte studies, while this source-byte ceiling
    // fails closed before one request can exhaust shared cPanel storage.
    'native_backup_max_uncompressed_bytes' => max(1, (int) env('PHR_NATIVE_BACKUP_MAX_UNCOMPRESSED_BYTES', 20 * 1024 * 1024 * 1024)),
    'documents_retention_days' => (int) env('PHR_DOCUMENTS_RETENTION_DAYS', 30),
    // A migrated reference keeps its verified legacy object until a separate,
    // explicit cleanup phase runs after this rollback window.
    'blob_migration_rollback_days' => max(1, (int) env('PHR_BLOB_MIGRATION_ROLLBACK_DAYS', 30)),
    'dicom_max_file_bytes' => (int) env('PHR_DICOM_MAX_FILE_BYTES', 1024 * 1024 * 1024),
    'dicom_viewer_direct_signed_urls' => filter_var(env('PHR_DICOM_VIEWER_DIRECT_SIGNED_URLS', false), FILTER_VALIDATE_BOOL),
    'dicom_viewer_url_ttl_minutes' => max(1, (int) env('PHR_DICOM_VIEWER_URL_TTL_MINUTES', 30)),
    'volume_cache_pipeline_version' => (int) env('PHR_VOLUME_CACHE_PIPELINE_VERSION', 1),
    'volume_cache_max_bytes' => (int) env('PHR_VOLUME_CACHE_MAX_BYTES', 67108864),
];

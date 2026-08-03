<?php

/*
 * `root` means two different things depending on the driver: a filesystem path
 * for "local", but an object-key prefix for "s3". The PHR DICOM disk can be
 * either, so its root has to follow the driver — defaulting it to a
 * storage_path() unconditionally prefixes every R2 key with an absolute host
 * path, no object is ever found, and every DICOM read 404s while the config
 * still looks correct.
 */
$phrDicomDiskDriver = env('PHR_DICOM_DISK_DRIVER', 's3');
$phrDicomDiskRoot = env(
    'PHR_DICOM_DISK_ROOT',
    $phrDicomDiskDriver === 'local' ? storage_path('app/private/phr-dicom') : ''
);

/*
 * The `s3` disk is GenAI import staging: PhrDocumentController::process and
 * PhrGenAiEnqueueCommand copy a document here, then ParseImportJob and
 * PhrDocumentImporter read it back.
 *
 * It defaults to "local", unlike phr_dicom above, because this app has never had the
 * AWS_* vars set anywhere — including production, where the disk resolved to a driver
 * with no region, bucket or key and threw InvalidArgumentException on first use. Nothing
 * had exercised it (`genai_import_jobs` was empty), so the breakage was latent rather than
 * visible. Defaulting to a driver that cannot work is not a safer default; it is a 503
 * waiting for the first person to click "process".
 *
 * Set S3_DISK_DRIVER=s3 with the AWS_* vars to put staging back on an object store.
 */
$s3DiskDriver = env('S3_DISK_DRIVER', 'local');
$s3DiskRoot = env(
    'S3_DISK_ROOT',
    $s3DiskDriver === 'local' ? storage_path('app/private/s3-blobs') : ''
);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // GenAI import staging — see the note at the top of this file.
        's3' => [
            'driver' => $s3DiskDriver,
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_S3_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'root' => $s3DiskRoot,
            'throw' => false,
            'report' => false,
        ],

        // PHR DICOM object storage. This is intentionally separate from the
        // default S3 disk because medical image payloads live in a dedicated
        // R2 bucket and are uploaded directly by the browser through signed
        // PUT URLs.
        'phr_dicom' => [
            'driver' => $phrDicomDiskDriver,
            'key' => env('PHR_DICOM_R2_ACCESS_KEY_ID'),
            'secret' => env('PHR_DICOM_R2_SECRET_ACCESS_KEY'),
            'region' => env('PHR_DICOM_R2_REGION', 'auto'),
            'bucket' => env('PHR_DICOM_R2_BUCKET'),
            'url' => env('PHR_DICOM_R2_URL'),
            'endpoint' => env('PHR_DICOM_R2_ENDPOINT'),
            'use_path_style_endpoint' => env('PHR_DICOM_R2_USE_PATH_STYLE_ENDPOINT', false),
            'root' => $phrDicomDiskRoot,
            'serve' => env('PHR_DICOM_DISK_SERVE', false),
            'throw' => false,
            'report' => false,
        ],

        'phr_documents' => [
            'driver' => 'local',
            'root' => env('PHR_DOCUMENTS_DISK_ROOT', storage_path('app/private/phr-documents')),
            'throw' => false,
            'report' => false,
        ],

        'phr_exports' => [
            'driver' => 'local',
            'root' => env('PHR_EXPORTS_DISK_ROOT', storage_path('app/private/phr-exports')),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];

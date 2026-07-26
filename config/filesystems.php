<?php

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

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_S3_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // PHR DICOM object storage. This is intentionally separate from the
        // default S3 disk because medical image payloads live in a dedicated
        // R2 bucket and are uploaded directly by the browser through signed
        // PUT URLs.
        'phr_dicom' => [
            'driver' => env('PHR_DICOM_DISK_DRIVER', 's3'),
            'key' => env('PHR_DICOM_R2_ACCESS_KEY_ID'),
            'secret' => env('PHR_DICOM_R2_SECRET_ACCESS_KEY'),
            'region' => env('PHR_DICOM_R2_REGION', 'auto'),
            'bucket' => env('PHR_DICOM_R2_BUCKET'),
            'url' => env('PHR_DICOM_R2_URL'),
            'endpoint' => env('PHR_DICOM_R2_ENDPOINT'),
            'use_path_style_endpoint' => env('PHR_DICOM_R2_USE_PATH_STYLE_ENDPOINT', false),
            'root' => env('PHR_DICOM_DISK_ROOT', storage_path('app/private/phr-dicom')),
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

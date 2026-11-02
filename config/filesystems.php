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
$s3DiskDriver = env('S3_DISK_DRIVER', 'local') ?: 'local';

/*
 * `?:` rather than an env() default, because a bare `S3_DISK_ROOT=` in a .env is *set* to
 * the empty string and so wins over the default. On the local driver that roots Flysystem
 * at the process working directory instead of storage/, scattering staged files wherever
 * the app happened to be invoked from. An explicitly empty root is never the intent.
 */
$s3DiskRoot = env('S3_DISK_ROOT') ?: (
    $s3DiskDriver === 'local' ? storage_path('app/private/s3-blobs') : ''
);

/*
 * PHR_STORAGE is the one choice of where this installation keeps every PHR file:
 *
 *   unset   Nothing has been chosen. Each disk keeps exactly the driver and root it has
 *           always had, from its own setting or its code default (phr_dicom's default is
 *           s3, the others' is local). An existing installation is here until its operator
 *           chooses otherwise, so this branch must never change what a disk resolves to.
 *   local   Every PHR disk is on the local driver, under storage/app/private.
 *   s3      Every PHR disk is on one S3-compatible bucket (PHR_STORAGE_S3_*), and the
 *           per-disk PHR_DICOM_R2_*, PHR_UPLOADS_* and AWS_* settings are not read.
 *
 * The object prefix of each disk is its local directory's name. That is what makes a move
 * a copy: phr-documents/patients/1/x on either side, with no row rewritten. It also keeps
 * the disks apart in a shared bucket. They all use the same `patients/` key prefixes, and
 * the sweeps list those prefixes, so with no root phr:dicom:gc would see every document as
 * an orphan DICOM object and delete it.
 *
 * Config may run with no app/ directory (the deploy's candidate-database check does), so
 * nothing here can reference an application class. See docs/storage.md.
 */
$phrStorage = strtolower(trim((string) env('PHR_STORAGE', '')));
if (! in_array($phrStorage, ['', 'local', 's3'], true)) {
    throw new InvalidArgumentException('PHR_STORAGE must be local or s3, or left unset.');
}
if ($phrStorage === 's3' && (string) env('PHR_STORAGE_S3_BUCKET', '') === '') {
    throw new InvalidArgumentException('PHR_STORAGE=s3 needs PHR_STORAGE_S3_BUCKET.');
}

/**
 * @param  array<string, mixed>  $unchosen  the disk as it resolves with PHR_STORAGE unset
 * @param  string  $directory  its directory under storage/app/private, and its object prefix
 * @param  string|null  $rootSetting  the setting that can move its local directory
 * @return array<string, mixed>
 */
$phrDisk = static function (array $unchosen, string $directory, ?string $rootSetting = null) use ($phrStorage): array {
    if ($phrStorage === '') {
        return $unchosen;
    }

    $shared = array_intersect_key($unchosen, array_flip(['serve', 'throw', 'report']));
    if ($phrStorage === 's3') {
        return [
            ...$shared,
            'driver' => 's3',
            'key' => env('PHR_STORAGE_S3_ACCESS_KEY_ID'),
            'secret' => env('PHR_STORAGE_S3_SECRET_ACCESS_KEY'),
            'region' => env('PHR_STORAGE_S3_REGION', 'auto'),
            'bucket' => env('PHR_STORAGE_S3_BUCKET'),
            'endpoint' => env('PHR_STORAGE_S3_ENDPOINT'),
            'use_path_style_endpoint' => env('PHR_STORAGE_S3_USE_PATH_STYLE_ENDPOINT', false),
            'root' => $directory,
            // Reads stream rather than buffering a whole object first. An SDK checksum can
            // land in signed URLs, and stores other than AWS handle them unevenly.
            'stream_reads' => true,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            // A move reads the source's ACL first, which not every store implements.
            'retain_visibility' => false,
        ];
    }

    $root = $rootSetting === null ? '' : (string) env($rootSetting, '');
    if ($root !== '' && ! str_starts_with($root, '/')) {
        // Left from an object store, where a root is a key prefix: it would resolve against
        // whatever directory the process started in.
        throw new InvalidArgumentException("PHR_STORAGE=local needs {$rootSetting} to be an absolute path.");
    }

    return [...$shared, 'driver' => 'local', 'root' => $root !== '' ? $root : storage_path('app/private/'.$directory)];
};

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
        's3' => $phrDisk([
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
        ], 's3-blobs', 'S3_DISK_ROOT'),

        // PHR DICOM object storage. This is intentionally separate from the
        // default S3 disk because medical image payloads live in a dedicated
        // R2 bucket and are uploaded directly by the browser through signed
        // PUT URLs.
        'phr_dicom' => $phrDisk([
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
        ], 'phr-dicom', 'PHR_DICOM_DISK_ROOT'),

        // Optional direct upload staging. Durable documents keep their own disk.
        'phr_uploads' => $phrDisk([
            'driver' => env('PHR_UPLOADS_DISK_DRIVER', 'local'),
            'root' => env('PHR_UPLOADS_DISK_DRIVER', 'local') === 'local' ? storage_path('app/private/phr-uploads') : '',
            'key' => env('PHR_UPLOADS_ACCESS_KEY_ID'),
            'secret' => env('PHR_UPLOADS_SECRET_ACCESS_KEY'),
            'region' => env('PHR_UPLOADS_REGION', 'auto'),
            'bucket' => env('PHR_UPLOADS_BUCKET'),
            'endpoint' => env('PHR_UPLOADS_ENDPOINT'),
            'use_path_style_endpoint' => env('PHR_UPLOADS_USE_PATH_STYLE_ENDPOINT', false),
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'throw' => true,
        ], 'phr-uploads'),

        'phr_documents' => $phrDisk([
            'driver' => 'local',
            'root' => env('PHR_DOCUMENTS_DISK_ROOT', storage_path('app/private/phr-documents')),
            'throw' => false,
            'report' => false,
        ], 'phr-documents', 'PHR_DOCUMENTS_DISK_ROOT'),

        'phr_exports' => $phrDisk([
            'driver' => 'local',
            'root' => env('PHR_EXPORTS_DISK_ROOT', storage_path('app/private/phr-exports')),
            'throw' => false,
            'report' => false,
        ], 'phr-exports', 'PHR_EXPORTS_DISK_ROOT'),

        // Chunks of an upload that passes through PHP, while they arrive: an object cannot
        // be appended to, so on an object store they wait here and are published as one
        // object when complete. Always local, whatever PHR_STORAGE says, and always
        // transient, so it is not part of a move. See StagedUpload.
        'phr_upload_spool' => [
            'driver' => 'local',
            'root' => storage_path('app/private/phr-upload-spool'),
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

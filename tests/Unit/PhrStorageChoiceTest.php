<?php

namespace Tests\Unit;

use App\Support\Storage\PhrStorageMap;
use App\Support\Storage\StagedUpload;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FilesystemConfig;
use Tests\TestCase;

/**
 * PHR_STORAGE: one choice for every PHR disk, and no change at all until someone makes it.
 */
class PhrStorageChoiceTest extends TestCase
{
    /** Every disk the choice governs, and its local directory. */
    private const array DISKS = [
        's3' => 's3-blobs',
        'phr_dicom' => 'phr-dicom',
        'phr_uploads' => 'phr-uploads',
        'phr_documents' => 'phr-documents',
        'phr_exports' => 'phr-exports',
    ];

    private const array S3 = [
        'PHR_STORAGE' => 's3',
        'PHR_STORAGE_S3_ACCESS_KEY_ID' => 'synthetic-key',
        'PHR_STORAGE_S3_SECRET_ACCESS_KEY' => 'synthetic-secret',
        'PHR_STORAGE_S3_REGION' => 'synthetic-region',
        'PHR_STORAGE_S3_BUCKET' => 'synthetic-bucket',
        'PHR_STORAGE_S3_ENDPOINT' => 'https://objects.example.test',
        'PHR_STORAGE_S3_USE_PATH_STYLE_ENDPOINT' => 'true',
    ];

    /** @param array<string, string> $settings */
    private function disks(array $settings): array
    {
        return FilesystemConfig::load($settings)['disks'];
    }

    /**
     * What each disk resolved to before PHR_STORAGE existed, written out rather than derived
     * so a change to the unset path cannot move this expectation along with it.
     *
     * @param  array<string, string>  $env
     * @return array<string, array<string, mixed>>
     */
    private function disksBeforeTheChoiceExisted(array $env): array
    {
        $get = static fn (string $key, mixed $default = null): mixed => match ($env[$key] ?? null) {
            null => $default,
            'true' => true,
            'false' => false,
            '' => '',
            default => $env[$key],
        };
        $dicomDriver = $get('PHR_DICOM_DISK_DRIVER', 's3');
        $s3Driver = $get('S3_DISK_DRIVER', 'local') ?: 'local';
        $uploadsDriver = $get('PHR_UPLOADS_DISK_DRIVER', 'local');

        return [
            's3' => [
                'driver' => $s3Driver, 'key' => $get('AWS_ACCESS_KEY_ID'), 'secret' => $get('AWS_SECRET_ACCESS_KEY'),
                'region' => $get('AWS_DEFAULT_REGION'), 'bucket' => $get('AWS_BUCKET'), 'url' => $get('AWS_URL'),
                'endpoint' => $get('AWS_S3_ENDPOINT'), 'use_path_style_endpoint' => $get('AWS_USE_PATH_STYLE_ENDPOINT', false),
                'root' => $get('S3_DISK_ROOT') ?: ($s3Driver === 'local' ? storage_path('app/private/s3-blobs') : ''),
                'throw' => false, 'report' => false,
            ],
            'phr_dicom' => [
                'driver' => $dicomDriver, 'key' => $get('PHR_DICOM_R2_ACCESS_KEY_ID'), 'secret' => $get('PHR_DICOM_R2_SECRET_ACCESS_KEY'),
                'region' => $get('PHR_DICOM_R2_REGION', 'auto'), 'bucket' => $get('PHR_DICOM_R2_BUCKET'), 'url' => $get('PHR_DICOM_R2_URL'),
                'endpoint' => $get('PHR_DICOM_R2_ENDPOINT'), 'use_path_style_endpoint' => $get('PHR_DICOM_R2_USE_PATH_STYLE_ENDPOINT', false),
                'root' => $get('PHR_DICOM_DISK_ROOT', $dicomDriver === 'local' ? storage_path('app/private/phr-dicom') : ''),
                'serve' => $get('PHR_DICOM_DISK_SERVE', false), 'throw' => false, 'report' => false,
            ],
            'phr_uploads' => [
                'driver' => $uploadsDriver, 'root' => $uploadsDriver === 'local' ? storage_path('app/private/phr-uploads') : '',
                'key' => $get('PHR_UPLOADS_ACCESS_KEY_ID'), 'secret' => $get('PHR_UPLOADS_SECRET_ACCESS_KEY'),
                'region' => $get('PHR_UPLOADS_REGION', 'auto'), 'bucket' => $get('PHR_UPLOADS_BUCKET'), 'endpoint' => $get('PHR_UPLOADS_ENDPOINT'),
                'use_path_style_endpoint' => $get('PHR_UPLOADS_USE_PATH_STYLE_ENDPOINT', false),
                'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required', 'throw' => true,
            ],
            'phr_documents' => [
                'driver' => 'local', 'root' => $get('PHR_DOCUMENTS_DISK_ROOT', storage_path('app/private/phr-documents')), 'throw' => false, 'report' => false,
            ],
            'phr_exports' => [
                'driver' => 'local', 'root' => $get('PHR_EXPORTS_DISK_ROOT', storage_path('app/private/phr-exports')), 'throw' => false, 'report' => false,
            ],
        ];
    }

    /** @return array<string, array{array<string, string>}> */
    public static function existingInstallations(): array
    {
        return [
            'nothing set, where DICOM defaults to s3 and the rest to local' => [[]],
            'an empty choice is no choice' => [['PHR_STORAGE' => '']],
            'DICOM moved to local disk' => [['PHR_DICOM_DISK_DRIVER' => 'local']],
            'DICOM on a bucket' => [['PHR_DICOM_DISK_DRIVER' => 's3', 'PHR_DICOM_R2_BUCKET' => 'synthetic', 'PHR_DICOM_R2_ENDPOINT' => 'https://r2.example.test', 'PHR_DICOM_R2_USE_PATH_STYLE_ENDPOINT' => 'true']],
            'DICOM at a chosen root' => [['PHR_DICOM_DISK_DRIVER' => 'local', 'PHR_DICOM_DISK_ROOT' => '/srv/synthetic/dicom', 'PHR_DICOM_DISK_SERVE' => 'true']],
            'GenAI staging on a bucket' => [['S3_DISK_DRIVER' => 's3', 'AWS_BUCKET' => 'synthetic', 'AWS_DEFAULT_REGION' => 'synthetic-region']],
            'GenAI staging at a chosen root' => [['S3_DISK_ROOT' => '/srv/synthetic/staging']],
            'direct uploads on a bucket' => [['PHR_UPLOADS_DISK_DRIVER' => 's3', 'PHR_UPLOADS_BUCKET' => 'synthetic', 'PHR_UPLOADS_ENDPOINT' => 'https://uploads.example.test']],
            'documents and exports at chosen roots' => [['PHR_DOCUMENTS_DISK_ROOT' => '/srv/synthetic/documents', 'PHR_EXPORTS_DISK_ROOT' => '/srv/synthetic/exports']],
        ];
    }

    /** @param array<string, string> $env */
    #[DataProvider('existingInstallations')]
    public function test_an_installation_that_has_not_chosen_keeps_exactly_what_it_has(array $env): void
    {
        $disks = $this->disks($env);

        foreach ($this->disksBeforeTheChoiceExisted($env) as $name => $before) {
            $this->assertSame($before, $disks[$name], "{$name} changed");
        }
    }

    public function test_the_default_keeps_dicom_on_s3_and_every_other_disk_local(): void
    {
        $disks = $this->disks([]);

        $this->assertSame('s3', $disks['phr_dicom']['driver']);
        foreach (['s3', 'phr_uploads', 'phr_documents', 'phr_exports'] as $name) {
            $this->assertSame('local', $disks[$name]['driver'], $name);
        }
    }

    public function test_local_puts_every_disk_on_its_local_directory_whatever_the_old_settings_say(): void
    {
        $disks = $this->disks(['PHR_STORAGE' => 'local', 'PHR_DICOM_DISK_DRIVER' => 's3', 'PHR_UPLOADS_DISK_DRIVER' => 's3', 'S3_DISK_DRIVER' => 's3']);

        foreach (self::DISKS as $name => $directory) {
            $this->assertSame('local', $disks[$name]['driver'], $name);
            $this->assertSame(storage_path('app/private/'.$directory), $disks[$name]['root'], $name);
        }
    }

    public function test_local_honours_a_chosen_directory_but_not_a_leftover_key_prefix(): void
    {
        $disks = $this->disks(['PHR_STORAGE' => 'local', 'PHR_DOCUMENTS_DISK_ROOT' => '/srv/synthetic/documents']);
        $this->assertSame('/srv/synthetic/documents', $disks['phr_documents']['root']);

        $this->expectException(InvalidArgumentException::class);
        $this->disks(['PHR_STORAGE' => 'local', 'PHR_DICOM_DISK_ROOT' => 'leftover/prefix']);
    }

    public function test_s3_puts_every_disk_on_one_bucket_under_its_own_prefix_and_reads_no_old_setting(): void
    {
        $disks = $this->disks([
            ...self::S3,
            'PHR_DICOM_DISK_DRIVER' => 'local', 'PHR_DICOM_R2_BUCKET' => 'old-dicom', 'PHR_DICOM_R2_URL' => 'https://old.example.test', 'PHR_DICOM_DISK_ROOT' => 'old',
            'PHR_UPLOADS_BUCKET' => 'old-uploads', 'AWS_BUCKET' => 'old-staging', 'AWS_URL' => 'https://old.example.test', 'S3_DISK_ROOT' => 'old',
            'PHR_DOCUMENTS_DISK_ROOT' => '/srv/old', 'PHR_EXPORTS_DISK_ROOT' => '/srv/old',
        ]);

        foreach (self::DISKS as $name => $directory) {
            $this->assertSame('s3', $disks[$name]['driver'], $name);
            $this->assertSame('synthetic-bucket', $disks[$name]['bucket'], $name);
            $this->assertSame('synthetic-key', $disks[$name]['key'], $name);
            $this->assertSame('synthetic-secret', $disks[$name]['secret'], $name);
            $this->assertSame('synthetic-region', $disks[$name]['region'], $name);
            $this->assertSame('https://objects.example.test', $disks[$name]['endpoint'], $name);
            $this->assertTrue($disks[$name]['use_path_style_endpoint'], $name);
            $this->assertSame($directory, $disks[$name]['root'], $name);
            $this->assertArrayNotHasKey('url', $disks[$name], $name);
            $this->assertTrue($disks[$name]['stream_reads'], $name);
            $this->assertFalse($disks[$name]['retain_visibility'], $name);
            $this->assertSame('when_required', $disks[$name]['request_checksum_calculation'], $name);
            $this->assertSame('when_required', $disks[$name]['response_checksum_validation'], $name);
        }
        $this->assertTrue($disks['phr_uploads']['throw'], 'phr_uploads keeps its own failure behaviour');
        $this->assertFalse($disks['phr_documents']['throw']);
    }

    public function test_an_object_key_is_the_local_path_under_the_directory_of_the_same_name(): void
    {
        $local = $this->disks(['PHR_STORAGE' => 'local']);
        $s3 = $this->disks(self::S3);

        $prefixes = [];
        foreach (array_keys(self::DISKS) as $name) {
            $prefixes[] = $s3[$name]['root'];
            $this->assertNotSame('', $s3[$name]['root'], "{$name} would share the bucket's top level");
            $this->assertSame(basename($local[$name]['root']), $s3[$name]['root'], $name);
        }
        $this->assertSame($prefixes, array_unique($prefixes), 'Two disks sharing a prefix would see each other in their sweeps.');
    }

    public function test_the_choice_covers_every_disk_a_sweep_visits(): void
    {
        $swept = array_diff(array_keys(PhrStorageMap::disks()), [StagedUpload::SPOOL_DISK]);

        $this->assertEqualsCanonicalizing(array_keys(self::DISKS), array_values($swept));
    }

    public function test_the_spool_stays_local_whatever_is_chosen(): void
    {
        foreach ([[], ['PHR_STORAGE' => 'local'], self::S3] as $settings) {
            $spool = $this->disks($settings)[StagedUpload::SPOOL_DISK];
            $this->assertSame('local', $spool['driver']);
            $this->assertSame(storage_path('app/private/phr-upload-spool'), $spool['root']);
        }
    }

    /** @return array<string, array{array<string, string>}> */
    public static function refusedChoices(): array
    {
        return [
            'an unknown store' => [['PHR_STORAGE' => 'r2']],
            's3 with no bucket' => [['PHR_STORAGE' => 's3']],
        ];
    }

    /** @param array<string, string> $settings */
    #[DataProvider('refusedChoices')]
    public function test_a_choice_that_cannot_work_refuses_to_load(array $settings): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->disks($settings);
    }

    public function test_the_choice_reads_any_case_and_surrounding_space(): void
    {
        $this->assertSame('local', $this->disks(['PHR_STORAGE' => ' LOCAL '])['phr_dicom']['driver']);
    }
}

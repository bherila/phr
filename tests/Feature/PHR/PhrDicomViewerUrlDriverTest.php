<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDicomInstance;
use App\Services\PHR\DICOM\DicomUploadProcessor;
use App\Services\PHR\DICOM\VolumeCacheService;
use Tests\TestCase;

/**
 * Guards the DICOM viewer against emitting presigned object-store URLs on a local disk.
 *
 * Three separate code paths hand a URL to the OHIF viewer — the volume manifest
 * (DicomStudyController::volumeManifest), the `dicomweb:` study metadata, and the
 * volume-cache artifact (VolumeCacheService::downloadUrl) — but all three funnel through
 * DicomUploadProcessor::shouldUseDirectSignedViewerUrls(). The local driver has no
 * temporaryUrl() and throws, so if that single flag were honoured on a local disk the
 * viewer would 500 on every image rather than fail once at boot.
 *
 * These tests pin the flag to the disk driver so PHR_DICOM_DISK_DRIVER=local cannot be
 * combined with PHR_DICOM_VIEWER_DIRECT_SIGNED_URLS=true to produce that state.
 */
class PhrDicomViewerUrlDriverTest extends TestCase
{
    private function configure(string $driver, bool $flag): void
    {
        config([
            'filesystems.disks.'.DicomUploadProcessor::DISK.'.driver' => $driver,
            'phr.dicom_viewer_direct_signed_urls' => $flag,
        ]);
    }

    public function test_local_disk_disables_direct_signed_urls_even_when_the_flag_is_on(): void
    {
        $this->configure('local', true);

        $processor = app(DicomUploadProcessor::class);

        $this->assertFalse($processor->usesObjectStore());
        $this->assertFalse(
            $processor->shouldUseDirectSignedViewerUrls(),
            'A local disk cannot presign; the flag must not be honoured.',
        );
        $this->assertFalse(
            app(VolumeCacheService::class)->usesDirectSignedUrls(),
            'The volume-cache path must follow the same gate as the instance path.',
        );
    }

    public function test_object_store_still_honours_the_flag(): void
    {
        $this->configure('s3', true);

        $processor = app(DicomUploadProcessor::class);

        $this->assertTrue($processor->usesObjectStore());
        $this->assertTrue($processor->shouldUseDirectSignedViewerUrls());
        $this->assertTrue(app(VolumeCacheService::class)->usesDirectSignedUrls());
    }

    public function test_flag_off_disables_direct_urls_on_either_driver(): void
    {
        foreach (['local', 's3'] as $driver) {
            $this->configure($driver, false);

            $this->assertFalse(
                app(DicomUploadProcessor::class)->shouldUseDirectSignedViewerUrls(),
                "Flag off must win on the {$driver} driver.",
            );
        }
    }

    public function test_instance_urls_are_app_routes_on_a_local_disk(): void
    {
        $this->configure('local', true);

        $instance = new PhrDicomInstance;
        $instance->id = 4242;

        $url = app(DicomUploadProcessor::class)->instanceDownloadUrl($instance, 7);

        // What the OHIF manifest and the `dicomweb:` metadata both embed.
        $this->assertStringContainsString('/api/phr/patients/7/dicom/instances/4242/file', $url);
        $this->assertStringNotContainsString('r2.cloudflarestorage.com', $url);
        $this->assertStringNotContainsString('X-Amz-Signature', $url);
    }
}

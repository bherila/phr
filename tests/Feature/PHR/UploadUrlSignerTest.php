<?php

namespace Tests\Feature\PHR;

use App\Services\PHR\Documents\UploadUrlSigner;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadUrlSignerTest extends TestCase
{
    public function test_s3_signing_binds_length_and_type_without_contacting_storage(): void
    {
        config(['filesystems.disks.phr_uploads' => [
            'driver' => 's3', 'key' => 'synthetic-key', 'secret' => 'synthetic-secret',
            'region' => 'auto', 'bucket' => 'synthetic-uploads', 'endpoint' => 'https://objects.example.test',
            'use_path_style_endpoint' => true, 'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required',
        ]]);
        Storage::forgetDisk('phr_uploads');
        $signed = app(UploadUrlSigner::class)->sign('phr_uploads', 'patients/12/document-uploads/synthetic', 'text/plain', 123, now()->addMinutes(15));
        parse_str(parse_url($signed['url'], PHP_URL_QUERY), $query);
        $headers = explode(';', $query['X-Amz-SignedHeaders']);
        $this->assertContains('content-length', $headers);
        $this->assertContains('content-type', $headers);
        $this->assertSame('text/plain', $signed['headers']['Content-Type']);
        $this->assertArrayNotHasKey('Content-Length', $signed['headers']);
        $this->assertStringStartsWith('https://objects.example.test/', $signed['url']);
    }
}

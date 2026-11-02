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

    public function test_s3_signing_addresses_the_object_under_the_disk_root(): void
    {
        config(['filesystems.disks.phr_uploads' => [
            'driver' => 's3', 'key' => 'synthetic-key', 'secret' => 'synthetic-secret',
            'region' => 'auto', 'bucket' => 'synthetic-uploads', 'endpoint' => 'https://objects.example.test',
            'use_path_style_endpoint' => true, 'root' => 'phr-uploads',
        ]]);
        Storage::forgetDisk('phr_uploads');

        $signed = app(UploadUrlSigner::class)->sign('phr_uploads', 'patients/12/document-uploads/synthetic', 'text/plain', 123, now()->addMinutes(15));

        $this->assertSame('/synthetic-uploads/phr-uploads/patients/12/document-uploads/synthetic', parse_url($signed['url'], PHP_URL_PATH));
    }

    public function test_a_part_is_signed_with_its_length_under_the_disk_root(): void
    {
        config(['filesystems.disks.phr_documents' => [
            'driver' => 's3', 'key' => 'synthetic-key', 'secret' => 'synthetic-secret',
            'region' => 'auto', 'bucket' => 'synthetic-bucket', 'endpoint' => 'https://objects.example.test',
            'use_path_style_endpoint' => true, 'root' => 'phr-documents',
            'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required',
        ]]);
        Storage::forgetDisk('phr_documents');

        $signed = app(UploadUrlSigner::class)->signPart('phr_documents', 'patients/12/ehi-uploads/synthetic/package.zip', 'synthetic-upload', 7, 5_242_880, now()->addMinutes(15));

        $this->assertSame('/synthetic-bucket/phr-documents/patients/12/ehi-uploads/synthetic/package.zip', parse_url($signed['url'], PHP_URL_PATH));
        parse_str((string) parse_url($signed['url'], PHP_URL_QUERY), $query);
        $this->assertSame('7', $query['partNumber']);
        $this->assertSame('synthetic-upload', $query['uploadId']);
        $this->assertContains('content-length', explode(';', $query['X-Amz-SignedHeaders']));
        $this->assertArrayNotHasKey('Content-Length', $signed['headers'], 'a browser sets that itself, and refuses to be told');
    }
}

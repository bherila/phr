<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDicomFile;
use App\Models\PhrDicomInstance;
use App\Models\PhrDicomSeries;
use App\Models\PhrDicomStudy;
use App\Models\PhrDicomUpload;
use App\Models\User;
use App\Services\PHR\DICOM\DicomUploadProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PhrDicomVolumeCacheTest extends TestCase
{
    public function test_unrelated_user_cannot_store_or_read_cache_but_viewer_can_store_it(): void
    {
        Storage::fake(DicomUploadProcessor::DISK);

        $owner = $this->createUser();
        $viewer = $this->createUser();
        $unrelated = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $series = $this->createSeries($owner, $patientId);

        $this->postCache($unrelated, $patientId, $series->id, $this->gzipBytes())
            ->assertNotFound();
        $this->actingAs($unrelated)
            ->get($this->cacheUrl($patientId, $series->id))
            ->assertNotFound();

        $this->grantPatientAccess($owner, $patientId, $viewer, 'viewer');

        $this->postCache($viewer, $patientId, $series->id, $this->gzipBytes())
            ->assertCreated()
            ->assertExactJson([
                'stored' => true,
                'byte_size' => strlen($this->gzipBytes()),
                'pipeline_version' => 1,
            ]);

        $artifact = PhrDicomFile::query()
            ->where('file_kind', PhrDicomFile::KIND_DERIVED_VOLUME)
            ->sole();
        $this->assertSame($patientId, $artifact->patient_id);
        $this->assertSame($series->instances()->firstOrFail()->upload_id, $artifact->upload_id);
        $this->assertSame("derived/volume-cache/{$series->series_instance_uid}/v1.bin.gz", $artifact->r2_key);
        $this->assertSame($artifact->r2_key, $artifact->original_relative_path);
        $this->assertSame(hash('sha256', $artifact->r2_key), $artifact->original_path_hash);
        $this->assertSame('volume-cache-v1.bin.gz', $artifact->original_filename);
        $this->assertSame('application/gzip', $artifact->mime_type);
        $this->assertSame([
            'kind' => 'volume_cache',
            'series_id' => $series->id,
            'pipeline_version' => 1,
        ], $artifact->metadata_json);
        Storage::disk(DicomUploadProcessor::DISK)->assertExists($artifact->r2_key);
    }

    public function test_manifest_cache_changes_from_unavailable_to_available_after_upload(): void
    {
        Storage::fake(DicomUploadProcessor::DISK);

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $series = $this->createSeries($owner, $patientId);

        $this->actingAs($owner)
            ->getJson($this->manifestUrl($patientId, $series->id))
            ->assertOk()
            ->assertJsonPath('cache.available', false)
            ->assertJsonPath('cache.url', null)
            ->assertJsonPath('cache.pipeline_version', 1);

        $this->postCache($owner, $patientId, $series->id, $this->gzipBytes())->assertCreated();

        $this->actingAs($owner)
            ->getJson($this->manifestUrl($patientId, $series->id))
            ->assertOk()
            ->assertJsonPath('cache.available', true)
            ->assertJsonPath('cache.url', url($this->cacheUrl($patientId, $series->id)))
            ->assertJsonPath('cache.pipeline_version', 1);
    }

    public function test_get_streams_stored_gzip_bytes_and_returns_not_found_when_absent(): void
    {
        Storage::fake(DicomUploadProcessor::DISK);

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $series = $this->createSeries($owner, $patientId);

        $this->actingAs($owner)
            ->get($this->cacheUrl($patientId, $series->id))
            ->assertNotFound();

        $bytes = $this->gzipBytes('cached-volume');
        $this->postCache($owner, $patientId, $series->id, $bytes)->assertCreated();

        $response = $this->actingAs($owner)
            ->get($this->cacheUrl($patientId, $series->id))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/gzip')
            ->assertHeader('Content-Length', (string) strlen($bytes));
        $this->assertSame($bytes, $response->streamedContent());
    }

    public function test_store_rejects_wrong_pipeline_version_missing_gzip_magic_and_ineligible_series(): void
    {
        Storage::fake(DicomUploadProcessor::DISK);

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $series = $this->createSeries($owner, $patientId);
        $ineligibleSeries = $this->createSeries($owner, $patientId, instanceCount: 19);

        $this->postCache($owner, $patientId, $series->id, $this->gzipBytes(), 2)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pipeline_version');
        $this->postCache($owner, $patientId, $series->id, 'not-gzip')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
        $this->postCache($owner, $patientId, $ineligibleSeries->id, $this->gzipBytes())
            ->assertUnprocessable()
            ->assertExactJson(['error' => 'series_not_eligible']);

        $this->assertSame(0, PhrDicomFile::query()->where('file_kind', PhrDicomFile::KIND_DERIVED_VOLUME)->count());
    }

    public function test_store_rejects_artifact_over_configured_size_limit(): void
    {
        Storage::fake(DicomUploadProcessor::DISK);
        config(['phr.volume_cache_max_bytes' => 4]);

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $series = $this->createSeries($owner, $patientId);

        $this->postCache($owner, $patientId, $series->id, "\x1f\x8b123")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_reupload_for_same_series_and_version_is_idempotent(): void
    {
        Storage::fake(DicomUploadProcessor::DISK);

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $series = $this->createSeries($owner, $patientId);

        $this->postCache($owner, $patientId, $series->id, $this->gzipBytes('first'))->assertCreated();
        $this->postCache($owner, $patientId, $series->id, $this->gzipBytes('replacement'))->assertCreated();

        $artifacts = PhrDicomFile::query()
            ->where('file_kind', PhrDicomFile::KIND_DERIVED_VOLUME)
            ->get();
        $this->assertCount(1, $artifacts);
        $this->assertSame(hash('sha256', $this->gzipBytes('replacement')), $artifacts->sole()->sha256);
        $this->assertSame($this->gzipBytes('replacement'), Storage::disk(DicomUploadProcessor::DISK)->get($artifacts->sole()->r2_key));
    }

    public function test_signed_url_configuration_affects_manifest_url_and_get_redirect(): void
    {
        Storage::fake(DicomUploadProcessor::DISK);

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $series = $this->createSeries($owner, $patientId);
        $this->postCache($owner, $patientId, $series->id, $this->gzipBytes())->assertCreated();

        config([
            'phr.dicom_viewer_direct_signed_urls' => true,
            'phr.dicom_viewer_url_ttl_minutes' => 12,
        ]);

        $manifestResponse = $this->actingAs($owner)
            ->getJson($this->manifestUrl($patientId, $series->id))
            ->assertOk()
            ->assertJsonPath('cache.available', true);
        $signedUrl = (string) $manifestResponse->json('cache.url');
        $this->assertStringStartsWith('http://localhost/derived/volume-cache/', $signedUrl);
        $this->assertStringContainsString('expiration=', $signedUrl);
        $this->assertStringNotContainsString('/api/phr/patients/', $signedUrl);

        $redirectResponse = $this->actingAs($owner)
            ->get($this->cacheUrl($patientId, $series->id))
            ->assertRedirect()
            ->assertHeader('Cache-Control', 'no-store, private');
        $location = (string) $redirectResponse->headers->get('Location');
        $this->assertStringStartsWith('http://localhost/derived/volume-cache/', $location);
        $this->assertStringContainsString('expiration=', $location);
    }

    public function test_gc_deletes_only_stale_pipeline_version_artifact(): void
    {
        Storage::fake(DicomUploadProcessor::DISK);
        config(['phr.volume_cache_pipeline_version' => 1]);

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $series = $this->createSeries($owner, $patientId);
        $this->postCache($owner, $patientId, $series->id, $this->gzipBytes('current'))->assertCreated();
        $currentArtifact = PhrDicomFile::query()
            ->where('file_kind', PhrDicomFile::KIND_DERIVED_VOLUME)
            ->sole();

        $staleKey = "derived/volume-cache/{$series->series_instance_uid}/v0.bin.gz";
        Storage::disk(DicomUploadProcessor::DISK)->put($staleKey, $this->gzipBytes('stale'));
        $staleArtifact = PhrDicomFile::create([
            'patient_id' => $patientId,
            'upload_id' => $series->instances()->firstOrFail()->upload_id,
            'file_kind' => PhrDicomFile::KIND_DERIVED_VOLUME,
            'r2_key' => $staleKey,
            'original_relative_path' => $staleKey,
            'original_path_hash' => hash('sha256', $staleKey),
            'original_filename' => 'volume-cache-v0.bin.gz',
            'mime_type' => 'application/gzip',
            'file_size_bytes' => strlen($this->gzipBytes('stale')),
            'sha256' => hash('sha256', $this->gzipBytes('stale')),
            'metadata_json' => [
                'kind' => 'volume_cache',
                'series_id' => $series->id,
                'pipeline_version' => 0,
            ],
        ]);

        $this->artisan('phr:dicom:gc')->assertExitCode(0);

        $this->assertDatabaseMissing('phr_dicom_files', ['id' => $staleArtifact->id]);
        $this->assertDatabaseHas('phr_dicom_files', ['id' => $currentArtifact->id]);
        Storage::disk(DicomUploadProcessor::DISK)->assertMissing($staleKey);
        Storage::disk(DicomUploadProcessor::DISK)->assertExists($currentArtifact->r2_key);
    }

    private function postCache(User $actor, int $patientId, int $seriesId, string $bytes, int $pipelineVersion = 1): TestResponse
    {
        return $this->actingAs($actor)
            ->withHeader('Accept', 'application/json')
            ->post($this->cacheUrl($patientId, $seriesId), [
                'file' => UploadedFile::fake()->createWithContent('volume-cache.bin.gz', $bytes),
                'pipeline_version' => $pipelineVersion,
            ]);
    }

    private function createPatientFor(User $owner): int
    {
        $response = $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Primary',
            'relationship' => 'self',
        ])->assertCreated();

        return (int) $response->json('patient.id');
    }

    private function grantPatientAccess(User $owner, int $patientId, User $grantee, string $level): void
    {
        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/access", [
            'email' => $grantee->email,
            'access_level' => $level,
        ])->assertCreated();
    }

    private function createSeries(User $owner, int $patientId, int $instanceCount = 20): PhrDicomSeries
    {
        $upload = PhrDicomUpload::create([
            'patient_id' => $patientId,
            'uploaded_by_user_id' => $owner->id,
            'status' => PhrDicomUpload::STATUS_PROCESSED,
            'stored_files' => $instanceCount,
            'r2_prefix' => "phr/dicom/patients/{$patientId}/uploads/volume-cache-test-".uniqid(),
        ]);
        $study = PhrDicomStudy::create([
            'patient_id' => $patientId,
            'upload_id' => $upload->id,
            'study_instance_uid' => '1.2.840.10008.study.'.uniqid(),
            'modalities' => 'CT',
        ]);
        $series = PhrDicomSeries::create([
            'patient_id' => $patientId,
            'study_id' => $study->id,
            'series_instance_uid' => '1.2.840.10008.series.'.uniqid(),
            'modality' => 'CT',
            'description' => 'Volume series',
        ]);

        for ($index = 0; $index < $instanceCount; $index++) {
            $relativePath = 'VOLUME/IM'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
            $file = PhrDicomFile::create([
                'patient_id' => $patientId,
                'upload_id' => $upload->id,
                'file_kind' => PhrDicomFile::KIND_DICOM,
                'r2_key' => $upload->r2_prefix.'/'.$relativePath,
                'original_relative_path' => $relativePath,
                'original_path_hash' => hash('sha256', $relativePath),
                'original_filename' => basename($relativePath),
                'mime_type' => 'application/dicom',
                'file_size_bytes' => 1024,
                'sha256' => hash('sha256', $series->series_instance_uid.'-'.$index),
            ]);

            PhrDicomInstance::create([
                'patient_id' => $patientId,
                'study_id' => $study->id,
                'series_id' => $series->id,
                'upload_id' => $upload->id,
                'file_id' => $file->id,
                'sop_instance_uid' => $series->series_instance_uid.'.'.($index + 1),
                'instance_number' => $index + 1,
                'transfer_syntax_uid' => '1.2.840.10008.1.2.1',
                'rows' => 512,
                'columns' => 512,
                'number_of_frames' => 1,
                'metadata_json' => [
                    'ImagePositionPatient' => [0, 0, $index],
                    'ImageOrientationPatient' => [1, 0, 0, 0, 1, 0],
                    'PixelSpacing' => [0.4277, 0.4277],
                    'BitsAllocated' => 16,
                    'PixelRepresentation' => 1,
                    'SamplesPerPixel' => 1,
                    'PhotometricInterpretation' => 'MONOCHROME2',
                ],
            ]);
        }

        return $series;
    }

    private function gzipBytes(string $payload = 'volume'): string
    {
        return "\x1f\x8b".$payload;
    }

    private function cacheUrl(int $patientId, int $seriesId): string
    {
        return "/api/phr/patients/{$patientId}/dicom/series/{$seriesId}/volume-cache";
    }

    private function manifestUrl(int $patientId, int $seriesId): string
    {
        return "/api/phr/patients/{$patientId}/dicom/series/{$seriesId}/volume-manifest";
    }
}

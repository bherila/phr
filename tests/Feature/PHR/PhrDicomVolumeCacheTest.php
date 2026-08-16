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
    public function test_unrelated_user_cannot_store_or_read_cache_and_viewer_cannot_store_it(): void
    {
        Storage::fake(DicomUploadProcessor::DISK);

        $owner = $this->createUser();
        $viewer = $this->createUser();
        $manager = $this->createUser();
        $unrelated = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $series = $this->createSeries($owner, $patientId);

        $this->postCache($unrelated, $patientId, $series->id, $this->gzipBytes())
            ->assertNotFound();
        $this->actingAs($unrelated)
            ->get($this->cacheUrl($patientId, $series->id))
            ->assertNotFound();

        $this->grantPatientAccess($owner, $patientId, $viewer, 'viewer');

        // Populating the cache overwrites bytes every other reader of this
        // patient downloads and decodes, so a read-only grant must not permit
        // it. The viewer keeps read access to an artifact somebody else stored.
        $this->postCache($viewer, $patientId, $series->id, $this->gzipBytes())
            ->assertForbidden();
        $this->assertSame(0, PhrDicomFile::query()->where('file_kind', PhrDicomFile::KIND_DERIVED_VOLUME)->count());

        $this->grantPatientAccess($owner, $patientId, $manager, 'manager');

        $this->postCache($manager, $patientId, $series->id, $this->gzipBytes())
            ->assertCreated()
            ->assertExactJson([
                'stored' => true,
                'byte_size' => strlen($this->gzipBytes()),
                'pipeline_version' => 1,
            ]);

        $this->actingAs($viewer)
            ->get($this->cacheUrl($patientId, $series->id))
            ->assertOk();

        $artifact = PhrDicomFile::query()
            ->where('file_kind', PhrDicomFile::KIND_DERIVED_VOLUME)
            ->sole();
        $this->assertSame($patientId, $artifact->patient_id);
        $this->assertSame($series->instances()->firstOrFail()->upload_id, $artifact->upload_id);
        $this->assertSame("patients/{$patientId}/imaging/dicom/derived/series/{$series->id}/v1.bin.gz", $artifact->r2_key);
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

    /**
     * Regression: cross-tenant volume-cache poisoning.
     *
     * `SeriesInstanceUID` is read verbatim from an uploaded DICOM file, so an
     * attacker can mint a series carrying a victim's UID under their own
     * patient. When the cache key was derived from that UID, storing a cache
     * for the attacker's own series overwrote the victim's cached volume in
     * shared object storage — the victim's 3D viewer then decoded attacker
     * bytes. The key must contain no DICOM-supplied identifier.
     */
    public function test_colliding_series_uid_under_another_patient_cannot_overwrite_victim_cache(): void
    {
        Storage::fake(DicomUploadProcessor::DISK);
        $disk = Storage::disk(DicomUploadProcessor::DISK);

        $victim = $this->createUser();
        $victimPatientId = $this->createPatientFor($victim);
        $victimSeries = $this->createSeries($victim, $victimPatientId);

        $victimBytes = $this->gzipBytes('victim-volume');
        $this->postCache($victim, $victimPatientId, $victimSeries->id, $victimBytes)->assertCreated();
        $victimArtifact = PhrDicomFile::query()
            ->where('file_kind', PhrDicomFile::KIND_DERIVED_VOLUME)
            ->where('patient_id', $victimPatientId)
            ->sole();
        $this->assertSame($victimBytes, $disk->get($victimArtifact->r2_key));

        // The attacker controls their own upload, so they can author a series
        // whose SeriesInstanceUID is identical to the victim's.
        $attacker = $this->createUser();
        $attackerPatientId = $this->createPatientFor($attacker);
        $attackerSeries = $this->createSeries(
            $attacker,
            $attackerPatientId,
            seriesInstanceUid: $victimSeries->series_instance_uid,
        );
        $this->assertSame($victimSeries->series_instance_uid, $attackerSeries->series_instance_uid);
        $this->assertNotSame($victimSeries->id, $attackerSeries->id);

        $this->postCache($attacker, $attackerPatientId, $attackerSeries->id, $this->gzipBytes('attacker-payload'))
            ->assertCreated();

        // The victim's object is untouched, and the two artifacts occupy
        // distinct keys despite the colliding UID.
        $this->assertSame($victimBytes, $disk->get($victimArtifact->r2_key));

        $attackerArtifact = PhrDicomFile::query()
            ->where('file_kind', PhrDicomFile::KIND_DERIVED_VOLUME)
            ->where('patient_id', $attackerPatientId)
            ->sole();
        $this->assertNotSame($victimArtifact->r2_key, $attackerArtifact->r2_key);
        $this->assertSame($this->gzipBytes('attacker-payload'), $disk->get($attackerArtifact->r2_key));

        // And the victim still serves their own bytes over the read path.
        $response = $this->actingAs($victim)
            ->get($this->cacheUrl($victimPatientId, $victimSeries->id))
            ->assertOk();
        $this->assertSame($victimBytes, $response->streamedContent());
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
        $this->assertStringStartsWith('http://localhost/patients/', $signedUrl);
        $this->assertStringContainsString('expiration=', $signedUrl);
        $this->assertStringNotContainsString('/api/phr/patients/', $signedUrl);

        $redirectResponse = $this->actingAs($owner)
            ->get($this->cacheUrl($patientId, $series->id))
            ->assertRedirect()
            ->assertHeader('Cache-Control', 'no-store, private');
        $location = (string) $redirectResponse->headers->get('Location');
        $this->assertStringStartsWith('http://localhost/patients/', $location);
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

        $staleKey = "derived/volume-cache/patients/{$patientId}/series/{$series->id}/v0.bin.gz";
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

    private function createSeries(
        User $owner,
        int $patientId,
        int $instanceCount = 20,
        ?string $seriesInstanceUid = null,
    ): PhrDicomSeries {
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
            'series_instance_uid' => $seriesInstanceUid ?? '1.2.840.10008.series.'.uniqid(),
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

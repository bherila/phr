<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDicomFile;
use App\Models\PhrDicomInstance;
use App\Models\PhrDicomSeries;
use App\Models\PhrDicomStudy;
use App\Models\PhrDicomUpload;
use App\Models\User;
use App\Services\PHR\DICOM\DicomUploadProcessor;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhrDicomVolumeManifestTest extends TestCase
{
    public function test_manifest_requires_patient_access_and_scopes_series_to_patient(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $unrelated = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $otherPatientId = $this->createPatientFor($owner);
        $series = $this->createSeries($owner, $patientId);
        $otherSeries = $this->createSeries($owner, $otherPatientId);

        $this->actingAs($unrelated)
            ->getJson($this->manifestUrl($patientId, $series->id))
            ->assertNotFound();

        $this->grantPatientAccess($owner, $patientId, $viewer, 'viewer');

        $this->actingAs($viewer)
            ->getJson($this->manifestUrl($patientId, $series->id))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('series.id', $series->id)
            ->assertJsonPath('eligible', true)
            ->assertJsonPath('cache.available', false)
            ->assertJsonPath('cache.url', null)
            ->assertJsonPath('cache.pipeline_version', 1);

        $this->actingAs($viewer)
            ->getJson("/api/phr/patients/{$patientId}/dicom/studies/{$series->study_id}/viewer-json")
            ->assertOk()
            ->assertJsonPath('studies.0.series.0.id', $series->id);

        $this->actingAs($viewer)
            ->getJson($this->manifestUrl($patientId, $otherSeries->id))
            ->assertNotFound();
    }

    public function test_manifest_rejects_series_with_too_few_slices(): void
    {
        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $series = $this->createSeries($owner, $patientId, instanceCount: 19);

        $this->actingAs($owner)
            ->getJson($this->manifestUrl($patientId, $series->id))
            ->assertOk()
            ->assertJsonPath('eligible', false)
            ->assertJsonPath('reasons.0', 'too_few_slices')
            ->assertJsonPath('volume', null)
            ->assertJsonCount(0, 'instances');
    }

    public function test_manifest_rejects_unsupported_modality(): void
    {
        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $series = $this->createSeries($owner, $patientId, modality: 'US');

        $this->actingAs($owner)
            ->getJson($this->manifestUrl($patientId, $series->id))
            ->assertOk()
            ->assertJsonPath('eligible', false)
            ->assertJsonPath('reasons.0', 'unsupported_modality');
    }

    public function test_manifest_sorts_instances_by_normal_projection_not_instance_number(): void
    {
        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $positions = range(0, 19);
        $instanceNumbers = array_reverse(range(1, 20));
        $series = $this->createSeries($owner, $patientId, positions: $positions, instanceNumbers: $instanceNumbers);

        $response = $this->actingAs($owner)
            ->getJson($this->manifestUrl($patientId, $series->id))
            ->assertOk()
            ->assertJsonPath('eligible', true);

        $this->assertSame($positions, $response->json('instances.*.projection'));
        $this->assertSame($instanceNumbers, array_map(static fn (mixed $value): int => (int) $value, $response->json('instances.*.sop_instance_uid')));
    }

    public function test_manifest_excludes_instance_outside_dominant_orientation_group(): void
    {
        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $series = $this->createSeries($owner, $patientId, instanceCount: 21, orientationOutlierIndex: 20);

        $this->actingAs($owner)
            ->getJson($this->manifestUrl($patientId, $series->id))
            ->assertOk()
            ->assertJsonPath('eligible', true)
            ->assertJsonPath('excluded_instance_count', 1)
            ->assertJsonPath('volume.slice_count', 20)
            ->assertJsonCount(20, 'instances');
    }

    public function test_manifest_honors_signed_url_configuration(): void
    {
        Storage::fake(DicomUploadProcessor::DISK);

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $series = $this->createSeries($owner, $patientId);

        config(['phr.dicom_viewer_direct_signed_urls' => false]);
        $proxyResponse = $this->actingAs($owner)
            ->getJson($this->manifestUrl($patientId, $series->id))
            ->assertOk();
        $this->assertStringStartsWith(url("/api/phr/patients/{$patientId}/dicom/instances/"), (string) $proxyResponse->json('instances.0.url'));

        config([
            'phr.dicom_viewer_direct_signed_urls' => true,
            'phr.dicom_viewer_url_ttl_minutes' => 12,
        ]);
        $signedResponse = $this->actingAs($owner)
            ->getJson($this->manifestUrl($patientId, $series->id))
            ->assertOk();

        $signedInstanceUrl = (string) $signedResponse->json('instances.0.url');
        // Stored references remain path-format agnostic throughout migration;
        // this fixture deliberately carries a legacy key.
        $this->assertStringStartsWith('http://localhost/phr/dicom/patients/', $signedInstanceUrl);
        $this->assertStringContainsString('expiration=', $signedInstanceUrl);
        $this->assertStringNotContainsString('/api/phr/patients/', $signedInstanceUrl);
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

    /**
     * @param  list<int|float>|null  $positions
     * @param  list<int>|null  $instanceNumbers
     */
    private function createSeries(
        User $owner,
        int $patientId,
        string $modality = 'CT',
        int $instanceCount = 20,
        ?array $positions = null,
        ?array $instanceNumbers = null,
        ?int $orientationOutlierIndex = null,
    ): PhrDicomSeries {
        $upload = PhrDicomUpload::create([
            'patient_id' => $patientId,
            'uploaded_by_user_id' => $owner->id,
            'status' => PhrDicomUpload::STATUS_PROCESSED,
            'stored_files' => $instanceCount,
            'r2_prefix' => "phr/dicom/patients/{$patientId}/uploads/volume-test-".uniqid(),
        ]);
        $study = PhrDicomStudy::create([
            'patient_id' => $patientId,
            'upload_id' => $upload->id,
            'study_instance_uid' => '1.2.840.10008.study.'.uniqid(),
            'modalities' => $modality,
        ]);
        $series = PhrDicomSeries::create([
            'patient_id' => $patientId,
            'study_id' => $study->id,
            'series_instance_uid' => '1.2.840.10008.series.'.uniqid(),
            'modality' => $modality,
            'description' => 'Volume series',
        ]);

        $positions ??= range(0, $instanceCount - 1);
        $instanceNumbers ??= range(1, $instanceCount);

        foreach ($positions as $index => $position) {
            $instanceNumber = $instanceNumbers[$index];
            $relativePath = 'VOLUME/IM'.str_pad((string) $instanceNumber, 4, '0', STR_PAD_LEFT);
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
            $orientation = $index === $orientationOutlierIndex
                ? [0, 1, 0, 1, 0, 0]
                : [1, 0, 0, 0, 1, 0];

            PhrDicomInstance::create([
                'patient_id' => $patientId,
                'study_id' => $study->id,
                'series_id' => $series->id,
                'upload_id' => $upload->id,
                'file_id' => $file->id,
                'sop_instance_uid' => (string) $instanceNumber,
                'instance_number' => $instanceNumber,
                'transfer_syntax_uid' => '1.2.840.10008.1.2.1',
                'rows' => 512,
                'columns' => 512,
                'number_of_frames' => 1,
                'metadata_json' => [
                    'ImagePositionPatient' => [0, 0, $position],
                    'ImageOrientationPatient' => $orientation,
                    'PixelSpacing' => [0.4277, '0.4277'],
                    'BitsAllocated' => 16,
                    'PixelRepresentation' => 1,
                    'SamplesPerPixel' => 1,
                    'PhotometricInterpretation' => 'MONOCHROME2',
                    'WindowCenter' => '350',
                    'WindowWidth' => 2000,
                ],
            ]);
        }

        return $series;
    }

    private function manifestUrl(int $patientId, int $seriesId): string
    {
        return "/api/phr/patients/{$patientId}/dicom/series/{$seriesId}/volume-manifest";
    }
}

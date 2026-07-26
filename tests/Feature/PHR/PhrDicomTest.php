<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDicomFile;
use App\Models\PhrDicomInstance;
use App\Models\PhrDicomSeries;
use App\Models\PhrDicomStudy;
use App\Models\PhrDicomUpload;
use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\DICOM\DicomMetadataParser;
use App\Services\PHR\DICOM\DicomUploadLimits;
use App\Services\PHR\DICOM\DicomUploadProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PhrDicomTest extends TestCase
{
    public function test_phr_dicom_schema_exists(): void
    {
        $this->assertTrue(Schema::hasTable('phr_dicom_uploads'));
        $this->assertTrue(Schema::hasTable('phr_dicom_files'));
        $this->assertTrue(Schema::hasTable('phr_dicom_studies'));
        $this->assertTrue(Schema::hasTable('phr_dicom_series'));
        $this->assertTrue(Schema::hasTable('phr_dicom_instances'));
        $this->assertTrue(Schema::hasColumn('phr_dicom_files', 'original_relative_path'));
        $this->assertTrue(Schema::hasColumn('phr_dicom_instances', 'transfer_syntax_uid'));
        $this->assertTrue(Schema::hasIndex('phr_dicom_studies', 'phr_dicom_studies_patient_uid_unique'));
    }

    public function test_upload_status_defaults_to_pending(): void
    {
        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);

        $upload = PhrDicomUpload::create([
            'patient_id' => $patientId,
            'uploaded_by_user_id' => $owner->id,
            'r2_prefix' => 'phr/dicom/patients/'.$patientId.'/uploads/manual',
        ]);

        $this->assertSame(PhrDicomUpload::STATUS_PENDING, $upload->refresh()->status);
    }

    public function test_open_upload_advertises_direct_upload_size_cap(): void
    {
        config(['phr.dicom_max_file_bytes' => DicomUploadLimits::DEFAULT_MAX_DIRECT_FILE_BYTES]);

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);

        $this->actingAs($owner)
            ->postJson("/api/phr/patients/{$patientId}/dicom/uploads", ['root_name' => 'CARDIAC_CT'])
            ->assertCreated()
            ->assertJsonPath('limits.max_file_bytes', DicomUploadLimits::DEFAULT_MAX_DIRECT_FILE_BYTES)
            ->assertJsonPath('limits.max_file_size_label', '1 GB')
            ->assertJsonPath('limits.direct_upload', true);
    }

    public function test_manager_can_upload_directory_and_viewer_can_read_metadata_and_download_study(): void
    {
        $this->fakeDicomDisk();

        $owner = $this->createUser();
        $manager = $this->createUser();
        $viewer = $this->createUser();
        $other = $this->createUser();

        $patientId = $this->createPatientFor($owner);
        $this->grantPatientAccess($owner, $patientId, $manager, 'manager');
        $this->grantPatientAccess($owner, $patientId, $viewer, 'viewer');

        $uploadId = $this->openUpload($manager, $patientId, 'CARDIAC_CT');
        $this->postFile($manager, $patientId, $uploadId, UploadedFile::fake()->createWithContent('DICOMDIR', $this->dicomdirBytes()), 'CARDIAC_CT/DICOMDIR')
            ->assertOk()
            ->assertJsonPath('result.stored', true);
        $this->postFile($manager, $patientId, $uploadId, UploadedFile::fake()->createWithContent('IM0001', $this->dicomBytes()), 'CARDIAC_CT/ST0001/SE0001/IM0001')
            ->assertOk()
            ->assertJsonPath('result.stored', true);
        $this->postFile($manager, $patientId, $uploadId, UploadedFile::fake()->createWithContent('SETUP.EXE', 'not a dicom file'), 'CARDIAC_CT/VIEWER/SETUP.EXE')
            ->assertOk()
            ->assertJsonPath('result.stored', false)
            ->assertJsonPath('result.skipped_reason', 'auxiliary_file');

        $this->finalizeUpload($manager, $patientId, $uploadId)
            ->assertOk()
            ->assertJsonPath('upload.total_files', 3)
            ->assertJsonPath('upload.stored_files', 2)
            ->assertJsonPath('upload.skipped_files', 1)
            ->assertJsonPath('upload.status', PhrDicomUpload::STATUS_PROCESSED)
            ->assertJsonPath('upload.original_root_name', 'CARDIAC_CT');

        $study = PhrDicomStudy::query()->where('patient_id', $patientId)->sole();
        $series = PhrDicomSeries::query()->where('study_id', $study->id)->sole();
        $instance = PhrDicomInstance::query()->where('series_id', $series->id)->sole();
        $upload = PhrDicomUpload::query()->where('patient_id', $patientId)->sole();

        $this->assertSame('1.2.840.113619.2.55.3.604688437.20260517.1', $study->study_instance_uid);
        $this->assertSame('CT', $study->modalities);
        $this->assertSame('CARDIAC_CT/ST0001/SE0001/IM0001', $instance->file->original_relative_path);
        $this->assertSame(1, $upload->skipped_files);
        $this->assertSame(2, PhrDicomFile::query()->where('patient_id', $patientId)->count());
        Storage::disk(DicomUploadProcessor::DISK)->assertExists($instance->file->r2_key);

        $this->actingAs($viewer)->getJson("/api/phr/patients/{$patientId}/dicom/studies")
            ->assertOk()
            ->assertJsonPath('studies.0.instance_count', 1);

        $this->actingAs($viewer)->getJson("/api/phr/patients/{$patientId}/dicom/studies/{$study->id}/viewer-json")
            ->assertOk()
            ->assertJsonPath('studies.0.StudyInstanceUID', $study->study_instance_uid)
            ->assertJsonPath('studies.0.series.0.instances.0.metadata.Rows', 512)
            ->assertJsonPath('studies.0.series.0.instances.0.url', 'dicomweb:'.url("/api/phr/patients/{$patientId}/dicom/instances/{$instance->id}/file"));

        $this->actingAs($viewer)->get("/api/phr/patients/{$patientId}/dicom/instances/{$instance->id}/file")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/dicom');

        $this->actingAs($viewer)->get("/api/phr/patients/{$patientId}/dicom/studies/{$study->id}/download")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip');

        $this->actingAs($viewer)->postJson("/api/phr/patients/{$patientId}/dicom/uploads", [])
            ->assertForbidden();

        $this->actingAs($other)->getJson("/api/phr/patients/{$patientId}/dicom/studies")->assertNotFound();
        $this->actingAs($other)->get("/api/phr/patients/{$patientId}/dicom/instances/{$instance->id}/file")->assertNotFound();
    }

    public function test_study_index_returns_newest_first_with_file_size_bytes(): void
    {
        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);

        $oldStudy = $this->createIndexedStudy(
            $owner,
            $patientId,
            '1.2.840.113619.study.old',
            '2025-12-31',
            '235959',
            [1024 * 1024],
        );
        $morningStudy = $this->createIndexedStudy(
            $owner,
            $patientId,
            '1.2.840.113619.study.morning',
            '2026-05-19',
            '081500',
            [1024 * 1024, 512 * 1024],
        );
        $latestStudy = $this->createIndexedStudy(
            $owner,
            $patientId,
            '1.2.840.113619.study.latest',
            '2026-05-19',
            '163000',
            [2 * 1024 * 1024],
        );

        $response = $this->actingAs($owner)
            ->getJson("/api/phr/patients/{$patientId}/dicom/studies")
            ->assertOk();

        $this->assertSame([
            $latestStudy->id,
            $morningStudy->id,
            $oldStudy->id,
        ], array_column($response->json('studies'), 'id'));
        $this->assertSame(2 * 1024 * 1024, $response->json('studies.0.file_size_bytes'));
        $this->assertSame(1572864, $response->json('studies.1.file_size_bytes'));
        $this->assertSame(1024 * 1024, $response->json('studies.2.file_size_bytes'));
    }

    public function test_auxiliary_files_are_skipped(): void
    {
        $this->fakeDicomDisk();

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);

        $uploadId = $this->openUpload($owner, $patientId, 'CARDIAC_CT');

        $cases = [
            ['CARDIAC_CT/Reviewer/Content/Thumbs.db', 'thumbnail cache binary'],
            ['CARDIAC_CT/Reviewer/Content/Default Config/MAS.Oem.config', '<?xml version="1.0"?><config />'],
            ['CARDIAC_CT/Reviewer/Content/Default Config/Modality/Reviewer.Modality.CT.config', '<?xml version="1.0"?><config />'],
            ['CARDIAC_CT/Reviewer/Sorna.License.exml', 'encrypted license'],
            ['CARDIAC_CT/Reviewer/Content/design.std', 'binary design payload'],
            ['CARDIAC_CT/.DS_Store', "\0apple-fs-meta"],
            ['CARDIAC_CT/desktop.ini', '[.ShellClassInfo]'],
        ];

        foreach ($cases as [$relativePath, $body]) {
            $this->postFile(
                $owner,
                $patientId,
                $uploadId,
                UploadedFile::fake()->createWithContent(basename($relativePath), $body),
                $relativePath,
            )
                ->assertOk()
                ->assertJsonPath('result.stored', false)
                ->assertJsonPath('result.skipped_reason', 'auxiliary_file');
        }

        $this->finalizeUpload($owner, $patientId, $uploadId)
            ->assertStatus(422)
            ->assertJsonPath('message', 'No DICOM image instances were uploaded. The session contained only non-image DICOM files, skipped files, or files that failed before reaching the server.');

        $upload = PhrDicomUpload::query()->where('patient_id', $patientId)->sole();
        $this->assertSame(PhrDicomUpload::STATUS_FAILED, $upload->status);
        $this->assertSame(0, $upload->stored_files);
        $this->assertSame(count($cases), $upload->skipped_files);
    }

    public function test_dicomdir_only_upload_cannot_be_finalized_as_imaging_study(): void
    {
        $this->fakeDicomDisk();

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);

        $uploadId = $this->openUpload($owner, $patientId, 'CARDIAC_CT');
        $this->postFile($owner, $patientId, $uploadId, UploadedFile::fake()->createWithContent('DICOMDIR', $this->dicomdirBytes()), 'CARDIAC_CT/DICOMDIR')
            ->assertOk()
            ->assertJsonPath('result.stored', true);

        $this->finalizeUpload($owner, $patientId, $uploadId)
            ->assertStatus(422)
            ->assertJsonPath('message', 'No DICOM image instances were uploaded. The session contained only non-image DICOM files, skipped files, or files that failed before reaching the server.');

        $this->assertSame(PhrDicomUpload::STATUS_FAILED, PhrDicomUpload::query()->where('patient_id', $patientId)->sole()->status);
        $this->assertSame(0, PhrDicomFile::query()->where('patient_id', $patientId)->count());
        $this->assertSame(0, PhrDicomStudy::query()->where('patient_id', $patientId)->count());
        $this->assertSame([], Storage::disk(DicomUploadProcessor::DISK)->allFiles());
    }

    public function test_finalized_session_rejects_additional_files(): void
    {
        $this->fakeDicomDisk();

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);

        $uploadId = $this->openUpload($owner, $patientId, null);
        $this->postFile($owner, $patientId, $uploadId, UploadedFile::fake()->createWithContent('IM0001', $this->dicomBytes()), 'IM0001')->assertOk();
        $this->finalizeUpload($owner, $patientId, $uploadId)->assertOk();

        $this->postFile($owner, $patientId, $uploadId, UploadedFile::fake()->createWithContent('IM0002', $this->dicomBytes([
            'sop_instance_uid' => '1.2.840.113619.2.55.3.604688437.20260517.late.1',
        ])), 'IM0002')->assertStatus(409);
    }

    public function test_direct_upload_url_reserves_unique_r2_paths(): void
    {
        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $uploadId = $this->openUpload($owner, $patientId, 'CARDIAC_CT');
        $adapter = new class
        {
            /**
             * @param  array<string, string>  $options
             * @return array{url: string, headers: array<string, string>}
             */
            public function temporaryUploadUrl(string $path, mixed $expiration, array $options): array
            {
                return [
                    'url' => 'https://r2.example.test/'.rawurlencode($path),
                    'headers' => [
                        'Content-Type' => $options['ContentType'],
                        'Host' => ['dicom-test-bucket.r2.example.test'],
                        'x-amz-meta-upload' => ['dicom'],
                    ],
                ];
            }
        };

        config(['filesystems.disks.'.DicomUploadProcessor::DISK.'.bucket' => 'dicom-test-bucket']);
        Storage::shouldReceive('disk')
            ->twice()
            ->with(DicomUploadProcessor::DISK)
            ->andReturn($adapter);

        $payload = [
            'filename' => 'IM0001',
            'relative_path' => 'CARDIAC_CT/ST0001/SE0001/IM0001',
            'content_type' => 'application/dicom',
            'file_size' => 1024,
        ];

        $first = $this->actingAs($owner)
            ->postJson("/api/phr/patients/{$patientId}/dicom/uploads/{$uploadId}/signed-url", $payload)
            ->assertOk()
            ->assertJsonPath('relative_path', 'CARDIAC_CT/ST0001/SE0001/IM0001')
            ->assertJsonPath('headers.Content-Type', 'application/dicom')
            ->assertJsonPath('headers.x-amz-meta-upload', 'dicom')
            ->assertJsonMissingPath('headers.Host');

        $second = $this->actingAs($owner)
            ->postJson("/api/phr/patients/{$patientId}/dicom/uploads/{$uploadId}/signed-url", $payload)
            ->assertOk()
            ->assertJsonPath('relative_path', 'CARDIAC_CT/ST0001/SE0001/IM0001-2');

        $this->assertStringStartsWith('phr/dicom/patients/'.$patientId.'/uploads/', (string) $first->json('r2_key'));
        $this->assertStringEndsWith('CARDIAC_CT/ST0001/SE0001/IM0001-2', (string) $second->json('r2_key'));

        $upload = PhrDicomUpload::query()->findOrFail($uploadId);
        $this->assertSame([
            'CARDIAC_CT/ST0001/SE0001/IM0001',
            'CARDIAC_CT/ST0001/SE0001/IM0001-2',
        ], $upload->manifest_json['reserved_paths']);
    }

    public function test_direct_upload_url_batch_reserves_unique_r2_paths(): void
    {
        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $uploadId = $this->openUpload($owner, $patientId, 'CARDIAC_CT');
        $adapter = new class
        {
            /**
             * @param  array<string, string>  $options
             * @return array{url: string, headers: array<string, string>}
             */
            public function temporaryUploadUrl(string $path, mixed $expiration, array $options): array
            {
                return [
                    'url' => 'https://r2.example.test/'.rawurlencode($path),
                    'headers' => [
                        'Content-Type' => $options['ContentType'],
                        'Host' => ['dicom-test-bucket.r2.example.test'],
                        'x-amz-meta-upload' => ['dicom'],
                    ],
                ];
            }
        };

        config(['filesystems.disks.'.DicomUploadProcessor::DISK.'.bucket' => 'dicom-test-bucket']);
        Storage::shouldReceive('disk')
            ->twice()
            ->with(DicomUploadProcessor::DISK)
            ->andReturn($adapter);

        $response = $this->actingAs($owner)
            ->postJson("/api/phr/patients/{$patientId}/dicom/uploads/{$uploadId}/signed-urls", [
                'files' => [
                    [
                        'client_id' => 'file-1',
                        'filename' => 'IM0001',
                        'relative_path' => 'CARDIAC_CT/ST0001/SE0001/IM0001',
                        'content_type' => 'application/dicom',
                        'file_size' => 1024,
                    ],
                    [
                        'client_id' => 'file-2',
                        'filename' => 'IM0001',
                        'relative_path' => 'CARDIAC_CT/ST0001/SE0001/IM0001',
                        'content_type' => 'application/dicom',
                        'file_size' => 1024,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('uploads.0.client_id', 'file-1')
            ->assertJsonPath('uploads.0.relative_path', 'CARDIAC_CT/ST0001/SE0001/IM0001')
            ->assertJsonPath('uploads.0.headers.Content-Type', 'application/dicom')
            ->assertJsonPath('uploads.0.headers.x-amz-meta-upload', 'dicom')
            ->assertJsonMissingPath('uploads.0.headers.Host')
            ->assertJsonPath('uploads.1.client_id', 'file-2')
            ->assertJsonPath('uploads.1.relative_path', 'CARDIAC_CT/ST0001/SE0001/IM0001-2');

        $this->assertStringStartsWith('phr/dicom/patients/'.$patientId.'/uploads/', (string) $response->json('uploads.0.r2_key'));
        $this->assertStringEndsWith('CARDIAC_CT/ST0001/SE0001/IM0001-2', (string) $response->json('uploads.1.r2_key'));

        $upload = PhrDicomUpload::query()->findOrFail($uploadId);
        $this->assertSame([
            'CARDIAC_CT/ST0001/SE0001/IM0001',
            'CARDIAC_CT/ST0001/SE0001/IM0001-2',
        ], $upload->manifest_json['reserved_paths']);
    }

    public function test_direct_upload_url_batch_rejects_files_over_configured_cap(): void
    {
        config(['phr.dicom_max_file_bytes' => 10]);

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $uploadId = $this->openUpload($owner, $patientId, 'CARDIAC_CT');

        $response = $this->actingAs($owner)
            ->postJson("/api/phr/patients/{$patientId}/dicom/uploads/{$uploadId}/signed-urls", [
                'files' => [
                    [
                        'client_id' => 'file-1',
                        'filename' => 'IM0001',
                        'relative_path' => 'CARDIAC_CT/ST0001/SE0001/IM0001',
                        'content_type' => 'application/dicom',
                        'file_size' => 11,
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('files.0.file_size');

        $errors = $response->json('errors');
        $this->assertIsArray($errors);
        $this->assertSame(['Each DICOM file must be 10 B or smaller.'], $errors['files.0.file_size'] ?? null);

        $upload = PhrDicomUpload::query()->findOrFail($uploadId);
        $this->assertArrayNotHasKey('reserved_paths', $upload->manifest_json);
    }

    public function test_direct_upload_url_serializes_empty_headers_as_json_object(): void
    {
        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $uploadId = $this->openUpload($owner, $patientId, 'CARDIAC_CT');
        $adapter = new class
        {
            /**
             * @param  array<string, mixed>  $options
             * @return array{url: string, headers: array<string, string>}
             */
            public function temporaryUploadUrl(string $path, mixed $expiration, array $options): array
            {
                return [
                    'url' => 'https://r2.example.test/'.rawurlencode($path),
                    'headers' => [],
                ];
            }
        };

        config(['filesystems.disks.'.DicomUploadProcessor::DISK.'.bucket' => 'dicom-test-bucket']);
        Storage::shouldReceive('disk')
            ->once()
            ->with(DicomUploadProcessor::DISK)
            ->andReturn($adapter);

        $response = $this->actingAs($owner)
            ->postJson("/api/phr/patients/{$patientId}/dicom/uploads/{$uploadId}/signed-url", [
                'filename' => 'IM0001',
                'relative_path' => 'CARDIAC_CT/ST0001/SE0001/IM0001',
                'content_type' => 'application/dicom',
                'file_size' => 1024,
            ])
            ->assertOk()
            ->assertJsonPath('relative_path', 'CARDIAC_CT/ST0001/SE0001/IM0001');

        $this->assertStringContainsString('"headers":{}', (string) $response->getContent());
    }

    public function test_viewer_json_can_emit_direct_signed_instance_urls_when_enabled(): void
    {
        $this->fakeDicomDisk();

        $owner = $this->createUser();
        $viewer = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $this->grantPatientAccess($owner, $patientId, $viewer, 'viewer');

        $uploadId = $this->openUpload($owner, $patientId, 'CARDIAC_CT');
        $this->postFile($owner, $patientId, $uploadId, UploadedFile::fake()->createWithContent('IM0001', $this->dicomBytes()), 'CARDIAC_CT/ST0001/SE0001/IM0001')->assertOk();
        $this->finalizeUpload($owner, $patientId, $uploadId)->assertOk();

        $study = PhrDicomStudy::query()->where('patient_id', $patientId)->sole();

        config([
            'phr.dicom_viewer_direct_signed_urls' => true,
            'phr.dicom_viewer_url_ttl_minutes' => 12,
        ]);

        $viewerResponse = $this->actingAs($viewer)
            ->getJson("/api/phr/patients/{$patientId}/dicom/studies/{$study->id}/viewer-json")
            ->assertOk();

        $signedInstanceUrl = (string) $viewerResponse->json('studies.0.series.0.instances.0.url');
        $this->assertStringStartsWith('dicomweb:http://localhost/phr/dicom/patients/', $signedInstanceUrl);
        $this->assertStringContainsString('expiration=', $signedInstanceUrl);
        $this->assertStringNotContainsString('/api/phr/patients/', $signedInstanceUrl);
    }

    public function test_direct_upload_url_rejects_files_over_configured_cap(): void
    {
        config(['phr.dicom_max_file_bytes' => 10]);

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $uploadId = $this->openUpload($owner, $patientId, 'CARDIAC_CT');

        $this->actingAs($owner)
            ->postJson("/api/phr/patients/{$patientId}/dicom/uploads/{$uploadId}/signed-url", [
                'filename' => 'IM0001',
                'relative_path' => 'CARDIAC_CT/ST0001/SE0001/IM0001',
                'content_type' => 'application/dicom',
                'file_size' => 11,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file_size')
            ->assertJsonPath('errors.file_size.0', 'Each DICOM file must be 10 B or smaller.');

        $upload = PhrDicomUpload::query()->findOrFail($uploadId);
        $this->assertArrayNotHasKey('reserved_paths', $upload->manifest_json);
    }

    public function test_direct_upload_completion_deletes_objects_over_configured_cap(): void
    {
        config(['phr.dicom_max_file_bytes' => 10]);
        $this->fakeDicomDisk();

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $uploadId = $this->openUpload($owner, $patientId, 'CARDIAC_CT');
        $upload = PhrDicomUpload::query()->findOrFail($uploadId);
        $relativePath = 'CARDIAC_CT/ST0001/SE0001/IM0001';
        $storageKey = $upload->r2_prefix.'/'.$relativePath;

        $upload->update([
            'manifest_json' => array_merge($upload->manifest_json ?? [], [
                'reserved_paths' => [$relativePath],
            ]),
        ]);
        Storage::disk(DicomUploadProcessor::DISK)->put($storageKey, str_repeat('x', 11));

        $this->actingAs($owner)
            ->postJson("/api/phr/patients/{$patientId}/dicom/uploads/{$uploadId}/files/complete", [
                'r2_key' => $storageKey,
                'relative_path' => $relativePath,
                'original_filename' => 'IM0001',
                'mime_type' => 'application/dicom',
                'file_size_bytes' => 10,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Each DICOM file must be 10 B or smaller.');

        Storage::disk(DicomUploadProcessor::DISK)->assertMissing($storageKey);
    }

    public function test_direct_uploaded_r2_object_is_registered_and_finalized(): void
    {
        $this->fakeDicomDisk();

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $uploadId = $this->openUpload($owner, $patientId, 'CARDIAC_CT');
        $upload = PhrDicomUpload::query()->findOrFail($uploadId);
        $relativePath = 'CARDIAC_CT/ST0001/SE0001/IM0001';
        $storageKey = $upload->r2_prefix.'/'.$relativePath;
        $contents = $this->dicomBytes();

        $upload->update([
            'manifest_json' => array_merge($upload->manifest_json ?? [], [
                'reserved_paths' => [$relativePath],
            ]),
        ]);
        Storage::disk(DicomUploadProcessor::DISK)->put($storageKey, $contents);

        $this->actingAs($owner)
            ->postJson("/api/phr/patients/{$patientId}/dicom/uploads/{$uploadId}/files/complete", [
                'r2_key' => $storageKey,
                'relative_path' => $relativePath,
                'original_filename' => 'IM0001',
                'mime_type' => 'application/dicom',
                'file_size_bytes' => strlen($contents),
            ])
            ->assertOk()
            ->assertJsonPath('result.stored', true)
            ->assertJsonPath('result.relative_path', $relativePath)
            ->assertJsonPath('upload.total_files', 1)
            ->assertJsonPath('upload.stored_files', 1);

        $this->finalizeUpload($owner, $patientId, $uploadId)
            ->assertOk()
            ->assertJsonPath('upload.status', PhrDicomUpload::STATUS_PROCESSED);

        $study = PhrDicomStudy::query()->where('patient_id', $patientId)->sole();
        $instance = PhrDicomInstance::query()->where('study_id', $study->id)->sole();
        $file = PhrDicomFile::query()->where('patient_id', $patientId)->sole();

        $this->assertSame($storageKey, $file->r2_key);
        $this->assertSame(hash('sha256', $contents), $file->sha256);
        $this->assertSame('1.2.840.113619.2.55.3.604688437.20260517.1.1.1', $instance->sop_instance_uid);
        $this->assertSame([], PhrDicomUpload::query()->findOrFail($uploadId)->manifest_json['reserved_paths']);
        Storage::disk(DicomUploadProcessor::DISK)->assertExists($storageKey);
    }

    public function test_upload_parses_image_metadata_after_undefined_length_sequence(): void
    {
        $this->fakeDicomDisk();

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);

        $uploadId = $this->openUpload($owner, $patientId, 'XR_FOOT');
        $response = $this->postFile(
            $owner,
            $patientId,
            $uploadId,
            UploadedFile::fake()->createWithContent('IN000001', $this->dicomBytes(includeUndefinedLengthSequence: true)),
            'XR_FOOT/DICOM/P0000001/ST000001/SE000001/IN000001',
        )
            ->assertOk()
            ->assertJsonPath('result.stored', true);
        $this->assertIsInt($response->json('result.study_id'));

        $this->finalizeUpload($owner, $patientId, $uploadId)
            ->assertOk()
            ->assertJsonPath('upload.status', PhrDicomUpload::STATUS_PROCESSED)
            ->assertJsonPath('upload.stored_files', 1);

        $study = PhrDicomStudy::query()->where('patient_id', $patientId)->sole();
        $series = PhrDicomSeries::query()->where('study_id', $study->id)->sole();
        $instance = PhrDicomInstance::query()->where('series_id', $series->id)->sole();

        $this->assertSame('1.2.840.113619.2.55.3.604688437.20260517.1', $study->study_instance_uid);
        $this->assertSame('1.2.840.113619.2.55.3.604688437.20260517.1.1', $series->series_instance_uid);
        $this->assertSame('1.2.840.113619.2.55.3.604688437.20260517.1.1.1', $instance->sop_instance_uid);
        $this->assertSame(512, $instance->rows);
        $this->assertSame(512, $instance->columns);
    }

    public function test_cancel_marks_session_failed_and_removes_stored_files(): void
    {
        $this->fakeDicomDisk();

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);

        $uploadId = $this->openUpload($owner, $patientId, null);
        $this->postFile($owner, $patientId, $uploadId, UploadedFile::fake()->createWithContent('IM0001', $this->dicomBytes()), 'IM0001')->assertOk();

        $this->actingAs($owner)
            ->postJson("/api/phr/patients/{$patientId}/dicom/uploads/{$uploadId}/cancel")
            ->assertOk()
            ->assertJsonPath('upload.status', PhrDicomUpload::STATUS_FAILED);

        $this->assertSame(0, PhrDicomFile::query()->where('patient_id', $patientId)->count());
        $this->assertSame(0, PhrDicomInstance::query()->where('patient_id', $patientId)->count());
        $this->assertSame(0, PhrDicomStudy::query()->where('patient_id', $patientId)->count());
    }

    public function test_invalid_php_upload_returns_actionable_dicom_error(): void
    {
        $this->fakeDicomDisk();

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $uploadId = $this->openUpload($owner, $patientId, null);
        $message = 'The DICOM file could not be uploaded. It may exceed the server upload limit. Try a smaller file or ask an administrator to raise the PHP upload_max_filesize, post_max_size, and web server body size limits.';
        $path = tempnam(sys_get_temp_dir(), 'dicom-upload-');
        $this->assertIsString($path);
        file_put_contents($path, 'dicom');

        try {
            $file = new UploadedFile($path, 'IN000001', 'application/dicom', UPLOAD_ERR_INI_SIZE, true);

            $this->postFile($owner, $patientId, $uploadId, $file, 'CARDIAC_CT/ST0001/SE0001/IN000001')
                ->assertStatus(422)
                ->assertJsonPath('message', $message)
                ->assertJsonPath('errors.file.0', $message);
        } finally {
            @unlink($path);
        }

        $this->assertSame(0, PhrDicomFile::query()->where('patient_id', $patientId)->count());
        $this->assertSame(0, PhrDicomInstance::query()->where('patient_id', $patientId)->count());
        $this->assertSame(0, PhrDicomStudy::query()->where('patient_id', $patientId)->count());
    }

    public function test_failed_session_rejects_stale_file_request_without_storing(): void
    {
        $this->fakeDicomDisk();

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);

        $uploadId = $this->openUpload($owner, $patientId, null);
        $staleUpload = PhrDicomUpload::query()->findOrFail($uploadId);

        $this->actingAs($owner)
            ->postJson("/api/phr/patients/{$patientId}/dicom/uploads/{$uploadId}/cancel")
            ->assertOk()
            ->assertJsonPath('upload.status', PhrDicomUpload::STATUS_FAILED);

        try {
            app(DicomUploadProcessor::class)->processSingleFile(
                $staleUpload,
                UploadedFile::fake()->createWithContent('IM0001', $this->dicomBytes()),
                'IM0001',
            );

            $this->fail('Expected stale file processing to be rejected.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
            $this->assertSame('Upload session is no longer accepting files.', $error->getMessage());
        }

        $this->assertSame(0, PhrDicomFile::query()->where('patient_id', $patientId)->count());
        $this->assertSame(0, PhrDicomInstance::query()->where('patient_id', $patientId)->count());
        $this->assertSame(0, PhrDicomStudy::query()->where('patient_id', $patientId)->count());
        $this->assertSame([], Storage::disk(DicomUploadProcessor::DISK)->allFiles());
    }

    public function test_relative_paths_with_traversal_segments_are_sanitized(): void
    {
        $this->fakeDicomDisk();

        $owner = $this->createUser();
        $manager = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $this->grantPatientAccess($owner, $patientId, $manager, 'manager');

        $uploadId = $this->openUpload($manager, $patientId, null);
        // Both '..' segments and absolute-style leading slashes must be stripped.
        $this->postFile($manager, $patientId, $uploadId, UploadedFile::fake()->createWithContent('IM0001', $this->dicomBytes()), '../../../etc/passwd/IM0001')
            ->assertOk()
            ->assertJsonPath('result.stored', true);
        $this->finalizeUpload($manager, $patientId, $uploadId)
            ->assertOk()
            ->assertJsonPath('upload.stored_files', 1);

        $file = PhrDicomFile::query()->where('patient_id', $patientId)->sole();

        // Sanitizer must have stripped all '..' segments — the stored path
        // stays inside the upload's prefix and the relative path no longer
        // contains traversal markers.
        $this->assertStringNotContainsString('..', $file->original_relative_path);
        $this->assertStringNotContainsString('..', $file->r2_key);
        $this->assertStringStartsWith('phr/dicom/patients/', $file->r2_key);
        $this->assertSame('etc/passwd/IM0001', $file->original_relative_path);
    }

    public function test_reupload_of_same_study_preserves_original_upload_id(): void
    {
        $this->fakeDicomDisk();

        $owner = $this->createUser();
        $manager = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $this->grantPatientAccess($owner, $patientId, $manager, 'manager');

        $firstId = $this->openUpload($manager, $patientId, null);
        $this->postFile($manager, $patientId, $firstId, UploadedFile::fake()->createWithContent('IM0001', $this->dicomBytes()), 'CARDIAC_CT/ST0001/SE0001/IM0001')->assertOk();
        $this->finalizeUpload($manager, $patientId, $firstId)->assertOk();

        $study = PhrDicomStudy::query()->where('patient_id', $patientId)->sole();
        $originalUploadId = (int) $study->upload_id;
        $this->assertNotSame(0, $originalUploadId);

        // Re-upload a second instance for the same study/series. The study
        // should pick up the new instance but its upload_id must NOT change.
        $secondId = $this->openUpload($manager, $patientId, null);
        $this->postFile($manager, $patientId, $secondId, UploadedFile::fake()->createWithContent('IM0002', $this->dicomBytes([
            'sop_instance_uid' => '1.2.840.113619.2.55.3.604688437.20260517.1.1.2',
        ])), 'CARDIAC_CT/ST0001/SE0001/IM0002')->assertOk();
        $this->finalizeUpload($manager, $patientId, $secondId)->assertOk();

        $this->assertSame(2, PhrDicomUpload::query()->where('patient_id', $patientId)->count());
        $study->refresh();
        $this->assertSame($originalUploadId, (int) $study->upload_id);
        $this->assertSame(2, PhrDicomInstance::query()->where('study_id', $study->id)->count());
    }

    public function test_duplicate_only_direct_upload_is_discarded_without_deleting_original_study(): void
    {
        $this->fakeDicomDisk();

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);

        $firstId = $this->openUpload($owner, $patientId, 'CARDIAC_CT');
        $this->postFile($owner, $patientId, $firstId, UploadedFile::fake()->createWithContent('IM0001', $this->dicomBytes()), 'CARDIAC_CT/ST0001/SE0001/IM0001')->assertOk();
        $this->finalizeUpload($owner, $patientId, $firstId)->assertOk();

        $study = PhrDicomStudy::query()->where('patient_id', $patientId)->sole();
        $originalUploadId = (int) $study->upload_id;
        $originalFile = PhrDicomFile::query()->where('patient_id', $patientId)->sole();

        $secondId = $this->openUpload($owner, $patientId, 'CARDIAC_CT');
        $secondUpload = PhrDicomUpload::query()->findOrFail($secondId);
        $relativePath = 'CARDIAC_CT/ST0001/SE0001/IM0001';
        $storageKey = $secondUpload->r2_prefix.'/'.$relativePath;
        $contents = $this->dicomBytes();

        $secondUpload->update([
            'manifest_json' => array_merge($secondUpload->manifest_json ?? [], [
                'reserved_paths' => [$relativePath],
            ]),
        ]);
        Storage::disk(DicomUploadProcessor::DISK)->put($storageKey, $contents);

        $this->actingAs($owner)
            ->postJson("/api/phr/patients/{$patientId}/dicom/uploads/{$secondId}/files/complete", [
                'r2_key' => $storageKey,
                'relative_path' => $relativePath,
                'original_filename' => 'IM0001',
                'mime_type' => 'application/dicom',
                'file_size_bytes' => strlen($contents),
            ])
            ->assertOk()
            ->assertJsonPath('result.stored', false)
            ->assertJsonPath('result.skipped_reason', 'duplicate_sop_instance')
            ->assertJsonPath('upload.stored_files', 0)
            ->assertJsonPath('upload.skipped_files', 1);

        Storage::disk(DicomUploadProcessor::DISK)->assertMissing($storageKey);

        $this->finalizeUpload($owner, $patientId, $secondId)
            ->assertOk()
            ->assertJsonPath('duplicate_upload', true)
            ->assertJsonPath('upload.status', PhrDicomUpload::STATUS_FAILED)
            ->assertJsonPath('upload.error_message', DicomUploadProcessor::DUPLICATE_UPLOAD_MESSAGE);

        $study->refresh();
        $this->assertSame($originalUploadId, (int) $study->upload_id);
        $this->assertSame(1, PhrDicomStudy::query()->where('patient_id', $patientId)->count());
        $this->assertSame(1, PhrDicomFile::query()->where('patient_id', $patientId)->count());
        $this->assertSame(1, PhrDicomInstance::query()->where('patient_id', $patientId)->count());
        $this->assertSame(1, PhrDicomUpload::query()->where('patient_id', $patientId)->where('status', PhrDicomUpload::STATUS_PROCESSED)->count());
        $this->assertSame(1, PhrDicomUpload::query()->where('patient_id', $patientId)->where('status', PhrDicomUpload::STATUS_FAILED)->count());
        Storage::disk(DicomUploadProcessor::DISK)->assertExists($originalFile->r2_key);
    }

    public function test_blank_relative_paths_fall_back_to_unique_original_filenames(): void
    {
        $this->fakeDicomDisk();

        $owner = $this->createUser();
        $manager = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $this->grantPatientAccess($owner, $patientId, $manager, 'manager');

        $uploadId = $this->openUpload($manager, $patientId, null);
        $this->postFile($manager, $patientId, $uploadId, UploadedFile::fake()->createWithContent('IM0001', $this->dicomBytes()), null)->assertOk();
        $this->postFile($manager, $patientId, $uploadId, UploadedFile::fake()->createWithContent('IM0001', $this->dicomBytes([
            'sop_instance_uid' => '1.2.840.113619.2.55.3.604688437.20260517.blank.2',
        ])), '   ')->assertOk();
        $this->finalizeUpload($manager, $patientId, $uploadId)
            ->assertOk()
            ->assertJsonPath('upload.stored_files', 2);

        $paths = PhrDicomFile::query()
            ->where('patient_id', $patientId)
            ->orderBy('id')
            ->pluck('original_relative_path')
            ->all();

        $this->assertSame(['IM0001', 'IM0001-2'], $paths);
    }

    public function test_failed_upload_removes_created_empty_studies_and_series(): void
    {
        $this->fakeDicomDisk();

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);
        $patient = PhrPatient::query()->findOrFail($patientId);
        $processor = $this->processorThatThrowsOnParseCall(2);

        try {
            $processor->process($patient, $owner->id, [
                UploadedFile::fake()->createWithContent('IM0001', $this->dicomBytes()),
                UploadedFile::fake()->createWithContent('IM0002', $this->dicomBytes([
                    'sop_instance_uid' => '1.2.840.113619.2.55.3.604688437.20260517.failure.2',
                ])),
            ], [
                'CARDIAC_CT/ST0001/SE0001/IM0001',
                'CARDIAC_CT/ST0001/SE0001/IM0002',
            ], 'CARDIAC_CT');

            $this->fail('Expected DICOM parsing to fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('Forced parser failure.', $error->getMessage());
        }

        $this->assertSame(0, PhrDicomFile::query()->where('patient_id', $patientId)->count());
        $this->assertSame(0, PhrDicomInstance::query()->where('patient_id', $patientId)->count());
        $this->assertSame(0, PhrDicomSeries::query()->where('patient_id', $patientId)->count());
        $this->assertSame(0, PhrDicomStudy::query()->where('patient_id', $patientId)->count());
        $this->assertSame(PhrDicomUpload::STATUS_FAILED, PhrDicomUpload::query()->where('patient_id', $patientId)->sole()->status);
        $this->assertSame([], Storage::disk(DicomUploadProcessor::DISK)->allFiles());
    }

    public function test_failed_duplicate_reupload_does_not_delete_existing_instance(): void
    {
        $this->fakeDicomDisk();

        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);

        $uploadId = $this->openUpload($owner, $patientId, null);
        $this->postFile($owner, $patientId, $uploadId, UploadedFile::fake()->createWithContent('IM0001', $this->dicomBytes()), 'CARDIAC_CT/ST0001/SE0001/IM0001')->assertOk();
        $this->finalizeUpload($owner, $patientId, $uploadId)->assertOk();

        $instance = PhrDicomInstance::query()->where('patient_id', $patientId)->sole();
        $originalUploadId = $instance->upload_id;
        $originalFileId = $instance->file_id;
        $patient = PhrPatient::query()->findOrFail($patientId);
        $processor = $this->processorThatThrowsOnParseCall(2);

        try {
            $processor->process($patient, $owner->id, [
                UploadedFile::fake()->createWithContent('IM0001-DUPLICATE', $this->dicomBytes()),
                UploadedFile::fake()->createWithContent('IM0002', $this->dicomBytes([
                    'sop_instance_uid' => '1.2.840.113619.2.55.3.604688437.20260517.failure.2',
                ])),
            ], [
                'CARDIAC_CT/ST0001/SE0001/IM0001-DUPLICATE',
                'CARDIAC_CT/ST0001/SE0001/IM0002',
            ], 'CARDIAC_CT');

            $this->fail('Expected DICOM parsing to fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('Forced parser failure.', $error->getMessage());
        }

        $instance->refresh();

        $this->assertSame($originalUploadId, $instance->upload_id);
        $this->assertSame($originalFileId, $instance->file_id);
        $this->assertSame(1, PhrDicomInstance::query()->where('patient_id', $patientId)->count());
        $this->assertSame(1, PhrDicomFile::query()->where('patient_id', $patientId)->count());
        $this->assertSame(1, PhrDicomUpload::query()->where('patient_id', $patientId)->where('status', PhrDicomUpload::STATUS_PROCESSED)->count());
        $this->assertSame(1, PhrDicomUpload::query()->where('patient_id', $patientId)->where('status', PhrDicomUpload::STATUS_FAILED)->count());
    }

    private function fakeDicomDisk(): void
    {
        Storage::fake(DicomUploadProcessor::DISK);
    }

    private function openUpload(User $actor, int $patientId, ?string $rootName): int
    {
        $payload = $rootName === null ? [] : ['root_name' => $rootName];
        $response = $this->actingAs($actor)
            ->postJson("/api/phr/patients/{$patientId}/dicom/uploads", $payload)
            ->assertCreated();

        return (int) $response->json('upload.id');
    }

    private function postFile(User $actor, int $patientId, int $uploadId, UploadedFile $file, ?string $relativePath): TestResponse
    {
        $payload = ['file' => $file];
        if ($relativePath !== null) {
            $payload['relative_path'] = $relativePath;
        }

        return $this->actingAs($actor)
            ->withHeader('Accept', 'application/json')
            ->post("/api/phr/patients/{$patientId}/dicom/uploads/{$uploadId}/files", $payload);
    }

    private function finalizeUpload(User $actor, int $patientId, int $uploadId): TestResponse
    {
        return $this->actingAs($actor)
            ->postJson("/api/phr/patients/{$patientId}/dicom/uploads/{$uploadId}/finalize");
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
     * @param  list<int>  $fileSizes
     */
    private function createIndexedStudy(User $owner, int $patientId, string $studyInstanceUid, string $studyDate, string $studyTime, array $fileSizes): PhrDicomStudy
    {
        $upload = PhrDicomUpload::create([
            'patient_id' => $patientId,
            'uploaded_by_user_id' => $owner->id,
            'status' => PhrDicomUpload::STATUS_PROCESSED,
            'stored_files' => count($fileSizes),
            'stored_bytes' => array_sum($fileSizes),
            'r2_prefix' => 'phr/dicom/patients/'.$patientId.'/uploads/'.$studyInstanceUid,
        ]);

        $study = PhrDicomStudy::create([
            'patient_id' => $patientId,
            'upload_id' => $upload->id,
            'study_instance_uid' => $studyInstanceUid,
            'study_date' => $studyDate,
            'study_time' => $studyTime,
            'description' => 'Indexed Study '.$studyInstanceUid,
            'modalities' => 'CT',
        ]);

        $series = PhrDicomSeries::create([
            'patient_id' => $patientId,
            'study_id' => $study->id,
            'series_instance_uid' => $studyInstanceUid.'.1',
            'modality' => 'CT',
        ]);

        foreach ($fileSizes as $index => $fileSize) {
            $relativePath = 'INDEXED/'.$studyInstanceUid.'/IM'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
            $file = PhrDicomFile::create([
                'patient_id' => $patientId,
                'upload_id' => $upload->id,
                'file_kind' => PhrDicomFile::KIND_DICOM,
                'r2_key' => $upload->r2_prefix.'/'.$relativePath,
                'original_relative_path' => $relativePath,
                'original_path_hash' => hash('sha256', $relativePath),
                'original_filename' => basename($relativePath),
                'mime_type' => 'application/dicom',
                'file_size_bytes' => $fileSize,
                'sha256' => hash('sha256', $studyInstanceUid.'-'.$index),
            ]);

            PhrDicomInstance::create([
                'patient_id' => $patientId,
                'study_id' => $study->id,
                'series_id' => $series->id,
                'upload_id' => $upload->id,
                'file_id' => $file->id,
                'sop_instance_uid' => $studyInstanceUid.'.1.'.($index + 1),
                'instance_number' => $index + 1,
            ]);
        }

        return $study;
    }

    private function processorThatThrowsOnParseCall(int $throwOnParseCall): DicomUploadProcessor
    {
        $parser = new class($throwOnParseCall) extends DicomMetadataParser
        {
            private int $parseCalls = 0;

            public function __construct(private readonly int $throwOnParseCall) {}

            /**
             * @return array{
             *     is_dicom: bool,
             *     has_preamble: bool,
             *     metadata: array<string, mixed>,
             *     normalized: array<string, mixed>,
             *     is_image_instance: bool
             * }
             */
            public function parse(string $path): array
            {
                $this->parseCalls++;

                if ($this->parseCalls === $this->throwOnParseCall) {
                    throw new RuntimeException('Forced parser failure.');
                }

                return parent::parse($path);
            }
        };

        return new DicomUploadProcessor($parser);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function dicomBytes(array $overrides = [], bool $includeUndefinedLengthSequence = false): string
    {
        $values = [
            'study_instance_uid' => '1.2.840.113619.2.55.3.604688437.20260517.1',
            'series_instance_uid' => '1.2.840.113619.2.55.3.604688437.20260517.1.1',
            'sop_instance_uid' => '1.2.840.113619.2.55.3.604688437.20260517.1.1.1',
            ...$overrides,
        ];

        return str_repeat("\0", 128).'DICM'
            .$this->element(0x0002, 0x0010, 'UI', '1.2.840.10008.1.2.1')
            .$this->element(0x0008, 0x0016, 'UI', '1.2.840.10008.5.1.4.1.1.2')
            .$this->element(0x0008, 0x0018, 'UI', $values['sop_instance_uid'])
            .$this->element(0x0008, 0x0020, 'DA', '20260517')
            .$this->element(0x0008, 0x0030, 'TM', '101112')
            .$this->element(0x0008, 0x0050, 'SH', 'ACC-1')
            .$this->element(0x0008, 0x0060, 'CS', 'CT')
            .$this->element(0x0008, 0x1030, 'LO', 'Cardiac CT')
            .$this->element(0x0008, 0x103E, 'LO', 'Axial')
            .($includeUndefinedLengthSequence ? $this->undefinedLengthProcedureCodeSequence() : '')
            .$this->element(0x0010, 0x0010, 'PN', 'Primary^Patient')
            .$this->element(0x0010, 0x0020, 'LO', 'PHR-1')
            .$this->element(0x0010, 0x0040, 'CS', 'O')
            .$this->element(0x0018, 0x0050, 'DS', '2.5')
            .$this->element(0x0020, 0x000D, 'UI', $values['study_instance_uid'])
            .$this->element(0x0020, 0x000E, 'UI', $values['series_instance_uid'])
            .$this->element(0x0020, 0x0011, 'IS', '1')
            .$this->element(0x0020, 0x0013, 'IS', '1')
            .$this->element(0x0020, 0x0032, 'DS', '0\\0\\0')
            .$this->element(0x0020, 0x0037, 'DS', '1\\0\\0\\0\\1\\0')
            .$this->element(0x0020, 0x0052, 'UI', '1.2.3.4.5')
            .$this->element(0x0028, 0x0002, 'US', pack('v', 1))
            .$this->element(0x0028, 0x0004, 'CS', 'MONOCHROME2')
            .$this->element(0x0028, 0x0010, 'US', pack('v', 512))
            .$this->element(0x0028, 0x0011, 'US', pack('v', 512))
            .$this->element(0x0028, 0x0030, 'DS', '0.7\\0.7')
            .$this->element(0x0028, 0x0100, 'US', pack('v', 16))
            .$this->element(0x0028, 0x0101, 'US', pack('v', 12))
            .$this->element(0x0028, 0x0102, 'US', pack('v', 11))
            .$this->element(0x0028, 0x0103, 'US', pack('v', 0));
    }

    private function undefinedLengthProcedureCodeSequence(): string
    {
        $itemPayload = $this->element(0x0008, 0x0100, 'SH', 'R-10208')
            .$this->element(0x0008, 0x0102, 'SH', 'SNM3')
            .$this->element(0x0008, 0x0104, 'LO', 'ANTERO-POSTERIOR OBLIQUE');

        return pack('v', 0x0008).pack('v', 0x1032).'SQ'."\0\0".pack('V', 0xFFFFFFFF)
            .pack('v', 0xFFFE).pack('v', 0xE000).pack('V', 0xFFFFFFFF)
            .$itemPayload
            .pack('v', 0xFFFE).pack('v', 0xE00D).pack('V', 0)
            .pack('v', 0xFFFE).pack('v', 0xE0DD).pack('V', 0);
    }

    private function dicomdirBytes(): string
    {
        return str_repeat("\0", 128).'DICM'.$this->element(0x0002, 0x0010, 'UI', '1.2.840.10008.1.2.1');
    }

    private function element(int $group, int $element, string $vr, string $value): string
    {
        $padding = $vr === 'UI' ? "\0" : ' ';
        if (strlen($value) % 2 === 1) {
            $value .= $padding;
        }

        return pack('v', $group).pack('v', $element).$vr.pack('v', strlen($value)).$value;
    }
}

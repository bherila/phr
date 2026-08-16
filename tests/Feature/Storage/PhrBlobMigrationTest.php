<?php

namespace Tests\Feature\Storage;

use App\Models\PhrDicomFile;
use App\Models\PhrDicomSeries;
use App\Models\PhrDicomStudy;
use App\Models\PhrDicomUpload;
use App\Models\PhrDocument;
use App\Models\PhrExport;
use App\Models\PhrNativeBackup;
use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\DICOM\DicomUploadProcessor;
use App\Services\PHR\NativeBackup\PhrNativeBackupCatalog;
use App\Support\Storage\PhrStorageKey;
use App\Support\Storage\PhrStorageMap;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class PhrBlobMigrationTest extends TestCase
{
    private const string UUID_NAMESPACE = 'c49e9b22-a4ad-5a87-a68f-2f0149be6770';

    public function test_document_migration_is_dry_run_by_default_then_applies_and_is_idempotent(): void
    {
        Storage::fake('phr_documents');
        [$owner, $patient] = $this->ownerAndPatient();
        $bytes = 'synthetic document bytes';
        $legacyKey = "phr/documents/patients/{$patient->id}/legacy/synthetic-record.pdf";
        Storage::disk('phr_documents')->put($legacyKey, $bytes);
        $document = $this->document($patient, $owner, $legacyKey, $bytes);

        $this->artisan('phr:storage:migrate-keys', ['--artifact' => 'documents'])
            ->expectsOutputToContain("reference=phr_documents#{$document->id} status=planned")
            ->doesntExpectOutputToContain('synthetic-record.pdf')
            ->assertSuccessful();

        $this->assertSame($legacyKey, $document->refresh()->storage_path);
        $this->assertSame([$legacyKey], Storage::disk('phr_documents')->allFiles());

        $this->artisan('phr:storage:migrate-keys', ['--artifact' => 'documents', '--apply' => true])
            ->expectsOutputToContain("reference=phr_documents#{$document->id} status=migrated")
            ->doesntExpectOutputToContain('synthetic-record.pdf')
            ->assertSuccessful();

        $document->refresh();
        $this->assertMatchesRegularExpression(
            '#^patients/'.$patient->id.'/documents/[0-9a-f-]{36}/synthetic-record\.pdf$#',
            (string) $document->storage_path,
        );
        Storage::disk('phr_documents')->assertExists($legacyKey);
        Storage::disk('phr_documents')->assertExists((string) $document->storage_path);
        $this->assertSame($bytes, Storage::disk('phr_documents')->get((string) $document->storage_path));
        $this->assertDatabaseHas('phr_blob_migrations', [
            'patient_id' => $patient->id,
            'artifact_class' => 'documents',
            'storage_disk' => 'phr_documents',
            'reference_table' => 'phr_documents',
            'reference_id' => $document->id,
            'reference_column' => 'storage_path',
            'source_key' => $legacyKey,
            'destination_key' => $document->storage_path,
            'source_size_bytes' => strlen($bytes),
            'source_sha256' => hash('sha256', $bytes),
            'legacy_deleted_at' => null,
        ]);
        $this->assertArrayHasKey($legacyKey, PhrStorageMap::references()->referencedKeys());

        $response = $this->actingAs($owner)
            ->get("/api/phr/patients/{$patient->id}/documents/{$document->id}/file")
            ->assertOk();
        $this->assertSame($bytes, $response->streamedContent());

        $this->artisan('phr:storage:migrate-keys', ['--artifact' => 'documents', '--apply' => true])
            ->expectsOutputToContain("reference=phr_documents#{$document->id} status=already_canonical")
            ->assertSuccessful();
        $this->assertCount(2, Storage::disk('phr_documents')->allFiles());
    }

    public function test_patient_scope_does_not_touch_another_patient(): void
    {
        Storage::fake('phr_documents');
        [$firstOwner, $firstPatient] = $this->ownerAndPatient();
        [$secondOwner, $secondPatient] = $this->ownerAndPatient();
        $first = $this->legacyDocument($firstPatient, $firstOwner, 'first');
        $second = $this->legacyDocument($secondPatient, $secondOwner, 'second');

        $this->artisan('phr:storage:migrate-keys', [
            '--artifact' => 'documents',
            '--patient' => (string) $firstPatient->id,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertStringStartsWith("patients/{$firstPatient->id}/documents/", (string) $first->refresh()->storage_path);
        $this->assertStringStartsWith('phr/documents/', (string) $second->refresh()->storage_path);
    }

    public function test_collision_and_source_metadata_mismatch_leave_references_and_legacy_bytes_unchanged(): void
    {
        Storage::fake('phr_documents');
        [$owner, $patient] = $this->ownerAndPatient();
        $bytes = 'synthetic source bytes';
        $legacyKey = "phr/documents/patients/{$patient->id}/legacy/collision.pdf";
        Storage::disk('phr_documents')->put($legacyKey, $bytes);
        $document = $this->document($patient, $owner, $legacyKey, $bytes);
        $destination = PhrStorageKey::document(
            (int) $patient->id,
            Uuid::uuid5(self::UUID_NAMESPACE, 'phr_documents:'.$document->id)->toString(),
            'collision.pdf',
        );
        Storage::disk('phr_documents')->put($destination, 'different destination bytes');

        $this->artisan('phr:storage:migrate-keys', ['--artifact' => 'documents', '--apply' => true])
            ->expectsOutputToContain("reference=phr_documents#{$document->id} status=destination_collision")
            ->doesntExpectOutputToContain('collision.pdf')
            ->assertFailed();

        $this->assertSame($legacyKey, $document->refresh()->storage_path);
        $this->assertSame($bytes, Storage::disk('phr_documents')->get($legacyKey));
        $this->assertSame('different destination bytes', Storage::disk('phr_documents')->get($destination));

        Storage::disk('phr_documents')->delete($destination);
        $document->update(['file_hash' => hash('sha256', 'not the source')]);
        $this->artisan('phr:storage:migrate-keys', ['--artifact' => 'documents', '--apply' => true])
            ->expectsOutputToContain("reference=phr_documents#{$document->id} status=source_mismatch")
            ->assertFailed();

        $this->assertSame($legacyKey, $document->refresh()->storage_path);
        Storage::disk('phr_documents')->assertMissing($destination);
        $this->assertSame($bytes, Storage::disk('phr_documents')->get($legacyKey));
    }

    public function test_compare_and_swap_does_not_overwrite_a_concurrently_changed_reference(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $bytes = 'synthetic concurrent bytes';
        $legacyKey = "phr/documents/patients/{$patient->id}/legacy/concurrent.pdf";
        $document = $this->document($patient, $owner, $legacyKey, $bytes);
        $concurrentKey = 'concurrent/synthetic-reference.pdf';
        $state = new class
        {
            public bool $copied = false;

            public string $destination = '';
        };

        $disk = $this->callbackDisk(
            fn (string $key): bool => $key === $legacyKey || ($state->copied && $key === $state->destination),
            fn (): mixed => $this->stream($bytes),
            function (string $source, string $target) use ($document, $legacyKey, $concurrentKey, $state): bool {
                $this->assertSame($legacyKey, $source);
                $state->destination = $target;
                $state->copied = true;
                DB::table('phr_documents')->where('id', $document->id)->update(['storage_path' => $concurrentKey]);

                return true;
            },
            fn (): bool => throw new \LogicException('The stale-reference path must not delete either object.'),
        );
        Storage::set('phr_documents', $disk);

        $this->artisan('phr:storage:migrate-keys', ['--artifact' => 'documents', '--apply' => true])
            ->expectsOutputToContain("reference=phr_documents#{$document->id} status=stale_reference")
            ->doesntExpectOutputToContain($concurrentKey)
            ->assertFailed();

        $this->assertSame($concurrentKey, $document->refresh()->storage_path);
        $this->assertDatabaseMissing('phr_blob_migrations', ['reference_id' => $document->id]);
    }

    public function test_copy_hash_mismatch_deletes_failed_destination_and_keeps_source_reference(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $bytes = 'synthetic verified source';
        $legacyKey = "phr/documents/patients/{$patient->id}/legacy/hash-mismatch.pdf";
        $document = $this->document($patient, $owner, $legacyKey, $bytes);
        $state = new class
        {
            public bool $copied = false;

            public string $destination = '';
        };

        $disk = $this->callbackDisk(
            fn (string $key): bool => $key === $legacyKey || ($state->copied && $key === $state->destination),
            fn (string $key): mixed => $this->stream($key === $legacyKey ? $bytes : 'corrupted copied bytes'),
            function (string $source, string $target) use ($state): bool {
                $state->copied = true;
                $state->destination = $target;

                return true;
            },
            function (string $key) use ($state): bool {
                $this->assertSame($state->destination, $key);
                $state->copied = false;

                return true;
            },
        );
        Storage::set('phr_documents', $disk);

        $this->artisan('phr:storage:migrate-keys', ['--artifact' => 'documents', '--apply' => true])
            ->expectsOutputToContain("reference=phr_documents#{$document->id} status=copy_mismatch")
            ->doesntExpectOutputToContain('hash-mismatch.pdf')
            ->assertFailed();

        $this->assertFalse($state->copied);
        $this->assertSame($legacyKey, $document->refresh()->storage_path);
        $this->assertDatabaseMissing('phr_blob_migrations', ['reference_id' => $document->id]);
    }

    public function test_failed_post_swap_readback_rolls_reference_and_ledger_back(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $bytes = 'synthetic readback source';
        $legacyKey = "phr/documents/patients/{$patient->id}/legacy/readback.pdf";
        $document = $this->document($patient, $owner, $legacyKey, $bytes);
        $state = new class
        {
            public bool $copied = false;

            public string $destination = '';

            public int $destinationReads = 0;
        };

        $disk = $this->callbackDisk(
            fn (string $key): bool => $key === $legacyKey || ($state->copied && $key === $state->destination),
            function (string $key) use ($legacyKey, $bytes, $state): mixed {
                if ($key === $legacyKey) {
                    return $this->stream($bytes);
                }

                $state->destinationReads++;

                return $this->stream($state->destinationReads === 1 ? $bytes : 'failed readback bytes');
            },
            function (string $source, string $target) use ($state): bool {
                $state->copied = true;
                $state->destination = $target;

                return true;
            },
            fn (): bool => throw new \LogicException('A verified destination is retained for diagnosis.'),
        );
        Storage::set('phr_documents', $disk);

        $this->artisan('phr:storage:migrate-keys', ['--artifact' => 'documents', '--apply' => true])
            ->expectsOutputToContain("reference=phr_documents#{$document->id} status=readback_failed")
            ->doesntExpectOutputToContain('readback.pdf')
            ->assertFailed();

        $this->assertSame(2, $state->destinationReads);
        $this->assertSame($legacyKey, $document->refresh()->storage_path);
        $this->assertDatabaseMissing('phr_blob_migrations', ['reference_id' => $document->id]);
    }

    public function test_migrates_exports_native_backups_and_both_dicom_artifact_classes(): void
    {
        Storage::fake('phr_documents');
        Storage::fake('phr_exports');
        Storage::fake(DicomUploadProcessor::DISK);
        [$owner, $patient] = $this->ownerAndPatient();

        $exportBytes = 'synthetic export bytes';
        $exportKey = 'phr/exports/legacy/synthetic-export.json';
        Storage::disk('phr_exports')->put($exportKey, $exportBytes);
        $export = PhrExport::create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'requested_by_user_id' => $owner->id,
            'format' => 'fhir',
            'status' => PhrExport::STATUS_READY,
            'storage_disk' => 'phr_exports',
            'storage_path' => $exportKey,
            'filename' => 'synthetic-export.json',
            'file_size_bytes' => strlen($exportBytes),
        ]);

        $backupBytes = 'synthetic native archive';
        $backupKey = 'phr/native-backups/legacy/synthetic.zip';
        Storage::disk('phr_exports')->put($backupKey, $backupBytes);
        $backup = PhrNativeBackup::create([
            'patient_id' => $patient->id,
            'requested_by_user_id' => $owner->id,
            'status' => PhrNativeBackup::STATUS_READY,
            'schema_version' => PhrNativeBackupCatalog::SCHEMA_VERSION,
            'storage_disk' => 'phr_exports',
            'storage_path' => $backupKey,
            'file_size_bytes' => strlen($backupBytes),
            'archive_sha256' => hash('sha256', $backupBytes),
        ]);

        $upload = PhrDicomUpload::create([
            'patient_id' => $patient->id,
            'uploaded_by_user_id' => $owner->id,
            'status' => PhrDicomUpload::STATUS_PROCESSED,
            'stored_files' => 1,
            'r2_prefix' => "phr/dicom/patients/{$patient->id}/uploads/legacy",
        ]);
        $dicomBytes = 'synthetic dicom bytes';
        $dicomKey = $upload->r2_prefix.'/STUDY/IMAGE0001.dcm';
        Storage::disk(DicomUploadProcessor::DISK)->put($dicomKey, $dicomBytes);
        $dicom = $this->dicomFile($patient, $upload, $dicomKey, 'STUDY/IMAGE0001.dcm', $dicomBytes);

        $study = PhrDicomStudy::create([
            'patient_id' => $patient->id,
            'upload_id' => $upload->id,
            'study_instance_uid' => '1.2.840.10008.synthetic.study',
        ]);
        $series = PhrDicomSeries::create([
            'patient_id' => $patient->id,
            'study_id' => $study->id,
            'series_instance_uid' => '1.2.840.10008.synthetic.series',
        ]);
        $derivedBytes = "\x1f\x8bsynthetic derived bytes";
        $derivedKey = "derived/volume-cache/patients/{$patient->id}/series/{$series->id}/v1.bin.gz";
        Storage::disk(DicomUploadProcessor::DISK)->put($derivedKey, $derivedBytes);
        $derived = $this->dicomFile(
            $patient,
            $upload,
            $derivedKey,
            $derivedKey,
            $derivedBytes,
            PhrDicomFile::KIND_DERIVED_VOLUME,
            ['kind' => 'volume_cache', 'series_id' => $series->id, 'pipeline_version' => 1],
        );

        $this->artisan('phr:storage:migrate-keys', ['--apply' => true])
            ->doesntExpectOutputToContain('synthetic-export.json')
            ->doesntExpectOutputToContain('IMAGE0001.dcm')
            ->assertSuccessful();

        $this->assertStringStartsWith("patients/{$patient->id}/exports/", (string) $export->refresh()->storage_path);
        $this->assertStringStartsWith("patients/{$patient->id}/exports/", (string) $backup->refresh()->storage_path);
        $this->assertStringStartsWith("patients/{$patient->id}/imaging/dicom/uploads/", (string) $upload->refresh()->r2_prefix);
        $this->assertStringStartsWith($upload->r2_prefix.'/', (string) $dicom->refresh()->r2_key);
        $this->assertSame(
            "patients/{$patient->id}/imaging/dicom/derived/series/{$series->id}/v1.bin.gz",
            $derived->refresh()->r2_key,
        );

        $exportDownload = $this->actingAs($owner)
            ->get(URL::temporarySignedRoute('phr.exports.download', now()->addMinute(), ['export' => $export->id]))
            ->assertOk();
        $this->assertSame($exportBytes, $exportDownload->streamedContent());
        $backupDownload = $this->actingAs($owner)
            ->get(URL::temporarySignedRoute('phr.native-backups.download', now()->addMinute(), ['backup' => $backup->id]))
            ->assertOk();
        $this->assertSame($backupBytes, $backupDownload->streamedContent());
        $volumeDownload = $this->actingAs($owner)
            ->get("/api/phr/patients/{$patient->id}/dicom/series/{$series->id}/volume-cache")
            ->assertOk();
        $this->assertSame($derivedBytes, $volumeDownload->streamedContent());

        foreach ([$exportKey, $backupKey] as $legacy) {
            Storage::disk('phr_exports')->assertExists($legacy);
        }
        foreach ([$dicomKey, $derivedKey] as $legacy) {
            Storage::disk(DicomUploadProcessor::DISK)->assertExists($legacy);
        }

        // The scheduled collector must honor the rollback ledger rather than
        // deleting repointed legacy objects as apparent orphans.
        $this->artisan('phr:dicom:gc')->assertSuccessful();
        foreach ([$dicomKey, $derivedKey] as $legacy) {
            Storage::disk(DicomUploadProcessor::DISK)->assertExists($legacy);
        }
    }

    public function test_pending_dicom_uploads_and_cross_patient_canonical_references_fail_safe(): void
    {
        Storage::fake('phr_documents');
        Storage::fake(DicomUploadProcessor::DISK);
        [$owner, $patient] = $this->ownerAndPatient();
        [, $otherPatient] = $this->ownerAndPatient();

        $foreignKey = "patients/{$otherPatient->id}/documents/018f1f3a-6d18-7f42-a780-5dd94c10f312/synthetic.pdf";
        Storage::disk('phr_documents')->put($foreignKey, 'synthetic bytes');
        $document = $this->document($patient, $owner, $foreignKey, 'synthetic bytes');
        $this->artisan('phr:storage:migrate-keys', ['--artifact' => 'documents', '--apply' => true])
            ->expectsOutputToContain("reference=phr_documents#{$document->id} status=invalid_reference")
            ->assertFailed();
        $this->assertSame($foreignKey, $document->refresh()->storage_path);

        $upload = PhrDicomUpload::create([
            'patient_id' => $patient->id,
            'uploaded_by_user_id' => $owner->id,
            'status' => PhrDicomUpload::STATUS_PENDING,
            'stored_files' => 1,
            'r2_prefix' => "phr/dicom/patients/{$patient->id}/uploads/in-flight",
        ]);
        $key = $upload->r2_prefix.'/IMAGE0001.dcm';
        Storage::disk(DicomUploadProcessor::DISK)->put($key, 'synthetic dicom');
        $file = $this->dicomFile($patient, $upload, $key, 'IMAGE0001.dcm', 'synthetic dicom');

        $this->artisan('phr:storage:migrate-keys', ['--artifact' => 'dicom-originals', '--apply' => true])
            ->expectsOutputToContain("reference=phr_dicom_files#{$file->id} status=active_upload")
            ->expectsOutputToContain("reference=phr_dicom_uploads#{$upload->id} status=active_upload")
            ->assertSuccessful();

        $this->assertSame($key, $file->refresh()->r2_key);
        $this->assertStringStartsWith('phr/dicom/', (string) $upload->refresh()->r2_prefix);
    }

    public function test_rejects_invalid_or_incompatible_scopes(): void
    {
        $this->artisan('phr:storage:migrate-keys', ['--artifact' => 'unknown'])
            ->assertExitCode(2);
        $this->artisan('phr:storage:migrate-keys', [
            '--disk' => 'phr_documents',
            '--artifact' => 'exports',
        ])->assertExitCode(2);
        $this->artisan('phr:storage:migrate-keys', ['--patient' => '0'])
            ->assertExitCode(2);
    }

    /** @return array{User, PhrPatient} */
    private function ownerAndPatient(): array
    {
        $owner = $this->createUser();
        $patient = PhrPatient::create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Synthetic Patient',
            'relationship' => 'self',
        ]);

        return [$owner, $patient];
    }

    private function legacyDocument(PhrPatient $patient, User $owner, string $token): PhrDocument
    {
        $bytes = "synthetic {$token} bytes";
        $key = "phr/documents/patients/{$patient->id}/legacy/{$token}.pdf";
        Storage::disk('phr_documents')->put($key, $bytes);

        return $this->document($patient, $owner, $key, $bytes);
    }

    private function document(PhrPatient $patient, User $owner, string $key, string $bytes): PhrDocument
    {
        return PhrDocument::create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
            'title' => 'Synthetic document',
            'document_type' => 'other',
            'original_filename' => basename($key),
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => $key,
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'file_hash' => hash('sha256', $bytes),
        ]);
    }

    /** @param array<string, mixed>|null $metadata */
    private function dicomFile(
        PhrPatient $patient,
        PhrDicomUpload $upload,
        string $key,
        string $relativePath,
        string $bytes,
        string $kind = PhrDicomFile::KIND_DICOM,
        ?array $metadata = null,
    ): PhrDicomFile {
        return PhrDicomFile::create([
            'patient_id' => $patient->id,
            'upload_id' => $upload->id,
            'file_kind' => $kind,
            'r2_key' => $key,
            'original_relative_path' => $relativePath,
            'original_path_hash' => hash('sha256', $relativePath),
            'original_filename' => basename($relativePath),
            'mime_type' => $kind === PhrDicomFile::KIND_DERIVED_VOLUME ? 'application/gzip' : 'application/dicom',
            'file_size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'metadata_json' => $metadata,
        ]);
    }

    /** @return resource */
    private function stream(string $bytes): mixed
    {
        $stream = fopen('php://temp', 'w+b');
        if (! is_resource($stream)) {
            $this->fail('Unable to create a synthetic test stream.');
        }
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    private function callbackDisk(
        \Closure $exists,
        \Closure $readStream,
        \Closure $copy,
        \Closure $delete,
    ): FilesystemAdapter {
        return new class($exists, $readStream, $copy, $delete) extends FilesystemAdapter
        {
            public function __construct(
                private readonly \Closure $existsCallback,
                private readonly \Closure $readStreamCallback,
                private readonly \Closure $copyCallback,
                private readonly \Closure $deleteCallback,
            ) {}

            public function exists($path)
            {
                return ($this->existsCallback)((string) $path);
            }

            public function readStream($path)
            {
                return ($this->readStreamCallback)((string) $path);
            }

            public function copy($from, $to)
            {
                return ($this->copyCallback)((string) $from, (string) $to);
            }

            /** @param string|array<int, string> $paths */
            public function delete($paths)
            {
                if (! is_string($paths)) {
                    throw new \LogicException('The migration deletes at most one failed destination.');
                }

                return ($this->deleteCallback)($paths);
            }
        };
    }
}

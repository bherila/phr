<?php

namespace Tests\Support;

use App\Models\PhrDocument;
use App\Models\PhrNativeRecordIdentity;
use App\Models\User;
use App\Services\PHR\NativeBackup\PhrNativeBackupCatalog;
use App\Services\PHR\NativeBackup\PhrNativeRecordCodec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Test-only proof reader for R2. Production restore remains Phase 4; this class exists
 * solely to prove that v1 contains enough typed data, relationships, identities, and
 * artifact bytes to rebuild the graph without reusing database ids.
 */
final class PhrNativeBackupTestReader
{
    private ZipArchive $zip;

    /** @var array<string, mixed> */
    private array $manifest;

    public function __construct(string $archivePath)
    {
        $this->zip = new ZipArchive;
        if ($this->zip->open($archivePath) !== true) {
            throw new RuntimeException('Unable to open test archive.');
        }

        $manifest = $this->zip->getFromName('manifest.json');
        if (! is_string($manifest)) {
            throw new RuntimeException('Test archive has no manifest.');
        }
        $decoded = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Test archive manifest is invalid.');
        }
        $this->manifest = $decoded;
    }

    public function __destruct()
    {
        $this->zip->close();
    }

    public function restore(User $owner, bool $restoreAccessGrants = true): int
    {
        if (($this->manifest['format'] ?? null) !== PhrNativeBackupCatalog::FORMAT
            || ($this->manifest['schemaVersion'] ?? null) !== PhrNativeBackupCatalog::SCHEMA_VERSION) {
            throw new RuntimeException('Unsupported test archive version.');
        }

        $records = $this->records();
        $actorIds = $this->actorIds($records, $owner);
        $newIds = [];
        $restoredPatientId = 0;
        $artifacts = collect($this->manifest['artifacts'] ?? [])->keyBy('recordNativeId');

        DB::transaction(function () use ($records, $actorIds, $artifacts, $restoreAccessGrants, &$newIds, &$restoredPatientId): void {
            foreach (PhrNativeBackupCatalog::included() as $table => $definition) {
                if ($table === 'phr_patient_user_access' && ! $restoreAccessGrants) {
                    continue;
                }

                foreach ($records[$table] as $record) {
                    $data = [];
                    foreach ($record['attributes'] as $column => $value) {
                        $data[$column] = PhrNativeRecordCodec::decodeValue($value);
                    }
                    foreach ($record['relationships'] as $column => $relationship) {
                        if ($relationship === null) {
                            $data[$column] = null;

                            continue;
                        }
                        $nativeId = (string) $relationship['nativeId'];
                        $data[$column] = $relationship['kind'] === 'actor'
                            ? $actorIds[$nativeId]
                            : $newIds[$relationship['table']][$nativeId];
                    }

                    $this->restoreArtifactColumns($table, $record, $data, $artifacts->get($record['nativeId']));
                    $newId = (int) DB::table($table)->insertGetId($data);
                    $newIds[$table][$record['nativeId']] = $newId;
                    if ($table === 'phr_patients') {
                        $restoredPatientId = $newId;
                    }

                    PhrNativeRecordIdentity::query()->create([
                        'patient_id' => $restoredPatientId,
                        'record_table' => $table,
                        'record_id' => $newId,
                        'native_id' => $record['nativeId'],
                    ]);
                }
            }

            foreach ($actorIds as $nativeId => $userId) {
                PhrNativeRecordIdentity::query()->create([
                    'patient_id' => $restoredPatientId,
                    'record_table' => 'users',
                    'record_id' => $userId,
                    'native_id' => $nativeId,
                ]);
            }
        });

        return $restoredPatientId;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function records(): array
    {
        $records = [];
        foreach ($this->manifest['tables'] as $table => $tableManifest) {
            $contents = $this->zip->getFromName($tableManifest['path']);
            if (! is_string($contents) || ! hash_equals($tableManifest['sha256'], hash('sha256', $contents))) {
                throw new RuntimeException('Test archive record hash mismatch.');
            }

            $records[$table] = [];
            foreach (preg_split('/\r?\n/', trim($contents)) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $content = [
                    'attributes' => $record['attributes'],
                    'relationships' => $record['relationships'],
                ];
                if (! hash_equals($record['contentHash'], hash('sha256', PhrNativeRecordCodec::canonicalJson($content)))) {
                    throw new RuntimeException('Test archive content hash mismatch.');
                }
                $records[$table][] = $record;
            }

            if (count($records[$table]) !== $tableManifest['count']) {
                throw new RuntimeException('Test archive record count mismatch.');
            }
        }

        return $records;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $records
     * @return array<string, int>
     */
    private function actorIds(array $records, User $owner): array
    {
        $patientRecord = $records['phr_patients'][0] ?? throw new RuntimeException('Test archive has no patient root.');
        $ownerNativeId = (string) $patientRecord['relationships']['owner_user_id']['nativeId'];
        $actors = [$ownerNativeId => (int) $owner->id];

        foreach ($records as $tableRecords) {
            foreach ($tableRecords as $record) {
                foreach ($record['relationships'] as $relationship) {
                    if (($relationship['kind'] ?? null) !== 'actor') {
                        continue;
                    }
                    $nativeId = (string) $relationship['nativeId'];
                    if (isset($actors[$nativeId])) {
                        continue;
                    }
                    $actor = User::factory()->create([
                        'email' => 'restored-actor-'.substr(hash('sha256', $nativeId), 0, 16).'@example.test',
                    ]);
                    $actors[$nativeId] = (int) $actor->id;
                }
            }
        }

        return $actors;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $artifact
     */
    private function restoreArtifactColumns(string $table, array $record, array &$data, ?array $artifact): void
    {
        if ($table === 'phr_documents') {
            $data['genai_job_id'] = null;
            $data['storage_disk'] = PhrDocument::STORAGE_DISK;
            $data['storage_path'] = $artifact === null ? null : 'phr/documents/restored/'.$record['nativeId'].'.blob';
        } elseif ($table === 'phr_dicom_uploads') {
            $data['r2_prefix'] = 'phr/dicom/restored/'.$record['nativeId'].'/';
            $data['error_message'] = null;
        } elseif ($table === 'phr_dicom_files') {
            $data['r2_key'] = 'phr/dicom/restored/'.$record['nativeId'].'.blob';
        }

        if ($artifact === null) {
            return;
        }

        $disk = $table === 'phr_documents' ? PhrDocument::STORAGE_DISK : 'phr_dicom';
        $storagePath = $table === 'phr_documents' ? $data['storage_path'] : $data['r2_key'];
        $stream = $this->zip->getStream($artifact['path']);
        if (! is_resource($stream)) {
            throw new RuntimeException('Test archive artifact is missing.');
        }
        try {
            $context = hash_init('sha256');
            $temp = tmpfile();
            if ($temp === false) {
                throw new RuntimeException('Unable to create test artifact stream.');
            }
            try {
                $size = 0;
                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);
                    if ($chunk === false) {
                        throw new RuntimeException('Unable to read test artifact.');
                    }
                    $size += strlen($chunk);
                    hash_update($context, $chunk);
                    fwrite($temp, $chunk);
                }
                if ($size !== $artifact['size'] || ! hash_equals($artifact['sha256'], hash_final($context))) {
                    throw new RuntimeException('Test archive artifact hash mismatch.');
                }
                rewind($temp);
                if (! Storage::disk($disk)->put($storagePath, $temp)) {
                    throw new RuntimeException('Unable to restore test artifact.');
                }
            } finally {
                fclose($temp);
            }
        } finally {
            fclose($stream);
        }
    }
}

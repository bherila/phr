<?php

namespace Tests\Support;

use App\Models\PhrEhiProposal;
use App\Models\PhrNativeBackup;
use App\Models\PhrNativeRestoreAttempt;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\DataHub\PhrPatientDeletionCleanupService;
use App\Services\PHR\DataHub\PhrPatientDeletionPlan;
use App\Services\PHR\DataHub\PhrPatientDeletionService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\NativeBackup\NativeBackupException;
use App\Services\PHR\NativeBackup\NativeRestoreException;
use App\Services\PHR\NativeBackup\PhrNativeArchiveBuilder;
use App\Services\PHR\NativeBackup\PhrNativeBackupService;
use App\Services\PHR\NativeBackup\PhrNativeRestoreArchiveReader;
use App\Services\PHR\NativeBackup\PhrNativeRestorePlanner;
use App\Services\PHR\NativeBackup\PhrNativeRestoreService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/** Synthetic-only lifecycle observation; no clinical content leaves the private fixture. */
final class EhiCapacityLifecycle
{
    public function run(PhrPatient $patient, int $actor, int $history, array $options = []): array
    {
        $result = ['status' => 'completed', 'stages' => [], 'round_trip_verified' => false];
        $metrics = $options['metrics'] ?? new EhiCapacityMetrics;
        $activePatientId = (int) $patient->id;
        $stage = function (string $name, callable $operation) use ($metrics, &$activePatientId): mixed {
            return $metrics->stage($name, $operation, $activePatientId);
        };
        $download = null;
        $inspected = null;
        $inspectionPath = null;
        $rebuilt = null;
        try {
            // A refused preview cannot authorize review or claim lifecycle readiness.
            $package = DB::table('phr_ehi_packages')->where('patient_id', $patient->id)->sole();
            if ($package->preview_generation_started < 1 || $package->preview_generation_started !== $package->preview_generation_completed) {
                $result['status'] = 'preview_resource_limit';

                $result['stages'] = $metrics->stages;

                return $result;
            }
            $stage('review', function () use ($patient, $actor, $history, $options): void {
                if (($options['review'] ?? 'two') === 'full') {
                    $this->reviewAll($patient, $actor);
                } else {
                    $proposals = PhrEhiProposal::query()->where('patient_id', $patient->id)->where('mapping', 'lab_result')->orderBy('id')->limit(2)->get();
                    if ($proposals->count() !== 2) {
                        throw new RuntimeException('Synthetic review proposals missing.');
                    }
                    foreach ([PhrEhiProposal::DECISION_ACCEPT, PhrEhiProposal::DECISION_REJECT] as $index => $decision) {
                        $proposal = $proposals[$index];
                        app(EhiProposalService::class)->review($proposal, $decision, $actor, $proposal->preview_digest);
                    }
                    if (DB::table('phr_lab_results')->where('patient_id', $patient->id)->count() !== $history + 1) {
                        throw new RuntimeException('Synthetic review changed unexpected clinical rows.');
                    }
                }
            });
            if (isset($options['after_initial_review'])) {
                $options['after_initial_review']($patient, $stage);
            }
            if (isset($options['exercise'])) {
                $options['exercise']($patient, $stage);
            }
            if (isset($options['artifact_target'])) {
                $count = DB::table('phr_ehi_blobs')->where('patient_id', $patient->id)->count()
                    + DB::table('phr_documents')->where('patient_id', $patient->id)->whereNotNull('storage_path')->count();
                if ($count !== $options['artifact_target']) {
                    throw new RuntimeException('Synthetic artifact candidate count differs from its declaration.');
                }
                $result['candidate_artifacts'] = $count;
            }
            $before = $this->reviewState((int) $patient->id);
            $accessBefore = $this->accessState($patient, $actor);
            if (($options['native_boundary'] ?? null) !== null) {
                $count = $metrics->durable((int) $patient->id)['rows'];
                config(['phr.native_max_records' => $count - ($options['native_boundary'] === 'over' ? 1 : 0)]);
                $result['boundary_native_records'] = config('phr.native_max_records');
            }
            $backup = $stage('backup', function () use ($patient, $actor): PhrNativeBackup {
                $service = app(PhrNativeBackupService::class);

                return $service->generate($service->createQueuedBackup($patient, $actor));
            });
            $download = $stage('download', function () use ($backup, $options): string {
                $path = isset($options['directory']) ? $options['directory'].'/independent-recovery.zip' : tempnam(sys_get_temp_dir(), 'phr-capacity-download-');
                $source = Storage::disk($backup->storage_disk)->readStream($backup->storage_path);
                $target = fopen($path, 'wb');
                if (! is_resource($source) || ! is_resource($target)) {
                    throw new RuntimeException('Synthetic independent download could not open.');
                }
                try {
                    stream_copy_to_stream($source, $target);
                } finally {
                    fclose($source);
                    fclose($target);
                }
                if (! hash_equals($backup->archive_sha256, hash_file('sha256', $path))) {
                    throw new RuntimeException('Synthetic independent download differs.');
                }

                return $path;
            });
            $inspectionPath = tempnam(sys_get_temp_dir(), 'phr-capacity-inspect-');
            copy($download, $inspectionPath);
            $inspected = app(PhrNativeRestoreArchiveReader::class)->openLocal($inspectionPath);
            $result['archive_records'] = array_sum($backup->counts_json);
            $result['archive_bytes'] = $backup->file_size_bytes;
            $fingerprint = $stage('fingerprint_before', fn () => $this->fingerprint($download, $inspected->manifest));
            $result['record_stream_bytes'] = $fingerprint['record_stream_bytes'];
            if (($options['sharing'] ?? false) === true) {
                $service = app(PhrNativeRestoreService::class);
                $optIn = $stage('existing_opt_in_preview', fn () => $this->previewArchive($download, $actor, true));
                if (($optIn->plan_counts_json['blockers'] ?? []) !== [] || $optIn->access_grant_count !== count($accessBefore['shares'])) {
                    throw new RuntimeException('Existing-patient explicit share restore was not admitted.');
                }
                $stage('existing_opt_in_apply', fn () => $service->apply($optIn));
                if ($this->accessState($patient, $actor) !== $accessBefore) {
                    throw new RuntimeException('Explicit existing-patient restore changed sharing semantics.');
                }
                $result['existing_opt_in_shares_preserved'] = true;
            }

            $knownArtifacts = $this->snapshotKnownArtifacts($patient, $backup);
            if ($knownArtifacts === []) {
                throw new RuntimeException('Synthetic known managed references missing.');
            }

            $plan = app(PhrPatientDeletionService::class)->preview($patient);
            if ($plan->blockers !== [] || $plan->artifacts === []) {
                throw new RuntimeException('Synthetic deletion plan is not usable.');
            }
            if (isset($options['omit_plan_artifact_family'])) {
                $family = $options['omit_plan_artifact_family'];
                $filteredArtifacts = array_values(array_filter($plan->artifacts, function (array $art) use ($backup, $family): bool {
                    if ($family === 'backup') {
                        return ! ($art['disk'] === $backup->storage_disk && $art['key'] === $backup->storage_path);
                    }
                    if ($family === 'blob') {
                        return ! (str_contains($art['key'], 'blob') || str_contains($art['key'], 'ehi'));
                    }
                    if ($family === 'document') {
                        return ! str_contains($art['key'], 'document');
                    }

                    return true;
                }));
                $plan = new PhrPatientDeletionPlan(
                    $plan->patientId,
                    $plan->recordCounts,
                    $plan->activeShareCount,
                    $filteredArtifacts,
                    array_sum(array_column($filteredArtifacts, 'bytes')),
                    $plan->blockers,
                    $plan->digest,
                );
            }
            $this->assertPlanCoversArtifacts($plan, $knownArtifacts);
            $stage('delete', function () use ($patient, $actor, $plan, $download, $backup, $knownArtifacts, $options, &$result): void {
                $deleted = app(PhrPatientDeletionService::class)->delete($patient, $actor, $plan->digest, true);
                app(PhrPatientDeletionCleanupService::class)->cleanup($deleted->id);
                if (($options['negative_control_surviving_artifact'] ?? false) === true) {
                    Storage::disk($backup->storage_disk)->put($backup->storage_path, 'synthetic-surviving-object');
                }
                $this->assertArtifactsAbsent($knownArtifacts, $backup);
                foreach ($plan->artifacts as $artifact) {
                    if (Storage::disk($artifact['disk'])->exists($artifact['key'])) {
                        throw new RuntimeException('Managed synthetic object survived deletion.');
                    }
                }
                if ($deleted->refresh()->status !== 'completed' || PhrPatient::query()->whereKey($patient->id)->exists()
                    || DB::table('phr_native_record_identities')->where('patient_id', $patient->id)->exists()
                    || ! is_file($download) || ! hash_equals($backup->archive_sha256, hash_file('sha256', $download))) {
                    throw new RuntimeException('Synthetic deletion/recovery isolation failed.');
                }
                $result['cleanup_verified'] = true;
                $result['deleted_managed_artifacts'] = count($plan->artifacts);
                $result['managed_artifact_bytes_before_delete'] = $plan->artifactBytes;
                $result['independent_download_verified'] = true;
            });
            if (($options['sharing'] ?? false) === true) {
                $optInPlan = $stage('new_patient_opt_in_plan', fn () => app(PhrNativeRestorePlanner::class)->plan($inspected, $actor, true));
                if (! in_array('actor_mapping_missing', $optInPlan->blockers, true)) {
                    throw new RuntimeException('Unknown shared actors were not refused for a new patient.');
                }
                $result['new_patient_opt_in_blocked'] = true;
            }
            $service = app(PhrNativeRestoreService::class);
            $attempt = $stage('restore_preview', fn () => $this->previewArchive($download, $actor, false));
            if (($attempt->plan_counts_json['blockers'] ?? []) !== []) {
                throw new RuntimeException('Synthetic restore has blockers.');
            }
            $attempt = $stage('restore_apply', fn () => $service->apply($attempt));
            if ($attempt->status !== 'completed' || $attempt->target_patient_root_id === null) {
                throw new RuntimeException('Synthetic restore did not complete.');
            }
            $restored = PhrPatient::findOrFail($attempt->target_patient_root_id);
            $activePatientId = (int) $restored->id;
            $after = $this->reviewState($activePatientId);
            $metrics->stages['restore_apply']['durable'] = $metrics->durable($activePatientId);
            $accessAfter = $this->accessState($restored, $actor, array_keys($accessBefore['shares']));
            if ($accessAfter['owner'] !== $accessBefore['owner'] || $accessAfter['shares'] !== [] || ! $accessAfter['former_shares_denied']) {
                throw new RuntimeException('Default new-patient access policy changed.');
            }
            $before['phr_patient_user_access'] = 1;
            if ($before !== $after) {
                throw new RuntimeException('Synthetic clinical/review state changed on restore.');
            }
            $rebuilt = $stage('rebackup', fn () => app(PhrNativeArchiveBuilder::class)->build($restored));
            $actual = $stage('fingerprint_after', fn () => $this->fingerprint($rebuilt->path, $rebuilt->manifest));
            if ($fingerprint['tables'] !== $actual['tables'] || $fingerprint['artifacts'] !== $actual['artifacts']) {
                $changed = array_keys(array_diff_assoc($fingerprint['tables'], $actual['tables']));
                throw new RuntimeException('Synthetic archive changed on restore: '.implode(',', $changed));
            }
            $result['round_trip_verified'] = true;
            $result['access_semantics_verified'] = true;
            $result['verified_tables'] = count($fingerprint['tables']);
            $result['verified_artifacts'] = count($fingerprint['artifacts']);
            $result['review_state'] = $after;
            $result['restored_patient_id'] = (int) $restored->id;
        } catch (NativeBackupException|NativeRestoreException $refused) {
            $result['status'] = $refused->failureCategory;
            $result['refused_stage'] = array_key_last($metrics->stages);
        } finally {
            $result['stages'] = $metrics->stages;
            unset($inspected);
            if ($inspectionPath !== null) {
                @unlink($inspectionPath);
            }
            if ($download !== null) {
                @unlink($download);
            }
            if ($rebuilt !== null) {
                @unlink($rebuilt->path);
            }
        }

        return $result;
    }

    /** Stable archive identities/content hashes verify every portable relationship and value. */
    public function fingerprint(string $path, array $manifest): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Synthetic archive cannot be inspected.');
        }
        try {
            $tables = [];
            $recordStreamBytes = 0;
            foreach ($manifest['tables'] as $table => $entry) {
                $recordStreamBytes += $zip->statName($entry['path'])['size'];
                $records = [];
                $stream = $zip->getStream($entry['path']);
                if (! is_resource($stream)) {
                    throw new RuntimeException('Synthetic record stream missing.');
                }
                try {
                    while (($line = fgets($stream)) !== false) {
                        $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                        if ($table === 'phr_patient_user_access') {
                            if (($row['attributes']['access_level'] ?? null) === 'owner') {
                                $records['owner'] = hash('sha256', json_encode(['role' => 'owner', 'actor' => $row['relationships']['user_id']], JSON_THROW_ON_ERROR));
                            }
                        } else {
                            $records[$row['nativeId']] = $row['contentHash'];
                        }
                    }
                } finally {
                    fclose($stream);
                }
                ksort($records);
                $tables[$table] = hash('sha256', json_encode($records, JSON_THROW_ON_ERROR));
            }
            $artifacts = [];
            foreach ($manifest['artifacts'] as $entry) {
                $artifacts[$entry['path']] = [$entry['size'], $entry['sha256']];
            }
            ksort($artifacts);
            ksort($tables);

            return ['tables' => $tables, 'artifacts' => $artifacts, 'record_stream_bytes' => $recordStreamBytes];
        } finally {
            $zip->close();
        }
    }

    private function previewArchive(string $path, int $actor, bool $shares): PhrNativeRestoreAttempt
    {
        $service = app(PhrNativeRestoreService::class);
        $bytes = filesize($path);
        $attempt = $service->startUpload($actor, $bytes, $shares);
        $source = fopen($path, 'rb');
        if (! is_resource($source)) {
            throw new RuntimeException('Independent recovery source is unreadable.');
        }
        try {
            $offset = 0;
            while ($offset < $bytes) {
                $part = tempnam(sys_get_temp_dir(), 'phr-capacity-part-');
                $target = fopen($part, 'wb');
                if (! is_resource($target)) {
                    throw new RuntimeException('Synthetic recovery chunk could not open.');
                }
                try {
                    $copied = stream_copy_to_stream($source, $target, min((int) config('phr.native_restore_chunk_bytes'), $bytes - $offset));
                    fclose($target);
                    $target = null;
                    if (! is_int($copied) || $copied < 1) {
                        throw new RuntimeException('Synthetic recovery source stopped early.');
                    }
                    $attempt = $service->appendChunk($attempt, $actor, new UploadedFile($part, 'synthetic-part', 'application/octet-stream', null, true), $offset);
                    $offset += $copied;
                } finally {
                    if (is_resource($target)) {
                        fclose($target);
                    }
                    @unlink($part);
                }
            }
        } finally {
            fclose($source);
        }

        return $service->preview($service->queuePreview($attempt, $actor));
    }

    public function reviewAll(PhrPatient $patient, int $actor): void
    {
        $rejected = DB::table('phr_ehi_proposals')->where('patient_id', $patient->id)->where('mapping', 'lab_result')->where('status', 'rejected')->exists();
        PhrEhiProposal::query()->where('patient_id', $patient->id)->where('status', PhrEhiProposal::STATUS_PENDING)->orderBy('id')
            ->chunkById(100, function ($proposals) use ($actor, &$rejected): void {
                foreach ($proposals as $proposal) {
                    $decision = match ($proposal->kind) {
                        PhrEhiProposal::KIND_CREATE, PhrEhiProposal::KIND_CORROBORATE => PhrEhiProposal::DECISION_ACCEPT,
                        PhrEhiProposal::KIND_UPDATE => PhrEhiProposal::DECISION_APPROVE_UPDATE,
                        default => PhrEhiProposal::DECISION_RETAIN,
                    };
                    if (! $rejected && $proposal->mapping === 'lab_result' && $proposal->kind === PhrEhiProposal::KIND_CREATE
                        && ($proposal->proposed_json['attributes']['external_id'] ?? null) === 'ORDER_RESULTS:100000:60000:1') {
                        $decision = PhrEhiProposal::DECISION_REJECT;
                        $rejected = true;
                    }
                    app(EhiProposalService::class)->review($proposal, $decision, $actor, $proposal->preview_digest);
                }
            });
        if (PhrEhiProposal::query()->where('patient_id', $patient->id)->where('status', PhrEhiProposal::STATUS_PENDING)->exists()) {
            throw new RuntimeException('Full synthetic review left an undecided proposal.');
        }
    }

    private function accessState(PhrPatient $patient, int $actor, array $formerShares = []): array
    {
        $access = app(PhrPatientAccessService::class);
        if ((int) $patient->owner_user_id !== $actor || ! $access->canWrite($patient, $actor)) {
            throw new RuntimeException('Restored owner authorization differs.');
        }
        $grants = PhrPatientUserAccess::query()->where('patient_id', $patient->id)->orderBy('user_id')->get();
        $owner = [];
        $shares = [];
        foreach ($grants as $grant) {
            $entry = ['role' => $grant->access_level, 'actor' => $grant->user_id, 'granted_by' => $grant->granted_by_user_id];
            if ($grant->user_id === $actor) {
                $owner = $entry;
                if ($grant->access_level !== 'owner') {
                    throw new RuntimeException('Synthetic owner grant differs.');
                }
            } else {
                $shares[$grant->user_id] = $entry;
                if (! $access->accessiblePatientsQuery($grant->user_id)->whereKey($patient->id)->exists()
                    || $access->canWrite($patient, $grant->user_id) !== ($grant->access_level === 'manager')) {
                    throw new RuntimeException('Synthetic shared role authorization differs.');
                }
            }
        }
        if ($owner === []) {
            throw new RuntimeException('Synthetic owner grant is missing.');
        }
        $denied = true;
        foreach ($formerShares as $user) {
            $denied = $denied && ! $access->accessiblePatientsQuery($user)->whereKey($patient->id)->exists() && ! $access->canWrite($patient, $user);
        }

        return ['owner' => $owner, 'shares' => $shares, 'former_shares_denied' => $denied];
    }

    private function reviewState(int $patient): array
    {
        $counts = [];
        foreach (['phr_lab_results', 'phr_documents', 'phr_immunizations', 'phr_office_visits', 'phr_ehi_proposal_decisions', 'phr_ehi_record_links', 'phr_patient_user_access'] as $table) {
            $counts[$table] = DB::table($table)->where('patient_id', $patient)->count();
        }
        $counts['proposal_statuses'] = DB::table('phr_ehi_proposals')->where('patient_id', $patient)->groupBy('status')->orderBy('status')
            ->selectRaw('status, COUNT(*) AS total')->pluck('total', 'status')->map(fn ($count): int => (int) $count)->all();

        return $counts;
    }

    /**
     * Snapshots the fixture's independently known managed source/blob and backup references before aggregate deletion.
     *
     * @return list<array{disk: string, key: string}>
     */
    public function snapshotKnownArtifacts(PhrPatient $patient, PhrNativeBackup $backup): array
    {
        $patientId = (int) $patient->id;
        $known = [];
        $record = static function (string $disk, ?string $key) use (&$known): void {
            if ($key !== null && $key !== '') {
                $known[$disk."\0".$key] = ['disk' => $disk, 'key' => $key];
            }
        };

        $record((string) $backup->storage_disk, (string) $backup->storage_path);

        DB::table('phr_native_backups')->where('patient_id', $patientId)->whereNotNull('storage_path')
            ->orderBy('id')->each(fn ($row) => $record((string) $row->storage_disk, (string) $row->storage_path));

        DB::table('phr_ehi_blobs')->where('patient_id', $patientId)->whereNotNull('storage_path')
            ->orderBy('id')->each(fn ($row) => $record((string) $row->storage_disk, (string) $row->storage_path));

        DB::table('phr_documents')->where('patient_id', $patientId)->whereNotNull('storage_path')
            ->orderBy('id')->each(fn ($row) => $record((string) $row->storage_disk, (string) $row->storage_path));

        DB::table('phr_dicom_files')->where('patient_id', $patientId)->whereNotNull('r2_key')
            ->orderBy('id')->each(fn ($row) => $record('phr_dicom', (string) $row->r2_key));

        DB::table('phr_exports')->where('patient_id', $patientId)->whereNotNull('storage_path')
            ->orderBy('id')->each(fn ($row) => $record((string) $row->storage_disk, (string) $row->storage_path));

        DB::table('phr_ehi_uploads')->where('patient_id', $patientId)->whereNotNull('storage_path')
            ->orderBy('id')->each(fn ($row) => $record((string) $row->storage_disk, (string) $row->storage_path));

        DB::table('phr_document_uploads')->where('patient_id', $patientId)->whereNotNull('staging_path')
            ->orderBy('id')->each(fn ($row) => $record((string) $row->storage_disk, (string) $row->staging_path));

        DB::table('phr_blob_migrations')->where('patient_id', $patientId)->orderBy('id')->each(function ($row) use ($record): void {
            if ($row->legacy_deleted_at === null && $row->source_key !== null) {
                $record((string) $row->storage_disk, (string) $row->source_key);
            }
            if ($row->destination_key !== null) {
                $record((string) $row->storage_disk, (string) $row->destination_key);
            }
        });

        return array_values($known);
    }

    /**
     * Asserts that the deletion plan covers all independently known managed references.
     *
     * @param  list<array{disk: string, key: string}>  $knownArtifacts
     */
    public function assertPlanCoversArtifacts(object $plan, array $knownArtifacts): void
    {
        $planKeys = [];
        foreach ($plan->artifacts as $artifact) {
            $planKeys[$artifact['disk']."\0".$artifact['key']] = true;
        }
        foreach ($knownArtifacts as $artifact) {
            if (! isset($planKeys[$artifact['disk']."\0".$artifact['key']])) {
                throw new RuntimeException('Synthetic deletion plan omitted known managed reference.');
            }
        }
    }

    /**
     * Verifies that every independently known managed object and backup is absent from storage.
     *
     * @param  list<array{disk: string, key: string}>  $knownArtifacts
     */
    public function assertArtifactsAbsent(array $knownArtifacts, ?PhrNativeBackup $backup = null): void
    {
        foreach ($knownArtifacts as $artifact) {
            if (Storage::disk($artifact['disk'])->exists($artifact['key'])) {
                throw new RuntimeException('Managed synthetic object survived deletion.');
            }
        }
        if ($backup !== null && Storage::disk($backup->storage_disk)->exists($backup->storage_path)) {
            throw new RuntimeException('Managed synthetic backup survived deletion.');
        }
    }
}

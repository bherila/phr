<?php

namespace Tests\Support;

use App\Models\PhrEhiProposal;
use App\Models\PhrPatient;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\NativeBackup\NativeBackupException;
use App\Services\PHR\NativeBackup\NativeRestoreException;
use App\Services\PHR\NativeBackup\PhrNativeArchiveBuilder;
use App\Services\PHR\NativeBackup\PhrNativeRestoreService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/** Synthetic-only lifecycle observation; no clinical content leaves the private fixture. */
final class EhiCapacityLifecycle
{
    public function run(PhrPatient $patient, int $actor, int $history): array
    {
        $result = ['status' => 'completed', 'stages' => [], 'round_trip_verified' => false];
        $stage = function (string $name, callable $operation) use (&$result): mixed {
            memory_reset_peak_usage();
            $started = microtime(true);
            try {
                return $operation();
            } finally {
                $result['stages'][$name] = ['seconds' => round(microtime(true) - $started, 3), 'peak_php_bytes' => memory_get_peak_usage(true)];
            }
        };
        $archive = null;
        $rebuilt = null;
        try {
            // A refused preview cannot authorize review or claim lifecycle readiness.
            $package = DB::table('phr_ehi_packages')->where('patient_id', $patient->id)->sole();
            if ($package->preview_generation_started < 1 || $package->preview_generation_started !== $package->preview_generation_completed) {
                $result['status'] = 'preview_resource_limit';

                return $result;
            }
            $stage('review', function () use ($patient, $actor, $history): void {
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
            });
            $before = $this->reviewState((int) $patient->id);
            $archive = $stage('backup', fn () => app(PhrNativeArchiveBuilder::class)->build($patient));
            $result['archive_records'] = array_sum($archive->counts);
            $result['archive_bytes'] = $archive->fileSize;
            $fingerprint = $stage('fingerprint_before', fn () => $this->fingerprint($archive->path, $archive->manifest));
            $result['record_stream_bytes'] = $fingerprint['record_stream_bytes'];
            // Only this fixture's patient is deleted; this harness uses a fresh private DB.
            DB::table('phr_native_record_identities')->where('patient_id', $patient->id)->delete();
            $patient->delete();
            $service = app(PhrNativeRestoreService::class);
            $attempt = $stage('restore_preview', fn () => $service->preview($service->createPreview(
                new UploadedFile($archive->path, 'synthetic.zip', 'application/zip', null, true), $actor, false,
            )));
            if (($attempt->plan_counts_json['blockers'] ?? []) !== []) {
                throw new RuntimeException('Synthetic restore has blockers.');
            }
            $attempt = $stage('restore_apply', fn () => $service->apply($attempt));
            if ($attempt->status !== 'completed' || $attempt->target_patient_root_id === null) {
                throw new RuntimeException('Synthetic restore did not complete.');
            }
            $restored = PhrPatient::findOrFail($attempt->target_patient_root_id);
            $after = $this->reviewState((int) $restored->id);
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
            $result['verified_tables'] = count($fingerprint['tables']);
            $result['verified_artifacts'] = count($fingerprint['artifacts']);
            $result['review_state'] = $after;
            $result['restored_patient_id'] = (int) $restored->id;
        } catch (NativeBackupException|NativeRestoreException $refused) {
            $result['status'] = $refused->failureCategory;
            $result['refused_stage'] = array_key_last($result['stages']);
        } finally {
            if ($archive !== null) {
                @unlink($archive->path);
            }
            if ($rebuilt !== null) {
                @unlink($rebuilt->path);
            }
        }

        return $result;
    }

    /** Stable archive identities/content hashes verify every portable relationship and value. */
    private function fingerprint(string $path, array $manifest): array
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
                // Owner access is deliberately recreated by restore, never replayed implicitly.
                if ($table === 'phr_patient_user_access') {
                    continue;
                }
                $records = [];
                $stream = $zip->getStream($entry['path']);
                if (! is_resource($stream)) {
                    throw new RuntimeException('Synthetic record stream missing.');
                }
                try {
                    while (($line = fgets($stream)) !== false) {
                        $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                        $records[$row['nativeId']] = $row['contentHash'];
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

            return ['tables' => $tables, 'artifacts' => $artifacts, 'record_stream_bytes' => $recordStreamBytes];
        } finally {
            $zip->close();
        }
    }

    private function reviewState(int $patient): array
    {
        $counts = [];
        foreach (['phr_lab_results', 'phr_ehi_proposal_decisions', 'phr_ehi_record_links', 'phr_patient_user_access'] as $table) {
            $counts[$table] = DB::table($table)->where('patient_id', $patient)->count();
        }
        $counts['proposal_statuses'] = DB::table('phr_ehi_proposals')->where('patient_id', $patient)->groupBy('status')->orderBy('status')
            ->selectRaw('status, COUNT(*) AS total')->pluck('total', 'status')->map(fn ($count): int => (int) $count)->all();

        return $counts;
    }
}

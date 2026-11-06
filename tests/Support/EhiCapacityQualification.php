<?php

namespace Tests\Support;

use App\Models\PhrEhiPackage;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiIntakeService;
use App\Services\PHR\Import\Ehi\EhiProjectionRefused;
use App\Services\PHR\Import\Ehi\EhiProjectionService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\EhiZipSource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Explicit synthetic qualification, not a supported production capacity claim. */
final class EhiCapacityQualification
{
    public function run(string $directory, string $name): array
    {
        $profiles = json_decode(file_get_contents(dirname(__DIR__, 2).'/scripts/ehi-qualification-profiles.json'), true, flags: JSON_THROW_ON_ERROR);
        $profile = $profiles[$name] ?? throw new RuntimeException('Unknown qualification profile.');
        $metrics = new EhiCapacityMetrics($directory);
        $start = microtime(true);
        $patient = null;
        $events = [];
        $lifecycle = new EhiCapacityLifecycle;
        $oracle = new EhiCapacityOracle;
        if (isset($profile['artifact_limit'])) {
            config(['phr.native_max_artifacts' => $profile['artifact_limit']]);
        }
        $result = ['status' => 'completed', 'round_trip_verified' => false];
        try {
            [$owner, $patient] = $metrics->stage('seed', function () use ($profile): array {
                Artisan::call('migrate', ['--force' => true]);
                $owner = User::factory()->create(['user_role' => 'user']);
                $patient = PhrPatient::create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic qualification', 'relationship' => 'self']);
                PhrPatientUserAccess::create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => 'owner', 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
                if ($profile['sharing']) {
                    foreach (['viewer', 'manager'] as $role) {
                        $shared = User::factory()->create(['user_role' => 'user']);
                        PhrPatientUserAccess::create(['patient_id' => $patient->id, 'user_id' => $shared->id, 'access_level' => $role, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
                    }
                }
                for ($offset = 0; $offset < $profile['history']; $offset += 100) {
                    $rows = [];
                    for ($i = $offset; $i < min($offset + 100, $profile['history']); $i++) {
                        $rows[] = ['patient_id' => $patient->id, 'user_id' => $owner->id, 'import_source' => 'synthetic-resource-history',
                            'external_id' => 'synthetic-prior-'.$i, 'review_status' => 'confirmed', 'analyte' => 'SYNTHETIC PRIOR '.$i,
                            'value' => '1', 'unit' => 'synthetic', 'collection_datetime' => '2009-01-01 00:00:00', 'created_at' => now(), 'updated_at' => now()];
                    }
                    DB::table('phr_lab_results')->insert($rows);
                }

                return [$owner, $patient];
            });
            $stage = fn (string $phase, callable $operation): mixed => $metrics->stage($phase, $operation, (int) $patient->id);
            $synthetic = EhiClinicalPackage::labCapacity($profile['records'], width: $profile['width'] ?? 0, fanout: $profile['fanout'] ?? 0, mixed: $profile['mixed'] ?? false)->build();
            if (isset($profile['artifact_boundary'])) {
                // Unique source bytes: avoid blob deduplication hiding the artifact boundary.
                $extra = (int) config('phr.native_max_artifacts');
                $base = count(array_unique(array_map(static fn ($bytes) => hash('sha256', $bytes), $synthetic->files))) + 2; // Two declared published document artifacts.
                for ($i = 0; $i < $extra - $base + ($profile['artifact_boundary'] === 'over' ? 1 : 0); $i++) {
                    $synthetic = $synthetic->with('artifacts/synthetic-'.$i.'.bin', 'Synthetic unique artifact '.$i);
                }
            }
            $zip = $this->zip($directory.'/initial.zip', $synthetic);
            $package = $stage('intake', fn () => $this->intake($zip, $patient, $owner->id));
            $stage('project', fn () => app(EhiProjectionService::class)->project($package, $owner->id));
            $stage('preview', fn () => app(EhiProposalService::class)->preview($package, $patient, $owner->id));
            $options = ['review' => $profile['review'], 'directory' => $directory, 'metrics' => $metrics,
                'sharing' => $profile['sharing'], 'native_boundary' => $profile['native_boundary'] ?? null];
            $options['after_initial_review'] = fn (PhrPatient $patient, callable $stage) => $stage('oracle_initial', fn () => $oracle->assertLabs($patient, $profile['records'], $profile['history']));
            if (isset($profile['artifact_boundary'])) {
                $options['artifact_target'] = (int) config('phr.native_max_artifacts') + ($profile['artifact_boundary'] === 'over' ? 1 : 0);
            }
            if ($profile['exercise']) {
                $options['exercise'] = function (PhrPatient $patient, callable $stage) use ($directory, $profile, $synthetic, $zip, $package, $owner, $lifecycle, $oracle, &$events): void {
                    $baseline = $stage('oracle_baseline', fn () => $oracle->snapshot($patient));
                    $stage('repreview', fn () => app(EhiProposalService::class)->preview($package->refresh(), $patient, $owner->id));
                    $stage('oracle_repreview', fn () => $oracle->assertUnchanged($baseline, $oracle->snapshot($patient)));
                    foreach (['identical' => $zip, 'repacked' => $this->zip($directory.'/repacked.zip', $synthetic, true)] as $kind => $path) {
                        $replayed = $stage('replay_'.$kind, fn () => $this->intake($path, $patient, $owner->id));
                        if ($replayed->id !== $package->id) {
                            throw new RuntimeException('Semantic replay did not converge.');
                        }
                        $stage('replay_'.$kind.'_preview', fn () => app(EhiProposalService::class)->preview($replayed, $patient, $owner->id));
                        $stage('oracle_'.$kind, fn () => $oracle->assertUnchanged($baseline, $oracle->snapshot($patient)));
                        $events[$kind] = ['same_package' => true, 'archive_bytes_identical' => hash_file('sha256', $path) === hash_file('sha256', $zip)];
                    }
                    if (! $events['identical']['archive_bytes_identical'] || $events['repacked']['archive_bytes_identical']) {
                        throw new RuntimeException('Replay controls did not distinguish archive bytes.');
                    }
                    $manifestOnly = $synthetic->with('synthetic-manifest-change.bin', 'Synthetic nonclinical addition');
                    $next = $stage('manifest_only_intake', fn () => $this->intake($this->zip($directory.'/manifest-only.zip', $manifestOnly), $patient, $owner->id));
                    if ($next->id === $package->id) {
                        throw new RuntimeException('Changed manifest was mistaken for identical input.');
                    }
                    $stage('manifest_only_preview', fn () => app(EhiProposalService::class)->preview($next, $patient, $owner->id));
                    $stage('oracle_manifest_preview', fn () => $oracle->assertUnchanged($baseline, $oracle->snapshot($patient)));
                    $stage('manifest_only_review', fn () => $lifecycle->reviewAll($patient, $owner->id));
                    $prior = $stage('oracle_manifest_review', function () use ($oracle, $baseline, $patient, $profile): array {
                        $after = $oracle->snapshot($patient);
                        $oracle->assertPriorPreserved($baseline, $after);
                        $oracle->assertLabs($patient, $profile['records'], $profile['history']);

                        return $after;
                    });
                    $events['changed_manifest'] = ['new_package' => true, 'clinical_rows_unchanged' => true, 'prior_decisions_preserved' => true];
                    for ($cycle = 1; $cycle <= ($profile['cycles'] ?? 0); $cycle++) {
                        $count = $profile['records'] + $cycle * $profile['new'];
                        $changed = EhiClinicalPackage::labCapacity($count, $profile['changed'], $cycle)->build();
                        $next = $stage('cycle_'.$cycle.'_intake', fn () => $this->intake($this->zip($directory.'/changed-'.$cycle.'.zip', $changed), $patient, $owner->id));
                        $stage('cycle_'.$cycle.'_preview', fn () => app(EhiProposalService::class)->preview($next, $patient, $owner->id));
                        $stage('oracle_cycle_'.$cycle.'_preview', fn () => $oracle->assertUnchanged($prior, $oracle->snapshot($patient)));
                        $stage('cycle_'.$cycle.'_review', fn () => $lifecycle->reviewAll($patient, $owner->id));
                        $prior = $stage('oracle_cycle_'.$cycle.'_review', function () use ($oracle, $prior, $patient, $profile, $count, $cycle): array {
                            $after = $oracle->snapshot($patient);
                            $oracle->assertPriorPreserved($prior, $after, true);
                            $oracle->assertLabs($patient, $count, $profile['history'], $profile['changed'], $cycle);

                            return $after;
                        });
                        $events['cycles_completed'] = $cycle;
                    }
                };
            }
            $result = $lifecycle->run($patient, $owner->id, $profile['history'], $options);
        } catch (EhiProjectionRefused $refused) {
            $result['status'] = $refused->reason;
            $result['refused_stage'] = array_key_last($metrics->stages);
            $result['resource_kind'] = $refused->resourceLimit?->value;
            if ($patient !== null) {
                $package = PhrEhiPackage::query()->where('patient_id', $patient->id)->latest('id')->first();
                $result['generation_complete'] = $package?->previewCompleted() ?? false;
            }
        } catch (Throwable $failure) {
            // Preserve aggregate failure evidence; never emit source-bearing exception messages.
            $result['status'] = 'unexpected_failure';
            $result['refused_stage'] = array_key_last($metrics->stages);
            $result['exception_class'] = $failure::class;
        }

        return ['profile' => $name, 'declaration' => $profile, 'status' => $result['status'],
            'seconds' => round(microtime(true) - $start, 6),
            'queries' => array_sum(array_column($metrics->stages, 'queries')),
            'max_bindings' => max([0, ...array_column($metrics->stages, 'max_bindings')]),
            'peak_php_bytes' => max([memory_get_peak_usage(true), ...array_column($metrics->stages, 'peak_php_bytes')]),
            'lifecycle' => $result, 'replay' => $events, 'stages' => $metrics->stages,
            'php_version' => PHP_VERSION, 'database_driver' => DB::connection()->getDriverName(), 'database_version' => DB::connection()->getServerVersion(),
            'composer_lock_sha256' => hash_file('sha256', dirname(__DIR__, 2).'/composer.lock'),
            'limits' => ['rows' => config('phr.ehi_preview_max_rows'), 'bytes' => config('phr.ehi_preview_max_bytes'), 'seconds' => config('phr.ehi_preview_max_seconds'),
                'native_records' => config('phr.native_max_records'), 'native_stream_bytes' => config('phr.native_max_record_stream_bytes')]];
    }

    private function intake(string $path, PhrPatient $patient, int $actor): PhrEhiPackage
    {
        $source = new EhiZipSource($path);
        try {
            $package = app(EhiIntakeService::class)->intake($source, $patient, $actor)->package;
        } finally {
            $source->close();
        }
        if ($package->source_id === null) {
            $existing = DB::table('phr_ehi_sources')->where('patient_id', $patient->id)->value('id');
            $package = app(EhiBindingService::class)->bind($package, $patient, $actor, $existing === null ? null : (int) $existing, $existing === null ? 'Synthetic qualification source' : null);
        }

        return $package;
    }

    private function zip(string $path, EhiSyntheticPackage $package, bool $repacked = false): string
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $files = $repacked ? array_reverse($package->files, true) : $package->files;
        foreach ($files as $name => $bytes) {
            $zip->addFromString($name, $bytes);
            $zip->setCompressionName($name, $repacked ? ZipArchive::CM_DEFLATE : ZipArchive::CM_STORE);
        }
        $zip->close();

        return $path;
    }
}

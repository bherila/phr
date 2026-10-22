<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\Ehi\EhiPackageSummary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('phr:ehi:status {--patient= : PHR patient id} {--actor= : Acting user id} {--package= : EHI package id (default: every package of the patient)}')]
#[Description('Show preservation, accounting, clinical, binding, projection and proposal state of EHI packages')]
class PhrEhiStatusCommand extends BaseEhiCommand
{
    /** Projection table outcomes recorded in the checkpoint, with their printed labels. */
    private const array TABLE_OUTCOMES = ['projected' => 'projected', 'mismatched' => 'key mismatch', 'absent' => 'absent'];

    public function handle(PhrPatientAccessService $accessService): int
    {
        $patient = $accessService->accessiblePatient($this->intOptionRequired('patient'), $this->intOptionRequired('actor'));
        $packages = $this->packagesFor($patient, $this->optionalIntOption('package'));
        if ($packages->isEmpty()) {
            $this->line('No EHI packages.');

            return self::SUCCESS;
        }

        foreach ($packages as $index => $package) {
            if ($index > 0) {
                $this->line('');
            }
            $this->linePackage($package, EhiPackageSummary::forPackage($package));
            $this->lineProjection($package);
            $this->lineProposals($package);

            // Intake and projection share the package lease; the clinical status
            // says which one holds it.
            $projecting = $package->clinical_status === PhrEhiPackage::CLINICAL_PROJECTING;
            if ($package->lease_expires_at !== null && $package->lease_expires_at->isFuture()) {
                $this->line($projecting ? 'projection in progress: yes' : 'intake in progress: yes');
            } elseif ($projecting) {
                $this->line('projection interrupted: yes (the next preview resumes it)');
            }
        }

        return self::SUCCESS;
    }

    /** Mapped-table outcomes of the latest projection run, by fixed outcome code. */
    private function lineProjection(PhrEhiPackage $package): void
    {
        $projection = $package->checkpoint_json['projection'] ?? null;
        if (! is_array($projection)) {
            $this->line('projection: (not started)');

            return;
        }
        $tables = is_array($projection['tables'] ?? null) ? $projection['tables'] : [];
        $counts = array_fill_keys(array_keys(self::TABLE_OUTCOMES), 0);
        foreach ($tables as $outcome) {
            if (is_string($outcome) && isset($counts[$outcome])) {
                $counts[$outcome]++;
            }
        }
        $this->line('projection: tables '.implode(', ', array_map(
            static fn (string $outcome, int $count): string => self::TABLE_OUTCOMES[$outcome]." {$count}",
            array_keys($counts),
            $counts,
        )));
    }

    /**
     * The proposal preview state, proposal counts by status, and pending ones by
     * mapping and kind. A pending proposal outside the package's completed
     * preview (left by a failed run, or restored) is not reviewable, so it is
     * counted apart rather than as pending.
     */
    private function lineProposals(PhrEhiPackage $package): void
    {
        $this->line('preview: '.match (true) {
            $package->preview_generation_started === null => '(not started)',
            $package->previewCompleted() => "complete (generation {$package->preview_generation_completed})",
            default => "incomplete (generation {$package->preview_generation_started} did not complete; run phr:ehi:preview)",
        });

        if (! $package->previewCompleted()) {
            $this->line('preview findings: historical diagnostics from the last completed preview, if any');
        }

        $statuses = PhrEhiProposal::query()->where('package_id', $package->id)
            ->tap(static fn ($query) => PhrEhiProposal::constrainToCompletedPreview($query->getQuery()))
            ->selectRaw('status, count(*) as aggregate')->groupBy('status')->orderBy('status')
            ->pluck('aggregate', 'status')->map(static fn (mixed $count): int => (int) $count)->all();
        $this->line('proposals: '.($statuses === [] ? '(none)' : $this->counts($statuses)));

        $pending = [];
        PhrEhiProposal::query()->where('package_id', $package->id)->where('status', PhrEhiProposal::STATUS_PENDING)
            ->tap(static fn ($query) => PhrEhiProposal::constrainToCompletedPreview($query->getQuery()))
            ->selectRaw('mapping, kind, count(*) as aggregate')->groupBy('mapping', 'kind')->orderBy('mapping')->orderBy('kind')
            ->get()->each(function (PhrEhiProposal $row) use (&$pending): void {
                $pending["{$row->mapping} {$row->kind}"] = (int) $row->getAttribute('aggregate');
            });
        if ($pending !== []) {
            $this->line('pending: '.$this->counts($pending));
        }

        $unreviewable = PhrEhiProposal::query()->where('package_id', $package->id)->where('status', PhrEhiProposal::STATUS_PENDING)->count()
            - array_sum($pending);
        if ($unreviewable > 0) {
            $this->line("pending outside the completed preview: {$unreviewable} (not reviewable until a preview completes)");
        }
    }
}

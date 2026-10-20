<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('phr:ehi:proposals {--patient= : PHR patient id} {--actor= : Acting user id} {--package= : EHI package id (default: every package of the patient)} {--status= : Only proposals with this status} {--show= : Proposal id to describe} {--values : With --show, also print proposed and current values (health data)}')]
#[Description('List EHI proposals, or describe one (field names only unless --values)')]
class PhrEhiProposalsCommand extends BaseEhiCommand
{
    public function handle(PhrPatientAccessService $accessService): int
    {
        $patient = $accessService->accessiblePatient($this->intOptionRequired('patient'), $this->intOptionRequired('actor'));
        $packages = $this->packagesFor($patient, $this->optionalIntOption('package'));

        $show = $this->optionalIntOption('show');
        if ($show !== null) {
            $proposal = PhrEhiProposal::query()->where('patient_id', $patient->id)->whereIn('package_id', $packages->modelKeys())->find($show);
            if ($proposal === null) {
                $this->error('--show does not name an EHI proposal of this patient.');

                return self::INVALID;
            }
            $this->describe($proposal, (bool) $this->option('values'));

            return self::SUCCESS;
        }
        if ($this->option('values')) {
            $this->error('--values requires --show.');

            return self::INVALID;
        }

        // A pending proposal outside its package's completed preview (left by a
        // failed run, or restored) is not reviewable, so it is not listed.
        foreach ($packages as $package) {
            if ($package->preview_generation_started !== null && ! $package->previewCompleted()) {
                $this->warn("package {$package->id}: the last preview did not complete; its pending proposals are hidden until phr:ehi:preview completes.");
            }
        }
        $query = PhrEhiProposal::query()->where('patient_id', $patient->id)->whereIn('package_id', $packages->modelKeys())
            ->tap(static fn ($query) => PhrEhiProposal::constrainToCompletedPreview($query->getQuery()))->orderBy('id');
        $status = $this->optionalStringOption('status');
        if ($status !== null) {
            $query->where('status', $status);
        }
        $proposals = $query->get();
        if ($proposals->isEmpty()) {
            $this->line('No EHI proposals.');

            return self::SUCCESS;
        }
        foreach ($proposals as $proposal) {
            $this->line(sprintf(
                'proposal %d: package %d, mapping %s, kind %s, status %s, target %s, preview digest %s',
                $proposal->id,
                $proposal->package_id,
                $proposal->mapping,
                $proposal->kind,
                $proposal->status,
                $this->target($proposal),
                $proposal->preview_digest,
            ));
        }

        return self::SUCCESS;
    }

    private function describe(PhrEhiProposal $proposal, bool $values): void
    {
        $this->line("proposal: {$proposal->id}");
        $this->line("package: {$proposal->package_id}");
        $this->line("mapping: {$proposal->mapping} ({$proposal->mapping_version})");
        $this->line("kind: {$proposal->kind}");
        $this->line("status: {$proposal->status}");
        if ($proposal->status === PhrEhiProposal::STATUS_PENDING) {
            $package = PhrEhiPackage::query()->find($proposal->package_id);
            $this->line('reviewable: '.($package?->completedPreviewIncludes($proposal) ? 'yes' : 'no (not part of a completed preview; run phr:ehi:preview)'));
        }
        $this->line('target: '.$this->target($proposal));
        $this->line('revisions: '.$proposal->revisions()->count());
        $this->line('candidates: '.($proposal->candidate_ids_json === null ? '(none)' : implode(', ', $proposal->candidate_ids_json)));
        $this->line("preview digest: {$proposal->preview_digest}");
        $this->line("revision digest: {$proposal->revision_digest}");

        $proposed = $proposal->proposed_json ?? [];
        $before = $proposal->before_json ?? [];
        // A mapping may nest the record's fields under `attributes` (provenance
        // beside them) and name an update's changed fields in `changed`, keeping
        // only digests of large values in before_json.
        $changed = null;
        $provenance = [];
        if (is_array($proposed['attributes'] ?? null)) {
            $changed = is_array($proposed['changed'] ?? null) ? array_map('strval', $proposed['changed']) : null;
            $provenance = array_diff_key($proposed, ['attributes' => true, 'changed' => true]);
            $proposed = $proposed['attributes'];
        }
        $fields = array_keys($proposed);
        if ($changed === null) {
            $fields = [...$fields, ...array_keys($before)];
        }
        $fields = array_values(array_unique(array_map('strval', $fields)));
        sort($fields, SORT_STRING);
        $this->line('fields: '.($fields === [] ? '(none)' : ''));
        foreach ($fields as $field) {
            $change = match (true) {
                $changed !== null => in_array($field, $changed, true) ? 'changed' : 'unchanged',
                ! array_key_exists($field, $before) => $proposal->before_json === null ? 'proposed' : 'added',
                ! array_key_exists($field, $proposed) => 'not proposed',
                $before[$field] === $proposed[$field] => 'unchanged',
                default => 'changed',
            };
            $this->line("  {$field}: {$change}");
        }

        if (! $values) {
            return;
        }
        $this->warn('WARNING: the values below are health data. Do not share or log this output.');
        foreach ($fields as $field) {
            if (array_key_exists($field, $before)) {
                $this->line("  {$field} before: ".$this->value($before[$field]));
            }
            if (array_key_exists($field, $proposed)) {
                $this->line("  {$field} proposed: ".$this->value($proposed[$field]));
            }
        }
        foreach ($provenance as $key => $value) {
            $this->line("  {$key} (provenance): ".$this->value($value));
        }
    }

    private function target(PhrEhiProposal $proposal): string
    {
        return $proposal->target_table === null ? '(none)' : "{$proposal->target_table} {$proposal->target_id}";
    }

    private function value(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: 'null';
    }
}

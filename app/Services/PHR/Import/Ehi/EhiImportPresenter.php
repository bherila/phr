<?php

namespace App\Services\PHR\Import\Ehi;

use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiSource;
use App\Models\PhrEhiUpload;

/**
 * JSON shapes for the browser EHI import routes: the facts `phr:ehi:status` and
 * `phr:ehi:proposals` print, as data. Package payloads carry ids, counts, fixed
 * codes and the user's own source labels; never a member path, a table or
 * column name, or an unmasked source patient key. Proposal payloads carry the
 * proposed and current values, which the person reviewing them needs.
 */
final class EhiImportPresenter
{
    /** Projection table outcomes recorded in the package checkpoint. */
    private const array TABLE_OUTCOMES = ['projected', 'mismatched', 'absent'];

    /**
     * How the client sends the archive's bytes. Only `chunks` (sequential POSTs
     * to the chunks route) exists today; a client reads this rather than assuming
     * it, so a signed object-storage transport can be added without changing it.
     */
    public const string TRANSPORT_CHUNKS = 'chunks';

    /** @return array<string, mixed> */
    public function upload(PhrEhiUpload $upload): array
    {
        $result = $upload->result_json ?? [];

        return [
            'id' => $upload->id,
            'patient_id' => $upload->patient_id,
            'status' => $upload->status,
            'failure_reason' => $upload->failure_reason,
            'transport' => self::TRANSPORT_CHUNKS,
            'file_size_bytes' => $upload->file_size_bytes,
            'uploaded_bytes' => $upload->uploaded_bytes,
            'chunk_size_bytes' => (int) config('phr.ehi_upload_chunk_bytes'),
            'package_id' => $upload->package_id,
            'replayed' => isset($result['replayed']) ? (bool) $result['replayed'] : null,
            'resumed' => isset($result['resumed']) ? (bool) $result['resumed'] : null,
            'repaired' => isset($result['repaired']) ? (int) $result['repaired'] : null,
            'expires_at' => $upload->expires_at->toIso8601String(),
            'completed_at' => $upload->completed_at?->toIso8601String(),
            'created_at' => $upload->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function package(PhrEhiPackage $package): array
    {
        $summary = EhiPackageSummary::forPackage($package);
        $source = $package->source_id === null ? null : PhrEhiSource::query()->find($package->source_id);
        $leased = $package->lease_expires_at !== null && $package->lease_expires_at->isFuture();
        $projecting = $package->clinical_status === PhrEhiPackage::CLINICAL_PROJECTING;

        return [
            'id' => $package->id,
            'patient_id' => $package->patient_id,
            'preservation_status' => $package->preservation_status,
            'accounting_status' => $package->accounting_status,
            'clinical_status' => $package->clinical_status,
            'binding_status' => $package->binding_status,
            'failure_category' => $package->failure_category,
            'source' => $source === null ? null : ['id' => $source->id, 'label' => $source->label],
            'source_patient_key' => $package->detected_source_patient_key === null ? null : EhiMaskedKey::mask($package->detected_source_patient_key),
            'intake_count' => $package->intake_count,
            'last_intake_at' => $package->last_intake_at?->toIso8601String(),
            'created_at' => $package->created_at?->toIso8601String(),
            // Intake and projection share the package lease; the clinical status
            // says which one holds it.
            'intake_in_progress' => $leased && ! $projecting,
            'projection_in_progress' => $leased && $projecting,
            'projection_interrupted' => ! $leased && $projecting,
            'accounting' => [
                'members' => $summary->members,
                'members_by_kind' => $summary->membersByKind,
                'tables' => $summary->tables,
                'tables_by_state' => $summary->tablesByState,
                'source_rows' => $summary->sourceRows,
                'malformed_rows' => $summary->malformedRows,
                'dispositions' => $summary->dispositions,
                'findings_by_code' => $summary->findingsByCode,
                'unreconciled_tables' => $summary->unreconciledTables,
            ],
            'projection' => $this->projection($package),
            'preview' => [
                'state' => match (true) {
                    $package->preview_generation_started === null => 'not_started',
                    $package->previewCompleted() => 'complete',
                    default => 'incomplete',
                },
                'generation' => $package->preview_generation_completed,
            ],
            'proposals' => $this->proposalCounts($package),
        ];
    }

    /**
     * @param  iterable<PhrEhiSource>  $suggestions
     * @return list<array{id: int, label: string, dictionary_matches: bool}>
     */
    public function suggestions(PhrEhiPackage $package, iterable $suggestions): array
    {
        $out = [];
        foreach ($suggestions as $source) {
            $out[] = [
                'id' => $source->id,
                'label' => $source->label,
                'dictionary_matches' => $package->dictionary_fingerprint !== null && $source->dictionary_fingerprint === $package->dictionary_fingerprint,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function preview(EhiPreview $preview): array
    {
        return [
            'package' => $this->package($preview->package),
            'replayed' => $preview->projection->replayed,
            'counts' => $preview->counts,
            'statuses' => $preview->statuses,
            'findings' => $preview->findings,
            'preview_digest' => $preview->digest,
        ];
    }

    /** @return array<string, mixed> */
    public function proposal(PhrEhiProposal $proposal, bool $reviewable): array
    {
        $proposed = $proposal->proposed_json ?? [];
        $candidates = $proposed['_candidate_summary'] ?? null;
        unset($proposed['_candidate_summary']);

        return [
            'id' => $proposal->id,
            'package_id' => $proposal->package_id,
            'mapping' => $proposal->mapping,
            'mapping_version' => $proposal->mapping_version,
            'kind' => $proposal->kind,
            'status' => $proposal->status,
            'reviewable' => $reviewable,
            'allowed_decisions' => $reviewable ? (EhiProposalService::ALLOWED_DECISIONS[$proposal->kind] ?? []) : [],
            'target' => $proposal->target_table === null ? null : ['table' => $proposal->target_table, 'id' => $proposal->target_id],
            'candidate_ids' => $proposal->candidate_ids_json ?? [],
            'candidate_total' => is_array($candidates) ? (int) ($candidates['total'] ?? 0) : count($proposal->candidate_ids_json ?? []),
            'candidates_truncated' => is_array($candidates) && (bool) ($candidates['truncated'] ?? false),
            'proposed' => $proposed,
            'before' => $proposal->before_json,
            'preview_digest' => $proposal->preview_digest,
            'decided_at' => $proposal->decided_at?->toIso8601String(),
        ];
    }

    /** @return array<string, int>|null mapped-table outcomes of the latest projection run */
    private function projection(PhrEhiPackage $package): ?array
    {
        $projection = $package->checkpoint_json['projection'] ?? null;
        if (! is_array($projection)) {
            return null;
        }
        $counts = array_fill_keys(self::TABLE_OUTCOMES, 0);
        foreach (is_array($projection['tables'] ?? null) ? $projection['tables'] : [] as $outcome) {
            if (is_string($outcome) && isset($counts[$outcome])) {
                $counts[$outcome]++;
            }
        }

        return $counts;
    }

    /**
     * Counts by status, pending ones by mapping and kind, and pending ones that
     * are not reviewable because they are outside the package's completed
     * preview (left by a failed run, or restored).
     *
     * @return array{by_status: array<string, int>, pending: array<string, array<string, int>>, pending_unreviewable: int}
     */
    private function proposalCounts(PhrEhiPackage $package): array
    {
        $byStatus = PhrEhiProposal::query()->where('package_id', $package->id)
            ->tap(static fn ($query) => PhrEhiProposal::constrainToCompletedPreview($query->getQuery()))
            ->selectRaw('status, count(*) as aggregate')->groupBy('status')->orderBy('status')
            ->pluck('aggregate', 'status')->map(static fn (mixed $count): int => (int) $count)->all();

        $pending = [];
        $reviewable = 0;
        PhrEhiProposal::query()->where('package_id', $package->id)->where('status', PhrEhiProposal::STATUS_PENDING)
            ->tap(static fn ($query) => PhrEhiProposal::constrainToCompletedPreview($query->getQuery()))
            ->selectRaw('mapping, kind, count(*) as aggregate')->groupBy('mapping', 'kind')->orderBy('mapping')->orderBy('kind')
            ->get()->each(function (PhrEhiProposal $row) use (&$pending, &$reviewable): void {
                $count = (int) $row->getAttribute('aggregate');
                $pending[$row->mapping][$row->kind] = $count;
                $reviewable += $count;
            });
        $allPending = PhrEhiProposal::query()->where('package_id', $package->id)->where('status', PhrEhiProposal::STATUS_PENDING)->count();

        return ['by_status' => $byStatus, 'pending' => $pending, 'pending_unreviewable' => $allPending - $reviewable];
    }
}

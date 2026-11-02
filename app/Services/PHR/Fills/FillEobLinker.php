<?php

namespace App\Services\PHR\Fills;

use App\Models\PhrMedicationFillEvidence;
use App\Models\PhrPatient;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps one patient's automatic fill-to-claim evidence equal to what
 * {@see FillEobLinkPlanner} says it should be, recomputed from the current data every time. What
 * was linked before is never an input, so the result does not depend on the order things arrived.
 *
 * Writes only `phr_medication_fill_evidence` rows of its own kinds
 * ({@see PhrMedicationFillEvidence::LINKER_KINDS}), and only where they differ. A fill row is
 * never written: its review status, its clinical fields and its opaque version (a hash of the
 * fill's own columns) stay exactly as they were. Rows a person or client made are read, never
 * changed: such a fill is settled and such a claim is taken.
 *
 * Rows are matched on (fill, line) and not on evidence_key, because a native restore remaps ids
 * but keeps the key, which was derived from the old ones.
 */
class FillEobLinker
{
    public function __construct(private readonly FillEobLinkPlanner $planner) {}

    /**
     * Brings the patient's automatic evidence up to date.
     *
     * @return array<string, int>
     */
    public function recompute(int $patientId): array
    {
        return DB::transaction(function () use ($patientId): array {
            // Serializes with the writers of this patient's fills and claims, which take the same lock.
            if (PhrPatient::query()->whereKey($patientId)->lockForUpdate()->first() === null) {
                return [];
            }
            $snapshot = $this->snapshot($patientId);
            $plan = $this->plan($snapshot);
            $diff = $this->diff($snapshot, $plan);
            $this->apply($patientId, $diff);

            return [...$plan['counts'], ...$this->counts($diff)];
        }, 3);
    }

    /**
     * What recompute would do, changing nothing.
     *
     * @return array<string, int>
     */
    public function preview(int $patientId): array
    {
        $snapshot = $this->snapshot($patientId);
        $plan = $this->plan($snapshot);

        return [...$plan['counts'], ...$this->counts($this->diff($snapshot, $plan))];
    }

    /**
     * @return array{
     *     fills: list<array{id: int, day: string, drug: ?string}>,
     *     claims: array<int, array{id: int, day: ?string, charge: ?int, sort: string, line_ids: list<int>, claim_number: ?string, processed_date: ?string, total_charges: ?string, provider_name: ?string}>,
     *     manual: array<int, list<int>>,
     *     dismissed: list<int>,
     *     automatic: Collection<int, object>,
     *     flags: Collection<int, object>,
     * }
     */
    private function snapshot(int $patientId): array
    {
        // Reads the tables directly: raw dates, no casts, and no model events in a path that runs on every write.
        $fills = DB::table('phr_medication_fills')->where('patient_id', $patientId)
            ->whereNull('deleted_at')->whereNull('retracted_at')->where('review_status', '!=', PhrReviewStatus::REJECTED)
            ->get(['id', 'filled_on', 'ndc_11', 'drug_as_printed'])
            ->map(static fn (object $fill): array => [
                'id' => (int) $fill->id,
                'day' => substr((string) $fill->filled_on, 0, 10),
                'drug' => self::drugKey($fill->ndc_11, $fill->drug_as_printed),
            ])->all();
        $liveFills = array_flip(array_column($fills, 'id'));

        $eobs = DB::table('phr_eobs')->where('patient_id', $patientId)
            ->where('claim_type', 'pharmacy')->where('review_status', '!=', PhrReviewStatus::REJECTED)
            ->get(['id', 'claim_number', 'claim_fingerprint', 'processed_date', 'total_charges', 'provider_name']);
        $lines = DB::table('phr_eob_lines')->where('patient_id', $patientId)
            ->whereIn('eob_id', fn ($query) => $query->select('id')->from('phr_eobs')->where('patient_id', $patientId)
                ->where('claim_type', 'pharmacy')->where('review_status', '!=', PhrReviewStatus::REJECTED))
            ->orderBy('line_number')->orderBy('id')
            ->get(['id', 'eob_id', 'service_start', 'total_charges'])->groupBy('eob_id');

        $claims = [];
        $claimOfLine = [];
        foreach ($eobs as $eob) {
            $eobLines = $lines->get($eob->id, collect());
            $days = $eobLines->pluck('service_start')->filter()->map(static fn (mixed $date): string => substr((string) $date, 0, 10))->unique()->values();
            $processed = $eob->processed_date === null ? null : substr((string) $eob->processed_date, 0, 10);
            foreach ($eobLines as $line) {
                $claimOfLine[(int) $line->id] = (int) $eob->id;
            }
            $claims[(int) $eob->id] = [
                'id' => (int) $eob->id,
                // A claim spanning several dates has no one service date to match a fill on.
                'day' => $days->count() === 1 ? $days->first() : null,
                'charge' => self::charge($eob->total_charges, $eobLines),
                // Row ids come last: they are only for two claims with nothing else to tell them apart.
                'sort' => implode('|', [$processed ?? '9999-12-31', (string) $eob->claim_number, (string) $eob->claim_fingerprint, str_pad((string) $eob->id, 20, '0', STR_PAD_LEFT)]),
                'line_ids' => $eobLines->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
                'claim_number' => $eob->claim_number,
                'processed_date' => $processed,
                'total_charges' => is_numeric($eob->total_charges) ? number_format((float) $eob->total_charges, 2, '.', '') : null,
                'provider_name' => $eob->provider_name,
            ];
        }

        $evidence = DB::table('phr_medication_fill_evidence')->where('patient_id', $patientId)
            ->where(fn ($query) => $query->whereNotNull('eob_line_id')->orWhereIn('source_kind', PhrMedicationFillEvidence::LINKER_KINDS))
            ->orderBy('id')
            ->get(['id', 'fill_id', 'eob_line_id', 'source_kind', 'source_locator']);

        $manual = [];
        foreach ($evidence as $row) {
            if (in_array($row->source_kind, PhrMedicationFillEvidence::LINKER_KINDS, true) || ! isset($liveFills[(int) $row->fill_id])) {
                continue;
            }
            $manual[(int) $row->fill_id] ??= [];
            if (isset($claimOfLine[(int) $row->eob_line_id])) {
                $manual[(int) $row->fill_id][] = $claimOfLine[(int) $row->eob_line_id];
            }
        }

        return [
            'fills' => $fills,
            'claims' => $claims,
            'manual' => $manual,
            'dismissed' => $evidence->where('source_kind', PhrMedicationFillEvidence::KIND_EOB_LINK_DISMISSED)
                ->pluck('fill_id')->map(static fn (mixed $id): int => (int) $id)->filter(static fn (int $id): bool => isset($liveFills[$id]))->values()->all(),
            'automatic' => $evidence->where('source_kind', PhrMedicationFillEvidence::KIND_AUTO_EOB_LINK)->values(),
            'flags' => $evidence->where('source_kind', PhrMedicationFillEvidence::KIND_EOB_LINK_OPEN)->values(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{links: array<int, array{claim_id: int, rule: string}>, open: array<int, array{reason: string, claim_ids: list<int>}>, counts: array<string, int>}
     */
    private function plan(array $snapshot): array
    {
        return $this->planner->plan(
            $snapshot['fills'],
            array_values(array_map(static fn (array $claim): array => ['id' => $claim['id'], 'day' => $claim['day'], 'charge' => $claim['charge'], 'sort' => $claim['sort']], $snapshot['claims'])),
            $snapshot['manual'],
            $snapshot['dismissed'],
        );
    }

    /**
     * What differs between the rows there are and the rows the plan calls for.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array{links: array<int, array{claim_id: int, rule: string}>, open: array<int, array{reason: string, claim_ids: list<int>}>, counts: array<string, int>}  $plan
     * @return array<string, mixed>
     */
    private function diff(array $snapshot, array $plan): array
    {
        $wanted = [];
        foreach ($plan['links'] as $fillId => $link) {
            foreach ($snapshot['claims'][$link['claim_id']]['line_ids'] as $lineId) {
                $wanted["{$fillId}|{$lineId}"] = ['fill_id' => $fillId, 'eob_line_id' => $lineId, 'rule' => $link['rule']];
            }
        }

        $diff = ['add_links' => [], 'remove' => [], 'relabel' => [], 'add_flags' => [], 'links_removed' => 0, 'flags_removed' => 0, 'links_relabelled' => 0, 'flags_relabelled' => 0];
        foreach ($snapshot['automatic'] as $row) {
            $key = $row->fill_id.'|'.$row->eob_line_id;
            if ($row->eob_line_id === null || ! isset($wanted[$key])) {
                $diff['remove'][] = (int) $row->id;
                $diff['links_removed']++;

                continue;
            }
            if ($row->source_locator !== $wanted[$key]['rule']) {
                $diff['relabel'][] = ['id' => (int) $row->id, 'locator' => $wanted[$key]['rule']];
                $diff['links_relabelled']++;
            }
            unset($wanted[$key]);
        }
        $diff['add_links'] = array_values($wanted);

        $open = array_map(static fn (array $entry): string => $entry['reason'], $plan['open']);
        foreach ($snapshot['flags'] as $row) {
            $fillId = (int) $row->fill_id;
            if (! isset($open[$fillId])) {
                $diff['remove'][] = (int) $row->id;
                $diff['flags_removed']++;

                continue;
            }
            if ($row->source_locator !== $open[$fillId]) {
                $diff['relabel'][] = ['id' => (int) $row->id, 'locator' => $open[$fillId]];
                $diff['flags_relabelled']++;
            }
            unset($open[$fillId]);
        }
        foreach ($open as $fillId => $reason) {
            $diff['add_flags'][] = ['fill_id' => $fillId, 'reason' => $reason];
        }

        return $diff;
    }

    /** @param  array<string, mixed>  $diff */
    private function apply(int $patientId, array $diff): void
    {
        if ($diff['remove'] !== []) {
            PhrMedicationFillEvidence::query()->where('patient_id', $patientId)->whereIn('id', $diff['remove'])
                ->whereIn('source_kind', PhrMedicationFillEvidence::LINKER_KINDS)->delete();
        }
        foreach ($diff['relabel'] as $relabel) {
            PhrMedicationFillEvidence::query()->where('patient_id', $patientId)->whereKey($relabel['id'])
                ->whereIn('source_kind', PhrMedicationFillEvidence::LINKER_KINDS)->update(['source_locator' => $relabel['locator']]);
        }
        foreach ($diff['add_links'] as $link) {
            PhrMedicationFillEvidence::query()->create([
                'patient_id' => $patientId,
                'fill_id' => $link['fill_id'],
                'eob_line_id' => $link['eob_line_id'],
                'source_kind' => PhrMedicationFillEvidence::KIND_AUTO_EOB_LINK,
                'source_locator' => $link['rule'],
                'evidence_key' => substr(hash('sha256', PhrMedicationFillEvidence::KIND_AUTO_EOB_LINK.'|'.$link['eob_line_id']), 0, 40),
            ]);
        }
        foreach ($diff['add_flags'] as $flag) {
            PhrMedicationFillEvidence::query()->create([
                'patient_id' => $patientId,
                'fill_id' => $flag['fill_id'],
                'source_kind' => PhrMedicationFillEvidence::KIND_EOB_LINK_OPEN,
                'source_locator' => $flag['reason'],
                'evidence_key' => substr(hash('sha256', PhrMedicationFillEvidence::KIND_EOB_LINK_OPEN), 0, 40),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $diff
     * @return array<string, int>
     */
    private function counts(array $diff): array
    {
        return [
            'links_added' => count($diff['add_links']),
            'links_removed' => $diff['links_removed'],
            'links_relabelled' => $diff['links_relabelled'],
            'flags_added' => count($diff['add_flags']),
            'flags_removed' => $diff['flags_removed'],
            'flags_relabelled' => $diff['flags_relabelled'],
        ];
    }

    /** The NDC when there is one, else the printed name; null when a fill names no drug at all. */
    private static function drugKey(mixed $ndc11, mixed $printed): ?string
    {
        if (is_string($ndc11) && $ndc11 !== '') {
            return 'ndc:'.$ndc11;
        }
        $name = is_string($printed) ? trim((string) preg_replace('/\s+/', ' ', mb_strtolower($printed))) : '';

        return $name === '' ? null : 'printed:'.$name;
    }

    /**
     * The claim's total charge in cents: the header, else the sum of its lines, else unknown.
     *
     * @param  Collection<int, object>  $lines
     */
    private static function charge(mixed $header, Collection $lines): ?int
    {
        if (is_numeric($header)) {
            return (int) round(((float) $header) * 100);
        }
        $charges = $lines->pluck('total_charges')->filter(static fn (mixed $value): bool => is_numeric($value));

        return $charges->isEmpty() ? null : (int) round($charges->sum(static fn (mixed $value): float => (float) $value) * 100);
    }
}

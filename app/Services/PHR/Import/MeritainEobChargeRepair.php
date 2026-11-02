<?php

namespace App\Services\PHR\Import;

use App\Models\PhrEob;
use App\Models\PhrEobLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Restores charges an earlier parser lost on lines of the older EOB layout, from each line's stored
 * printed text. It only fills an empty charge, and never changes another value.
 *
 * A claim's fingerprint covers its line charges, so it moves too: otherwise importing the same PDF
 * again would no longer recognise the claim and would add it twice. The stored fingerprint must
 * first match one recomputed from the stored rows, proving that recomputing describes this claim.
 * A claim that doesn't match is left alone and counted.
 */
final readonly class MeritainEobChargeRepair
{
    public function __construct(
        private MeritainEobParser $parser,
        private MeritainEobFingerprint $fingerprint,
    ) {}

    /** @return array{claims_repaired: int, lines_repaired: int, claims_skipped: int} */
    public function run(?int $patientId, bool $dryRun): array
    {
        $counts = ['claims_repaired' => 0, 'lines_repaired' => 0, 'claims_skipped' => 0];
        $claimIds = PhrEobLine::query()->whereNull('total_charges')
            ->when($patientId !== null, static fn ($query) => $query->where('patient_id', $patientId))
            ->whereIn('eob_id', PhrEob::query()->select('id')->where('import_source', MeritainEobImporter::IMPORT_SOURCE))
            ->distinct()->orderBy('eob_id')->pluck('eob_id');

        foreach ($claimIds as $claimId) {
            $outcome = DB::transaction(fn (): array => $this->repairClaim((int) $claimId, $dryRun));
            $counts['lines_repaired'] += $outcome['lines'];
            $counts['claims_repaired'] += $outcome['lines'] > 0 ? 1 : 0;
            $counts['claims_skipped'] += $outcome['skipped'] ? 1 : 0;
        }

        return $counts;
    }

    /** @return array{lines: int, skipped: bool} */
    private function repairClaim(int $claimId, bool $dryRun): array
    {
        $claim = PhrEob::query()->lockForUpdate()->findOrFail($claimId);
        /** @var Collection<int, PhrEobLine> $lines */
        $lines = $claim->lines()->orderBy('line_number')->orderBy('id')->get();
        if ($this->fingerprintOf($claim, $lines, []) !== $claim->claim_fingerprint) {
            return ['lines' => 0, 'skipped' => true];
        }

        $charges = [];
        foreach ($lines as $line) {
            $parsed = $line->getAttribute('parsed_data');
            $columns = is_array($parsed) ? ($parsed['columns'] ?? null) : null;
            if ($line->total_charges === null && is_string($line->raw_text) && is_array($columns) && isset($columns['total_charges'])) {
                $charge = $this->parser->chargeFromLegacyLine($line->raw_text, $columns);
                if ($charge !== null) {
                    $charges[(int) $line->id] = $charge;
                }
            }
        }
        if ($charges === []) {
            return ['lines' => 0, 'skipped' => false];
        }

        $repaired = $this->fingerprintOf($claim, $lines, $charges);
        $taken = PhrEob::query()->whereKeyNot($claim->id)->where('patient_id', $claim->patient_id)
            ->where('import_source', $claim->import_source)->where('claim_fingerprint', $repaired)->exists();
        if ($taken) {
            return ['lines' => 0, 'skipped' => true];
        }

        if (! $dryRun) {
            foreach ($lines as $line) {
                if (isset($charges[(int) $line->id])) {
                    $line->forceFill(['total_charges' => $charges[(int) $line->id]])->save();
                }
            }
            $claim->forceFill(['claim_fingerprint' => $repaired, 'parser_version' => MeritainEobParser::PARSER_VERSION])->save();
        }

        return ['lines' => count($charges), 'skipped' => false];
    }

    /**
     * @param  Collection<int, PhrEobLine>  $lines
     * @param  array<int, string>  $charges  line id => charge to use instead of the stored one
     */
    private function fingerprintOf(PhrEob $claim, Collection $lines, array $charges): string
    {
        return $this->fingerprint->fromParsed([
            ...$claim->only([
                'claim_number', 'claim_type', 'processed_date', 'provider_name', 'total_charges', 'total_provider_discount',
                'total_ineligible_amount', 'total_deductible_applied', 'total_copay_applied', 'total_carrier_payment',
                'total_plan_payment', 'total_patient_responsibility',
            ]),
            'lines' => $lines->map(static fn (PhrEobLine $line): array => [
                ...$line->only([
                    'procedure_code', 'revenue_code', 'service_start', 'service_end', 'provider_discount', 'ineligible_amount',
                    'deductible_applied', 'copay_applied', 'carrier_payment', 'plan_payment', 'patient_responsibility',
                ]),
                'total_charges' => $charges[(int) $line->id] ?? $line->total_charges,
            ])->all(),
        ]);
    }
}

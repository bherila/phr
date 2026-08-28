<?php

namespace App\Services\PHR\Import;

use App\Models\PhrPatient;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Coordinates the existing EOB reconcilers into an explicit preview/apply
 * contract. The preview is deliberately counts-only: a client can decide
 * whether to apply without a second, unbounded PHI projection.
 */
final readonly class PhrEobReconciliationService
{
    public const string MERITAIN_VISITS = 'meritain-eob-visits';

    public const string MERITAIN_ALLERGY_PROCEDURES = 'meritain-eob-allergy-procedures';

    public const string DELTA_DENTAL_VISITS = 'delta-dental-eob-visits';

    /** @var list<string> */
    public const array TYPES = [
        self::MERITAIN_VISITS,
        self::MERITAIN_ALLERGY_PROCEDURES,
        self::DELTA_DENTAL_VISITS,
    ];

    public function __construct(
        private MeritainEobVisitReconciler $meritainVisits,
        private MeritainEobAllergyProcedureReconciler $meritainAllergyProcedures,
        private DeltaDentalEobVisitReconciler $deltaDentalVisits,
    ) {}

    /** @return array{reconciliation: string, summary: array<string, int>, preview_digest: string} */
    public function preview(PhrPatient $patient, string $reconciliation): array
    {
        $summary = $this->run($patient, $reconciliation, true);

        return [
            'reconciliation' => $reconciliation,
            'summary' => $summary,
            'preview_digest' => $this->digest($patient, $reconciliation, $summary),
        ];
    }

    /**
     * Recalculate inside the mutation transaction and fail closed whenever the
     * requested digest no longer describes the proposed mutation.
     *
     * @return array{reconciliation: string, summary: array<string, int>, preview_digest: string}
     */
    public function apply(PhrPatient $patient, string $reconciliation, string $expectedDigest): array
    {
        return DB::transaction(function () use ($patient, $reconciliation, $expectedDigest): array {
            $preview = $this->preview($patient, $reconciliation);
            if (! hash_equals($preview['preview_digest'], $expectedDigest)) {
                throw new PhrReconciliationPreviewChangedException;
            }

            $this->run($patient, $reconciliation, false);

            return $preview;
        });
    }

    /** @return array<string, int> */
    private function run(PhrPatient $patient, string $reconciliation, bool $dryRun): array
    {
        $summary = match ($reconciliation) {
            self::MERITAIN_VISITS => $this->meritainVisits->reconcile($patient, $dryRun),
            self::MERITAIN_ALLERGY_PROCEDURES => $this->meritainAllergyProcedures->reconcile($patient, $dryRun),
            self::DELTA_DENTAL_VISITS => $this->deltaDentalVisits->reconcile($patient, $dryRun),
            default => throw new InvalidArgumentException('Unsupported reconciliation.'),
        };

        /** @var array<string, int> $summary */
        return $summary;
    }

    /** @param array<string, int> $summary */
    private function digest(PhrPatient $patient, string $reconciliation, array $summary): string
    {
        ksort($summary, SORT_STRING);

        return hash('sha256', json_encode([
            'patient_id' => (int) $patient->id,
            'reconciliation' => $reconciliation,
            'summary' => $summary,
        ], JSON_THROW_ON_ERROR));
    }
}

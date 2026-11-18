<?php

namespace App\Services\PHR\Import\Ehi;

use App\Services\PHR\ResourceByteSize;
use Illuminate\Support\Facades\DB;

/** Conservative patient-scoped M2 work set, including history reachable by a new package. */
final class EhiPreviewBudget
{
    /** Tables charged by admission; also used by the isolated calibration harness. */
    public const array TABLES = [
        'phr_ehi_sources', 'phr_ehi_packages', 'phr_ehi_package_members',
        'phr_ehi_entities', 'phr_ehi_revisions', 'phr_ehi_occurrences',
        'phr_ehi_proposals', 'phr_ehi_proposal_revisions', 'phr_ehi_record_links',
        'phr_ehi_proposal_decisions', 'phr_documents', 'phr_immunizations', 'phr_lab_results', 'phr_office_visits',
    ];

    /**
     * Binding/intake/projection/review/native restore mutations serialize on the
     * patient row lock. External FK cleanup can only shrink this evidence usage.
     * Any future charged table defaults to fresh rescanning unless listed here.
     */
    private const array GUARDED_TABLES = [
        'phr_ehi_sources', 'phr_ehi_packages', 'phr_ehi_package_members',
        'phr_ehi_entities', 'phr_ehi_revisions', 'phr_ehi_occurrences',
        'phr_ehi_proposals', 'phr_ehi_proposal_revisions', 'phr_ehi_record_links', 'phr_ehi_proposal_decisions',
    ];

    private readonly float $started;

    public function __construct(private readonly ResourceByteSize $rowSizes = new ResourceByteSize)
    {
        $this->started = (hrtime(true) / 1_000_000_000);
    }

    public function checkpoint(): void
    {
        if ((hrtime(true) / 1_000_000_000) - $this->started > (int) config('phr.ehi_preview_max_seconds')) {
            throw new EhiProjectionRefused(EhiProjectionRefused::RESOURCE_LIMIT, 'the preview time budget was exceeded', resourceLimit: EhiPreviewLimit::Time);
        }
    }

    public function charge(int $rows, int $bytes): void
    {
        $this->checkpoint();
        if ($rows > (int) config('phr.ehi_preview_max_rows') || $bytes > (int) config('phr.ehi_preview_max_bytes')) {
            throw new EhiProjectionRefused(EhiProjectionRefused::RESOURCE_LIMIT, 'the preview work set exceeds the supported row or byte limit', resourceLimit: EhiPreviewLimit::Capacity);
        }
    }

    /** Recheck at materialization boundaries; SQL aggregates do not hydrate JSON/models. */
    public function assertPatient(int $patientId, int $additionalRows = 0, int $additionalBytes = 0): void
    {
        $this->measureTables($patientId, self::TABLES, $additionalRows, $additionalBytes);
    }

    /**
     * Measure before hydrating proposal payloads inside the patient write guard.
     * Only the EHI evidence totals may be reused until this guarded chunk writes:
     * application mutations take the same patient lock, and unguarded FK cleanup
     * only shrinks them. Clinical edits do not all do so, and reservePatient()
     * therefore measures every unlisted table again.
     */
    public function measurePatient(int $patientId): EhiPreviewEvidenceUsage
    {
        $evidence = $this->measureTables($patientId, array_values(array_intersect(self::TABLES, self::GUARDED_TABLES)));
        $this->measureTables($patientId, array_values(array_diff(self::TABLES, self::GUARDED_TABLES)), $evidence['rows'], $evidence['bytes']);

        return new EhiPreviewEvidenceUsage($patientId, $evidence['rows'], $evidence['bytes']);
    }

    /** Reuse only within the same patient guard, before any intervening write. */
    public function reservePatient(EhiPreviewEvidenceUsage $evidence, int $additionalRows, int $additionalBytes): void
    {
        $this->measureTables($evidence->patientId, array_values(array_diff(self::TABLES, self::GUARDED_TABLES)), $evidence->rows + $additionalRows, $evidence->bytes + $additionalBytes);
    }

    /**
     * @param  list<string>  $tables
     * @return array{rows: int, bytes: int}
     */
    private function measureTables(int $patientId, array $tables, int $rows = 0, int $bytes = 0): array
    {
        $this->charge($rows, $bytes);
        foreach ($tables as $table) {
            $size = $this->rowSizes->row($table);
            $probe = DB::table($table)->where('patient_id', $patientId)->orderBy('id')
                ->limit(max(1, (int) config('phr.ehi_preview_max_rows') - $rows + 1))->selectRaw($size.' AS raw_bytes');
            $totals = DB::query()->fromSub($probe, 'preview_work')
                ->selectRaw('COUNT(*) AS row_count, COALESCE(SUM(raw_bytes), 0) AS raw_bytes')->first();
            $rows += (int) $totals->row_count;
            $bytes += (int) $totals->raw_bytes;
            $this->charge($rows, $bytes);
        }

        return ['rows' => $rows, 'bytes' => $bytes];
    }
}

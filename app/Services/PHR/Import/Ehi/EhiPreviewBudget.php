<?php

namespace App\Services\PHR\Import\Ehi;

use App\Services\PHR\ResourceByteSize;
use Illuminate\Support\Facades\DB;

/** Conservative patient-scoped M2 work set, including history reachable by a new package. */
final class EhiPreviewBudget
{
    private const array TABLES = [
        'phr_ehi_sources', 'phr_ehi_packages', 'phr_ehi_package_members',
        'phr_ehi_entities', 'phr_ehi_revisions', 'phr_ehi_occurrences',
        'phr_ehi_proposals', 'phr_ehi_proposal_revisions', 'phr_ehi_record_links',
        'phr_ehi_proposal_decisions', 'phr_documents', 'phr_immunizations',
    ];

    private readonly float $started;

    public function __construct(private readonly ResourceByteSize $rowSizes = new ResourceByteSize)
    {
        $this->started = (hrtime(true) / 1_000_000_000);
    }

    public function checkpoint(): void
    {
        if ((hrtime(true) / 1_000_000_000) - $this->started > (int) config('phr.ehi_preview_max_seconds')) {
            throw new EhiProjectionRefused(EhiProjectionRefused::RESOURCE_LIMIT, 'the preview time budget was exceeded');
        }
    }

    public function charge(int $rows, int $bytes): void
    {
        $this->checkpoint();
        if ($rows > (int) config('phr.ehi_preview_max_rows') || $bytes > (int) config('phr.ehi_preview_max_bytes')) {
            throw new EhiProjectionRefused(EhiProjectionRefused::RESOURCE_LIMIT, 'the preview work set exceeds the supported row or byte limit');
        }
    }

    /** Recheck at materialization boundaries; SQL aggregates do not hydrate JSON/models. */
    public function assertPatient(int $patientId, int $additionalRows = 0, int $additionalBytes = 0): void
    {
        $rows = $additionalRows;
        $bytes = $additionalBytes;
        $this->charge($rows, $bytes);
        foreach (self::TABLES as $table) {
            $size = $this->rowSizes->row($table);
            $probe = DB::table($table)->where('patient_id', $patientId)->orderBy('id')
                ->limit(max(1, (int) config('phr.ehi_preview_max_rows') - $rows + 1))->selectRaw($size.' AS raw_bytes');
            $totals = DB::query()->fromSub($probe, 'preview_work')
                ->selectRaw('COUNT(*) AS row_count, COALESCE(SUM(raw_bytes), 0) AS raw_bytes')->first();
            $rows += (int) $totals->row_count;
            $bytes += (int) $totals->raw_bytes;
            $this->charge($rows, $bytes);
        }
    }
}

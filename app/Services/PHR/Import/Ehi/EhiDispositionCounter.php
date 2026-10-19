<?php

namespace App\Services\PHR\Import\Ehi;

use App\Models\PhrEhiOccurrence;
use App\Models\PhrEhiTableAccount;

/**
 * Re-derives a mapped table's disposition counts from its occurrences, so the
 * account always reconciles: every valid row either has exactly one occurrence
 * or was quarantined without one (an empty primary-key cell), and
 * source_row_count = malformed + sum(dispositions) holds by construction.
 */
final class EhiDispositionCounter
{
    /** Every disposition a mapped table reports, in display order. */
    public const array DISPOSITIONS = [
        PhrEhiOccurrence::DISPOSITION_PROJECTED,
        PhrEhiOccurrence::DISPOSITION_CORROBORATING,
        PhrEhiOccurrence::DISPOSITION_PENDING_REVIEW,
        PhrEhiOccurrence::DISPOSITION_RETAINED,
        PhrEhiOccurrence::DISPOSITION_QUARANTINED,
    ];

    /** Call inside the caller's patient-locked transaction. */
    public static function recount(PhrEhiTableAccount $account): void
    {
        if ($account->member_id === null) {
            return;
        }

        $counts = array_fill_keys(self::DISPOSITIONS, 0);
        $occurrences = 0;
        $grouped = PhrEhiOccurrence::query()->where('member_id', $account->member_id)
            ->selectRaw('disposition, count(*) as aggregate')->groupBy('disposition')->pluck('aggregate', 'disposition');
        foreach ($grouped as $disposition => $count) {
            $counts[(string) $disposition] = ($counts[(string) $disposition] ?? 0) + (int) $count;
            $occurrences += (int) $count;
        }
        $valid = $account->source_row_count - $account->malformed_row_count;
        $counts[PhrEhiOccurrence::DISPOSITION_QUARANTINED] += max(0, $valid - $occurrences);

        $account->forceFill(['disposition_counts_json' => $counts])->save();
    }
}

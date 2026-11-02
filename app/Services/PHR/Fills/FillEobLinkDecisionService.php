<?php

namespace App\Services\PHR\Fills;

use App\Models\PhrEob;
use App\Models\PhrMedicationFill;
use App\Models\PhrMedicationFillEvidence;
use App\Models\PhrPatient;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A person's answer about a fill's pharmacy claim, from the web app or relayed by an agent they
 * told. Either the fill is on this claim, which is recorded on all the claim's lines like a link
 * any client makes and so is the linker's to respect and never to change; or it is on none of the
 * claims on offer, or not on the one it was linked to automatically. Those two are remembered, so
 * the fill is neither asked about again nor linked automatically later, until the person resets it.
 *
 * Only evidence is written here. The fill's review status and its version are not touched.
 */
final readonly class FillEobLinkDecisionService
{
    public const string LINK = 'link';

    public const string NONE = 'none';

    public const string REJECT = 'reject';

    public const string RESET = 'reset';

    public const array ACTIONS = [self::LINK, self::NONE, self::REJECT, self::RESET];

    public const string LINKED = 'linked';

    public const string DISMISSED = 'dismissed';

    public const string REJECTED = 'rejected';

    public const string RESTORED = 'restored';

    public const string UNCHANGED = 'unchanged';

    public function __construct(
        private FillEobLinker $linker,
        private MedicationFillEvidenceService $evidence,
    ) {}

    public function decide(PhrPatient $patient, int $fillId, string $action, ?int $eobId): string
    {
        return DB::transaction(function () use ($patient, $fillId, $action, $eobId): string {
            PhrPatient::query()->whereKey($patient->id)->lockForUpdate()->first();
            $fill = PhrMedicationFill::query()->where('patient_id', $patient->id)->whereNull('retracted_at')
                ->where('review_status', '!=', PhrReviewStatus::REJECTED)->findOrFail($fillId);

            return match ($action) {
                self::NONE => $this->dismiss($patient, $fill),
                self::REJECT => $this->reject($patient, $fill),
                self::RESET => $this->reset($patient, $fill),
                default => $this->link($patient, $fill, (int) $eobId),
            };
        }, 3);
    }

    private function link(PhrPatient $patient, PhrMedicationFill $fill, int $eobId): string
    {
        $eob = PhrEob::query()->where('patient_id', $patient->id)->where('claim_type', 'pharmacy')->findOrFail($eobId);
        $lines = $this->linker->linesOfClaim((int) $patient->id, (int) $eob->id);

        // The same answer arriving again finds it recorded.
        $recorded = PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)->whereIn('eob_line_id', $lines)
            ->whereNotIn('source_kind', PhrMedicationFillEvidence::LINKER_KINDS)->exists();
        if ($recorded) {
            return self::UNCHANGED;
        }
        $onOffer = array_column($this->linker->candidates((int) $patient->id)[(int) $fill->id] ?? [], 'eob_id');
        if (! in_array((int) $eob->id, $onOffer, true)) {
            throw new ConflictHttpException('This claim is no longer on offer for this fill. Reload and choose again.');
        }
        foreach ($lines as $lineId) {
            $this->evidence->record($patient, $fill, null, $lineId, 'eob', null);
        }

        return self::LINKED;
    }

    private function dismiss(PhrPatient $patient, PhrMedicationFill $fill): string
    {
        if ($this->dismissal($fill)->exists()) {
            return self::UNCHANGED;
        }
        if (($this->linker->candidates((int) $patient->id)[(int) $fill->id] ?? []) === []) {
            throw new ConflictHttpException('This fill has no claim waiting for an answer.');
        }
        $this->recordDismissal($patient, $fill);

        return self::DISMISSED;
    }

    /** Frees the claim for another fill that day, so the rules may give it to the right one. */
    private function reject(PhrPatient $patient, PhrMedicationFill $fill): string
    {
        if ($this->dismissal($fill)->exists()) {
            return self::UNCHANGED;
        }
        $linked = PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)
            ->where('source_kind', PhrMedicationFillEvidence::KIND_AUTO_EOB_LINK)->exists();
        if (! $linked) {
            throw new ConflictHttpException('This fill has no automatic claim to reject.');
        }
        $this->recordDismissal($patient, $fill);

        return self::REJECTED;
    }

    private function reset(PhrPatient $patient, PhrMedicationFill $fill): string
    {
        $dismissal = $this->dismissal($fill)->first();
        if ($dismissal === null) {
            return self::UNCHANGED;
        }
        $dismissal->delete();
        FillEobLinkTrigger::schedule((int) $patient->id);

        return self::RESTORED;
    }

    /** @return Builder<PhrMedicationFillEvidence> */
    private function dismissal(PhrMedicationFill $fill): Builder
    {
        return PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)
            ->where('source_kind', PhrMedicationFillEvidence::KIND_EOB_LINK_DISMISSED);
    }

    private function recordDismissal(PhrPatient $patient, PhrMedicationFill $fill): void
    {
        PhrMedicationFillEvidence::query()->create([
            'patient_id' => $patient->id,
            'fill_id' => $fill->id,
            'source_kind' => PhrMedicationFillEvidence::KIND_EOB_LINK_DISMISSED,
            'evidence_key' => substr(hash('sha256', PhrMedicationFillEvidence::KIND_EOB_LINK_DISMISSED), 0, 40),
        ]);
        FillEobLinkTrigger::schedule((int) $patient->id);
    }
}

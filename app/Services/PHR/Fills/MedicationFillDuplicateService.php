<?php

namespace App\Services\PHR\Fills;

use App\Models\PhrMedicationFill;
use App\Models\PhrMedicationFillEvidence;
use App\Models\PhrPatient;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A person's (or their agent's) answer to a fill flagged `possible_duplicate`.
 *
 * `same` merges the flagged fill into the existing one it may duplicate: its evidence
 * (other documents, EOB lines, connection aliases) moves onto the existing fill, blanks
 * on the existing fill are filled from it, and the flagged fill is retracted, not deleted.
 * `different` clears the flag and leaves both fills standing. Either way the flag row is
 * kept, relabelled with the decision, so the history stays and a later write that matches
 * the same pair does not raise the flag again.
 */
final readonly class MedicationFillDuplicateService
{
    public const string SAME = 'same';

    public const string DIFFERENT = 'different';

    public const string MERGED = 'merged';

    public const string CLEARED = 'cleared';

    public const string UNCHANGED = 'unchanged';

    /** Flag rows that are no longer open, by the decision that closed them. */
    public const string KIND_MERGED = 'duplicate_merged';

    public const string KIND_DISMISSED = 'duplicate_dismissed';

    /** Fields the flagged fill may supply to the existing fill when the latter has none. */
    private const array BLANKS = ['rx_number', 'pharmacy_name', 'pharmacy_location'];

    public function __construct(
        private AgentClinicalRecordVersion $versions,
        private MedicationFillEvidenceService $evidence,
        private MedicationFillWriteExtension $extension,
    ) {}

    /**
     * @return array{outcome: string, action: string, fill_id: int, merged_into_fill_id: int|null, moved: array{documents: int, eob_lines: int}, version: string, fill: PhrMedicationFill}
     */
    public function resolve(PhrPatient $patient, int $fillId, string $action, string $expectedVersion, ?int $candidateId = null, ?string $importSource = null): array
    {
        if (! in_array($action, [self::SAME, self::DIFFERENT], true)) {
            throw ValidationException::withMessages(['action' => 'The action must be same or different.']);
        }

        return DB::transaction(function () use ($patient, $fillId, $action, $expectedVersion, $candidateId, $importSource): array {
            $query = PhrMedicationFill::withTrashed()->where('patient_id', $patient->id)->lockForUpdate();
            if ($importSource !== null) {
                $query->where('import_source', $importSource);
            }
            $fill = $query->find($fillId);
            abort_if($fill === null || $fill->deleted_at !== null, 404);

            $current = $this->versions->for($fill);
            if (! hash_equals($current, $expectedVersion)) {
                throw new ConflictHttpException('This fill changed since it was loaded. Reload it and review it again.');
            }

            $flags = PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)
                ->where('source_kind', 'possible_duplicate')->whereNotNull('related_fill_id')
                ->when($candidateId !== null, fn ($q) => $q->where('related_fill_id', $candidateId))
                ->orderBy('id')->lockForUpdate()->get();

            if ($flags->isEmpty()) {
                // The same decision arriving again finds the flag already closed.
                $closed = PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)
                    ->where('source_kind', $action === self::SAME ? self::KIND_MERGED : self::KIND_DISMISSED)
                    ->when($candidateId !== null, fn ($q) => $q->where('related_fill_id', $candidateId))
                    ->orderBy('id')->first();
                if ($closed !== null) {
                    return $this->result(self::UNCHANGED, $action, $fill, $action === self::SAME ? (int) $closed->related_fill_id : null, 0, 0);
                }
                throw new ConflictHttpException('This fill has no open possible-duplicate flag.');
            }

            if ($action === self::DIFFERENT) {
                foreach ($flags as $flag) {
                    $flag->update(['source_kind' => self::KIND_DISMISSED]);
                }
                $fill->touch();

                return $this->result(self::CLEARED, $action, $fill->refresh(), null, 0, 0);
            }

            if ($flags->count() > 1) {
                throw ValidationException::withMessages(['candidate_fill_id' => 'This fill may duplicate more than one fill; say which one it is the same as.']);
            }
            $flag = $flags->first();
            $candidate = PhrMedicationFill::query()->where('patient_id', $patient->id)
                ->whereNull('retracted_at')->whereKeyNot($fill->id)->lockForUpdate()->find((int) $flag->related_fill_id);
            if ($candidate === null || $candidate->review_status === PhrReviewStatus::REJECTED) {
                throw new ConflictHttpException('The fill this may duplicate is no longer in the record. Mark it as a different fill instead.');
            }

            [$documents, $eobLines] = $this->moveEvidence($patient, $fill, $candidate);
            $this->fillBlanks($fill, $candidate);

            $flag->update(['source_kind' => self::KIND_MERGED]);
            // A flag on the existing fill that pointed at this one is answered too.
            PhrMedicationFillEvidence::query()->where('fill_id', $candidate->id)->where('related_fill_id', $fill->id)
                ->where('source_kind', 'possible_duplicate')->update(['source_kind' => self::KIND_MERGED]);

            $fill->forceFill(['retracted_at' => now()])->save();
            $this->extension->afterRetract($patient, $fill);

            return $this->result(self::MERGED, $action, $fill->refresh(), (int) $candidate->id, $documents, $eobLines);
        });
    }

    /** @return array{0: int, 1: int} documents and EOB lines now vouching for the existing fill */
    private function moveEvidence(PhrPatient $patient, PhrMedicationFill $fill, PhrMedicationFill $candidate): array
    {
        $documents = 0;
        $eobLines = 0;
        // The document the flagged fill was first read from now also shows the existing fill.
        if ($this->evidence->record($patient, $candidate, $fill->source_document_id, null, (string) $fill->source_kind, $fill->source_locator)) {
            $documents++;
        }

        $held = PhrMedicationFillEvidence::query()->where('fill_id', $candidate->id)->pluck('evidence_key')->all();
        $rows = PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)
            ->whereNotIn('source_kind', ['possible_duplicate', self::KIND_MERGED, self::KIND_DISMISSED])
            ->orderBy('id')->get();
        foreach ($rows as $row) {
            if (in_array($row->evidence_key, $held, true)) {
                continue;
            }
            $row->update(['fill_id' => $candidate->id]);
            $held[] = $row->evidence_key;
            $documents += $row->document_id !== null ? 1 : 0;
            $eobLines += $row->eob_line_id !== null ? 1 : 0;
        }
        // A retry of the flagged fill's own identity lands on the existing fill.
        if ($fill->import_source !== null && $fill->external_id !== null) {
            $this->evidence->alias($patient, $candidate, (string) $fill->import_source, (string) $fill->external_id);
        }

        return [$documents, $eobLines];
    }

    private function fillBlanks(PhrMedicationFill $fill, PhrMedicationFill $candidate): void
    {
        $filled = [];
        foreach (self::BLANKS as $field) {
            if (($candidate->getAttribute($field) === null || $candidate->getAttribute($field) === '') && $fill->getAttribute($field) !== null && $fill->getAttribute($field) !== '') {
                $filled[$field] = $fill->getAttribute($field);
            }
        }
        if ($filled === []) {
            return;
        }
        // A field the person already confirmed was filled in from another source: look again.
        if ($candidate->review_status === PhrReviewStatus::CONFIRMED) {
            $filled['review_status'] = PhrReviewStatus::PENDING;
        }
        $candidate->update($filled);
    }

    /** @return array{outcome: string, action: string, fill_id: int, merged_into_fill_id: int|null, moved: array{documents: int, eob_lines: int}, version: string, fill: PhrMedicationFill} */
    private function result(string $outcome, string $action, PhrMedicationFill $fill, ?int $into, int $documents, int $eobLines): array
    {
        return [
            'outcome' => $outcome,
            'action' => $action,
            'fill_id' => (int) $fill->id,
            'merged_into_fill_id' => $into,
            'moved' => ['documents' => $documents, 'eob_lines' => $eobLines],
            'version' => $this->versions->for($fill),
            'fill' => $fill,
        ];
    }
}

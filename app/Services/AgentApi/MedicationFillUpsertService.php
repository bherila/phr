<?php

namespace App\Services\AgentApi;

use App\DataTransferObjects\AgentApi\ClinicalUpsertData;
use App\DataTransferObjects\AgentApi\ClinicalUpsertResult;
use App\DataTransferObjects\AgentApi\MedicationFillUpsertResult;
use App\Models\PhrDocument;
use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use App\Services\PHR\Fills\MedicationFillDrugResolver;
use App\Services\PHR\Fills\MedicationFillEvidenceService;
use App\Services\PHR\Fills\MedicationFillMatcher;
use App\Services\PHR\Fills\MedicationFillWriter;
use App\Services\PHR\Ndc\NdcResolution;
use App\Support\AgentApi\AgentApiClientIdentity;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The idempotent write of one prescription fill, on the same identity,
 * version and review rules as every other agent-written clinical record, plus
 * what is particular to a fill: the drug is resolved from the NDC by the
 * server, the fill is attached to a medication (existing or created), and the
 * fill is never duplicated across sources.
 *
 * Three things can make a write land on a fill the patient already has:
 *  1. its own identity (this client's external_id, derived from pharmacy, Rx
 *     number and fill date), which is an ordinary idempotent retry or update;
 *  2. a confident cross-source match ({@see MedicationFillMatcher}): the label,
 *     the receipt and the EOB line of one dispensing are one fill, the new source
 *     is recorded as evidence and only the fill's blanks are filled in;
 *  3. an uncertain match, which creates the fill but flags it, with the fill it
 *     may duplicate, for a person to settle.
 */
final readonly class MedicationFillUpsertService
{
    public function __construct(
        private AgentClinicalRecordVersion $versions,
        private MedicationFillWriter $writer,
        private MedicationFillMatcher $matcher,
        private MedicationFillEvidenceService $evidence,
    ) {}

    public function upsert(PhrPatient $patient, AgentApiClientIdentity $client, ClinicalUpsertData $data): MedicationFillUpsertResult
    {
        return $this->upsertFromSource($patient, $client->importSource(), $data);
    }

    /**
     * The same write for a source that is not an OAuth client, such as phr's own
     * AI extraction (import source "genai").
     */
    public function upsertFromSource(PhrPatient $patient, string $importSource, ClinicalUpsertData $data): MedicationFillUpsertResult
    {
        if ($data->sourceDocumentId !== null) {
            PhrDocument::query()->where('patient_id', $patient->id)->findOrFail($data->sourceDocumentId);
        }
        /** @var array{patient_id: int, import_source: string, external_id: string} $identity */
        $identity = [
            'patient_id' => $patient->id,
            'import_source' => $importSource,
            'external_id' => $data->externalId,
        ];

        $eobLineId = isset($data->attributes['eob_line_id']) ? (int) $data->attributes['eob_line_id'] : null;
        $this->evidence->assertEobLine($patient, $eobLineId);

        // Resolving the NDC may call openFDA, so it happens before the transaction and its row
        // locks. A same-NDC retry skips it by comparing with the stored row, which is only sound
        // while the row is still as it was read: the locked row is checked against that snapshot,
        // and the preparation is redone if another request changed it in between.
        for ($attempt = 1; ; $attempt++) {
            $peek = PhrMedicationFill::query()->withoutGlobalScope(SoftDeletingScope::class)->where($identity)->first();
            $live = $peek?->deleted_at === null && $peek?->retracted_at === null ? $peek : null;
            $prepared = $this->writer->prepare($patient, $data->attributes, $live, false);
            $snapshot = $this->ndcState($live);

            try {
                return DB::transaction(function () use ($patient, $data, $identity, $prepared, $snapshot, $eobLineId): MedicationFillUpsertResult {
                    // Serializes the writers of one patient's fills, so two sources reading the
                    // same dispensing at once cannot both decide it is new.
                    PhrPatient::query()->whereKey($patient->id)->lockForUpdate()->first();

                    $result = $this->write($patient, $data, $identity, $prepared, $snapshot);
                    $this->evidence->record(
                        $patient,
                        $result->record,
                        $data->sourceDocumentId,
                        $eobLineId,
                        $eobLineId !== null ? 'eob' : (string) ($data->attributes['source_kind'] ?? 'manual'),
                        isset($data->attributes['source_locator']) ? (string) $data->attributes['source_locator'] : null,
                    );
                    if ($result->dedupe['status'] === 'matched') {
                        $this->evidence->alias($patient, $result->record, $identity['import_source'], $identity['external_id']);
                    }

                    return $result;
                }, 3);
            } catch (StaleNdcPreparation) {
                if ($attempt >= 3) {
                    throw new ConflictHttpException('The fill changed while it was being written; fetch it and retry with its current version.');
                }
            }
        }
    }

    /**
     * What the preparation depended on: the stored NDC and drug text. Null when there is no live row.
     *
     * @return array<string, mixed>|null
     */
    private function ndcState(?PhrMedicationFill $fill): ?array
    {
        return $fill === null ? null : [
            'ndc_as_captured' => $fill->ndc_as_captured,
            'ndc_status' => $fill->ndc_status,
            'ndc_10' => $fill->ndc_10,
            'drug_as_printed' => $fill->drug_as_printed,
        ];
    }

    /**
     * @param  array{patient_id: int, import_source: string, external_id: string}  $identity
     * @param  array{attributes: array<string, mixed>, resolution: ?NdcResolution, medication_id: ?int, drug_changed: bool}  $prepared
     * @param  array<string, mixed>|null  $snapshot
     */
    private function write(PhrPatient $patient, ClinicalUpsertData $data, array $identity, array $prepared, ?array $snapshot): MedicationFillUpsertResult
    {
        // Identity survives deletion and retraction: reviving a withdrawn fill would
        // resurrect something a person deleted or the source took back. createOrFirst
        // applies the soft-delete scope and would not see such a row, so the identity row
        // is checked under a lock first and again if two first writes race.
        $this->refuseWithdrawnIdentity($identity);

        $attributes = $prepared['attributes'];
        $dedupe = ['status' => 'none', 'matched_by' => null, 'matched_fill_id' => null];
        if (! PhrMedicationFill::query()->where($identity)->exists()) {
            // An identity this connection already merged into another fill stays there: a retry
            // lands on it again, and if a person deleted that fill it is not recreated.
            $aliased = $this->evidence->findAlias($patient, $identity['import_source'], $identity['external_id']);
            if ($aliased !== null) {
                if ($aliased->deleted_at !== null || $aliased->retracted_at !== null) {
                    throw new ConflictHttpException('The external identifier is no longer available for this patient.');
                }

                return $this->mergeInto($patient, $aliased, $prepared, 'external_id');
            }
            $match = $this->matcher->find($patient, $attributes);
            if ($match['status'] === MedicationFillMatcher::MATCHED && $match['fill'] !== null) {
                return $this->mergeInto($patient, $match['fill'], $prepared, (string) $match['by']);
            }
            if ($match['status'] === MedicationFillMatcher::UNCERTAIN && $match['fill'] !== null) {
                $attributes['review_flags'] = [[
                    'code' => 'possible_duplicate',
                    'message' => "This may be the same fill as fill {$match['fill']->id}: it matches on the fill date and drug, but the pharmacy, quantity or Rx number do not settle it. Keep one and reject the other if they are the same.",
                    'fill_id' => (int) $match['fill']->id,
                ]];
                $dedupe = ['status' => 'flagged', 'matched_by' => $match['by'], 'matched_fill_id' => (int) $match['fill']->id];
            }
        }

        try {
            /** @var PhrMedicationFill $existing */
            $existing = PhrMedicationFill::query()->createOrFirst($identity, [
                'user_id' => $patient->owner_user_id,
                'source_document_id' => $data->sourceDocumentId,
                // Every agent-created record enters the human review queue.
                'review_status' => PhrReviewStatus::PENDING,
                ...$attributes,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two first writes raced. The row exists now; whether it is usable is the
            // same question as before, asked under a lock.
            $this->refuseWithdrawnIdentity($identity);
            $existing = PhrMedicationFill::query()->where($identity)->firstOrFail();
        }
        if ($existing->wasRecentlyCreated) {
            $attached = $this->writer->attach($patient, $existing, $prepared['medication_id']);
            $existing->refresh();

            return new MedicationFillUpsertResult(
                $existing,
                ClinicalUpsertResult::CREATED,
                $this->versions->for($existing),
                $attached['medication'],
                $attached['created'],
                $prepared['resolution'],
                $dedupe,
            );
        }

        // Re-read under a row lock before comparing the opaque version.
        $existing = PhrMedicationFill::query()->where($identity)->lockForUpdate()->firstOrFail();
        // The preparation assumed the row as peeked. If another request changed its NDC since,
        // the drug asked for may differ from the stored one even though no field looks dirty;
        // that must not be accepted as an unchanged no-op.
        if ($this->ndcState($existing) !== $snapshot) {
            throw new StaleNdcPreparation;
        }
        $previousMedicationId = $existing->medication_id;
        $currentVersion = $this->versions->for($existing);
        $existing->fill(['source_document_id' => $data->sourceDocumentId, ...$attributes]);

        // A retried request whose desired state is already present is safe even
        // if it carries the version from before its first successful call.
        if (! $existing->isDirty()) {
            return new MedicationFillUpsertResult(
                $existing,
                ClinicalUpsertResult::UNCHANGED,
                $currentVersion,
                $existing->medication,
                false,
                $prepared['resolution'],
            );
        }
        if ($data->expectedVersion === null || ! hash_equals($currentVersion, $data->expectedVersion)) {
            throw new ConflictHttpException('The clinical record changed; fetch it and retry with its current version.');
        }

        // The record really is changing, so human review reopens.
        $existing->setAttribute('review_status', PhrReviewStatus::PENDING);
        $existing->save();
        $medication = $existing->medication;
        $created = false;
        if ($prepared['medication_id'] !== null || $existing->medication_id === null) {
            // Every link change goes through the linker so the medication it leaves is tidied.
            $attached = $this->writer->attach($patient, $existing, $prepared['medication_id'], $previousMedicationId);
            $medication = $attached['medication'];
            $created = $attached['created'];
        } elseif ($prepared['drug_changed']) {
            $attached = $this->writer->reconcile($patient, $existing);
            $medication = $attached['medication'];
            $created = $attached['created'];
        }
        $existing->refresh();

        return new MedicationFillUpsertResult(
            $existing,
            ClinicalUpsertResult::UPDATED,
            $this->versions->for($existing),
            $medication instanceof PhrMedication ? $medication : $existing->medication,
            $created,
            $prepared['resolution'],
        );
    }

    /**
     * The fill is one the patient already has, read from another source. Only the
     * fill's blanks are filled in (a receipt adds the price a label lacks); a value
     * already stored is never overwritten by a second source. The new source is
     * recorded as evidence by the caller.
     *
     * @param  array{attributes: array<string, mixed>, resolution: NdcResolution|null, medication_id: int|null, drug_changed: bool}  $prepared
     */
    private function mergeInto(PhrPatient $patient, PhrMedicationFill $existing, array $prepared, string $by): MedicationFillUpsertResult
    {
        $incoming = $prepared['attributes'];
        $skip = ['source_kind', 'source_locator', 'medication_id', 'review_flags', ...MedicationFillDrugResolver::DRUG_COLUMNS];
        foreach ($incoming as $column => $value) {
            if (in_array($column, $skip, true) || $value === null || $value === '') {
                continue;
            }
            $current = $existing->getAttribute($column);
            if ($current === null || $current === '') {
                $existing->setAttribute($column, $value);
            }
        }
        // A fill whose drug never resolved takes the drug another source resolved.
        $drugChanged = false;
        if ($existing->ndc_status !== PhrMedicationFill::NDC_RESOLVED && ($incoming['ndc_status'] ?? null) === PhrMedicationFill::NDC_RESOLVED) {
            foreach (MedicationFillDrugResolver::DRUG_COLUMNS as $column) {
                if (array_key_exists($column, $incoming)) {
                    $existing->setAttribute($column, $incoming[$column]);
                }
            }
            $drugChanged = true;
        }

        $outcome = ClinicalUpsertResult::UNCHANGED;
        if ($existing->isDirty()) {
            // The fill really is changing, so human review reopens.
            $existing->setAttribute('review_status', PhrReviewStatus::PENDING);
            $existing->save();
            $outcome = ClinicalUpsertResult::UPDATED;
        }
        $medication = $existing->medication;
        $created = false;
        if ($drugChanged) {
            $attached = $this->writer->reconcile($patient, $existing);
            $medication = $attached['medication'];
            $created = $attached['created'];
        } elseif ($existing->medication_id === null) {
            $attached = $this->writer->attach($patient, $existing, null);
            $medication = $attached['medication'];
            $created = $attached['created'];
        }
        $existing->refresh();

        return new MedicationFillUpsertResult(
            $existing,
            $outcome,
            $this->versions->for($existing),
            $medication instanceof PhrMedication ? $medication : $existing->medication,
            $created,
            $prepared['resolution'],
            ['status' => 'matched', 'matched_by' => $by, 'matched_fill_id' => (int) $existing->id],
        );
    }

    /**
     * @param  array{patient_id: int, import_source: string, external_id: string}  $identity
     */
    private function refuseWithdrawnIdentity(array $identity): void
    {
        $existing = PhrMedicationFill::query()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->where($identity)
            ->lockForUpdate()
            ->first();

        if ($existing !== null && ($existing->deleted_at !== null || $existing->retracted_at !== null)) {
            throw new ConflictHttpException('The external identifier is no longer available for this patient.');
        }
    }
}

<?php

namespace App\Services\AgentApi;

use App\DataTransferObjects\AgentApi\ClinicalUpsertData;
use App\DataTransferObjects\AgentApi\ClinicalUpsertResult;
use App\DataTransferObjects\AgentApi\MedicationFillUpsertResult;
use App\Models\PhrDocument;
use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use App\Services\PHR\Fills\MedicationFillWriter;
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
 * server, and the fill is attached to a medication (existing or created).
 */
final readonly class MedicationFillUpsertService
{
    public function __construct(
        private AgentClinicalRecordVersion $versions,
        private MedicationFillWriter $writer,
    ) {}

    public function upsert(PhrPatient $patient, AgentApiClientIdentity $client, ClinicalUpsertData $data): MedicationFillUpsertResult
    {
        if ($data->sourceDocumentId !== null) {
            PhrDocument::query()->where('patient_id', $patient->id)->findOrFail($data->sourceDocumentId);
        }
        /** @var array{patient_id: int, import_source: string, external_id: string} $identity */
        $identity = [
            'patient_id' => $patient->id,
            'import_source' => $client->importSource(),
            'external_id' => $data->externalId,
        ];

        // Resolving the NDC may call openFDA, so it happens before the transaction
        // and its row locks. A same-NDC retry skips it by comparing with the stored row.
        $peek = PhrMedicationFill::query()->withoutGlobalScope(SoftDeletingScope::class)->where($identity)->first();
        $prepared = $this->writer->prepare($patient, $data->attributes, $peek?->deleted_at === null && $peek?->retracted_at === null ? $peek : null, false);

        return DB::transaction(function () use ($patient, $data, $identity, $prepared): MedicationFillUpsertResult {
            // Identity survives deletion and retraction: reviving a withdrawn fill would
            // resurrect something a person deleted or the source took back. createOrFirst
            // applies the soft-delete scope and would not see such a row, so the identity row
            // is checked under a lock first and again if two first writes race.
            $this->refuseWithdrawnIdentity($identity);

            $attributes = $prepared['attributes'];
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
                );
            }

            // Re-read under a row lock before comparing the opaque version.
            $existing = PhrMedicationFill::query()->where($identity)->lockForUpdate()->firstOrFail();
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
        }, 3);
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

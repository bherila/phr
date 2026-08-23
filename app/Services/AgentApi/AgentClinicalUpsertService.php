<?php

namespace App\Services\AgentApi;

use App\DataTransferObjects\AgentApi\ClinicalUpsertData;
use App\DataTransferObjects\AgentApi\ClinicalUpsertResult;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Support\AgentApi\AgentApiClientIdentity;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class AgentClinicalUpsertService
{
    public function __construct(private AgentClinicalRecordVersion $versions) {}

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $identity
     */
    private function refuseWithdrawnIdentity(string $modelClass, array $identity): void
    {
        $existing = $modelClass::query()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->where($identity)
            ->lockForUpdate()
            ->first();

        if ($existing instanceof Model
            && ($existing->getAttribute('deleted_at') !== null || $existing->getAttribute('retracted_at') !== null)) {
            throw new ConflictHttpException('The external identifier is no longer available for this patient.');
        }
    }

    public function upsert(
        PhrPatient $patient,
        AgentApiClientIdentity $client,
        ClinicalUpsertData $data,
    ): ClinicalUpsertResult {
        $definition = AgentClinicalResourceCatalog::definition($data->resource);
        $modelClass = $definition['model'] ?? null;
        abort_unless(is_string($modelClass) && isset($definition['write_rules']), 404);

        if ($data->sourceDocumentId !== null) {
            PhrDocument::query()
                ->where('patient_id', $patient->id)
                ->findOrFail($data->sourceDocumentId);
        }

        return DB::transaction(function () use ($patient, $client, $data, $modelClass): ClinicalUpsertResult {
            $identity = [
                'patient_id' => $patient->id,
                'import_source' => $client->importSource(),
                'external_id' => $data->externalId,
            ];
            $createAttributes = [
                'user_id' => $patient->owner_user_id,
                'source_document_id' => $data->sourceDocumentId,
                // Every agent-created record enters the human review queue.
                'review_status' => PhrReviewStatus::PENDING,
                ...$data->attributes,
            ];

            // Identity survives both a deletion and a retraction: the row stays and
            // the unique (patient_id, import_source, external_id) index still holds
            // the slot. createOrFirst applies the soft-delete scope, so it would not
            // see a withdrawn row and would fail on the constraint instead of
            // answering; reviving one would resurrect something a person deleted or
            // the source withdrew. Both are a conflict, which is the same answer
            // documents.upload already gives for a trashed identity.
            // Locking the identity row serializes this against a concurrent
            // delete or retraction. createOrFirst applies the soft-delete scope,
            // so it would not see a withdrawn row and would fail on the unique
            // index instead of answering; reviving one would resurrect something
            // a person deleted or the source withdrew. Both are a conflict,
            // which is the same answer documents.upload gives for a trashed
            // identity.
            $this->refuseWithdrawnIdentity($modelClass, $identity);

            try {
                /** @var Model $record */
                $record = $modelClass::query()->createOrFirst($identity, $createAttributes);
            } catch (UniqueConstraintViolationException) {
                // Two first writes raced. The row now exists; whether it is
                // usable is the same question as before, asked under a lock.
                $this->refuseWithdrawnIdentity($modelClass, $identity);

                /** @var Model $record */
                $record = $modelClass::query()->where($identity)->firstOrFail();
            }
            if ($record->wasRecentlyCreated) {
                // Hydrate database defaults and driver-normalized scalar types before
                // deriving the version clients will later compare after a fresh read.
                $record->refresh();

                return new ClinicalUpsertResult(
                    $record,
                    ClinicalUpsertResult::CREATED,
                    $this->versions->for($record),
                );
            }

            // Re-read under a row lock before comparing the opaque version. The
            // strong HMAC covers every stored attribute, so same-second updates
            // remain detectable even on databases with second-precision timestamps.
            $record = $modelClass::query()
                ->where($identity)
                ->lockForUpdate()
                ->firstOrFail();
            $currentVersion = $this->versions->for($record);
            // review_status is excluded from the diff on purpose: it must not
            // count toward dirtiness, or a resubmission of unchanged clinical
            // data would look like a real edit and reopen review by itself.
            $updates = [
                'source_document_id' => $data->sourceDocumentId,
                ...$data->attributes,
            ];
            $record->fill($updates);

            // A retried request whose desired state is already present is safe
            // even if it carries the version from before its first successful call.
            if (! $record->isDirty()) {
                return new ClinicalUpsertResult(
                    $record,
                    ClinicalUpsertResult::UNCHANGED,
                    $currentVersion,
                );
            }
            if ($data->expectedVersion === null || ! hash_equals($currentVersion, $data->expectedVersion)) {
                throw new ConflictHttpException('The clinical record changed; fetch it and retry with its current version.');
            }

            // The record really is changing, so human review reopens -- including
            // for a record a reviewer had already rejected. An idempotent retry
            // returned above as UNCHANGED and leaves the reviewer's decision alone.
            $record->setAttribute('review_status', PhrReviewStatus::PENDING);
            $record->save();
            $record->refresh();

            return new ClinicalUpsertResult(
                $record,
                ClinicalUpsertResult::UPDATED,
                $this->versions->for($record),
            );
        }, 3);
    }
}

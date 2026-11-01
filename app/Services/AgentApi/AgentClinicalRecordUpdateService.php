<?php

namespace App\Services\AgentApi;

use App\DataTransferObjects\AgentApi\ClinicalRecordUpdateData;
use App\DataTransferObjects\AgentApi\ClinicalUpsertResult;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Safe ID-targeted patching for records whose provenance predates the OAuth client. */
final readonly class AgentClinicalRecordUpdateService
{
    public function __construct(private AgentClinicalRecordVersion $versions) {}

    public function update(
        PhrPatient $patient,
        int $recordId,
        ClinicalRecordUpdateData $data,
    ): ClinicalUpsertResult {
        $definition = AgentClinicalResourceCatalog::definition($data->resource);
        $modelClass = $definition['model'] ?? null;
        abort_unless(is_string($modelClass) && isset($definition['write_rules']), 404);

        // Resource-specific preparation (a fill resolves its drug from openFDA) may call the
        // network, so it runs before the transaction and its row lock, and a transaction retry
        // never repeats it. A stale version is refused first, before any network call.
        $extensionClass = $definition['write_extension'] ?? null;
        $extension = is_string($extensionClass) ? app($extensionClass) : null;
        $prepared = null;
        if ($extension !== null) {
            /** @var Model $current */
            $current = $modelClass::query()
                ->where('patient_id', $patient->id)
                ->whereNull('retracted_at')
                ->findOrFail($recordId);
            if (! hash_equals($this->versions->for($current), $data->expectedVersion)) {
                throw new ConflictHttpException('The clinical record changed; fetch it and retry with its current version.');
            }
            $prepared = $extension->prepareUpdate($patient, $current, $data->attributes);
        }

        return DB::transaction(function () use ($patient, $recordId, $data, $modelClass, $extension, $prepared): ClinicalUpsertResult {
            /** @var Model $record */
            // A withdrawn record is not editable. Deletion is already excluded by
            // the soft-delete scope; retraction has to be stated, or an agent
            // could keep patching a record its own source has taken back.
            $record = $modelClass::query()
                ->where('patient_id', $patient->id)
                ->whereNull('retracted_at')
                ->lockForUpdate()
                ->findOrFail($recordId);
            $currentVersion = $this->versions->for($record);

            // The precondition is checked before anything is applied. This
            // endpoint reaches legacy and browser-created rows, so a stale or
            // fabricated version must never produce a successful response that
            // discloses the record or its current version.
            if (! hash_equals($currentVersion, $data->expectedVersion)) {
                throw new ConflictHttpException('The clinical record changed; fetch it and retry with its current version.');
            }

            if ($data->sourceDocumentSpecified && $data->sourceDocumentId !== null) {
                PhrDocument::query()
                    ->where('patient_id', $patient->id)
                    ->findOrFail($data->sourceDocumentId);
            }

            $updates = $prepared ?? $data->attributes;
            if ($data->sourceDocumentSpecified) {
                $updates['source_document_id'] = $data->sourceDocumentId;
            }
            $record->fill($updates);

            // An exact no-op preserves the existing review state.
            if (! $record->isDirty()) {
                return new ClinicalUpsertResult($record, ClinicalUpsertResult::UNCHANGED, $currentVersion);
            }

            // Any effective agent change reopens human review. Confirmation is a
            // browser action; this endpoint cannot assert it.
            $record->setAttribute('review_status', PhrReviewStatus::PENDING);
            $record->save();
            $extension?->afterUpdate($patient, $record);
            $record->refresh();

            return new ClinicalUpsertResult(
                $record,
                ClinicalUpsertResult::UPDATED,
                $this->versions->for($record),
            );
        }, 3);
    }
}

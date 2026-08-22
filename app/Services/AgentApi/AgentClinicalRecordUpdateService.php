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

        return DB::transaction(function () use ($patient, $recordId, $data, $modelClass): ClinicalUpsertResult {
            /** @var Model $record */
            $record = $modelClass::query()
                ->where('patient_id', $patient->id)
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

            $updates = $data->attributes;
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
            $record->refresh();

            return new ClinicalUpsertResult(
                $record,
                ClinicalUpsertResult::UPDATED,
                $this->versions->for($record),
            );
        }, 3);
    }
}

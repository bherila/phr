<?php

namespace App\Services\AgentApi;

use App\DataTransferObjects\AgentApi\ClinicalRecordUpdateData;
use App\DataTransferObjects\AgentApi\ClinicalUpsertResult;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
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

            if ($data->sourceDocumentSpecified && $data->sourceDocumentId !== null) {
                PhrDocument::query()
                    ->where('patient_id', $patient->id)
                    ->findOrFail($data->sourceDocumentId);
            }

            $updates = $data->attributes;
            if ($data->sourceDocumentSpecified) {
                $updates['source_document_id'] = $data->sourceDocumentId;
            }
            if ($data->reviewStatus !== null) {
                $updates['review_status'] = $data->reviewStatus;
            }
            $record->fill($updates);

            // Safe retries remain no-ops, even after another edit changed the
            // opaque version, because the requested state is already present.
            if (! $record->isDirty()) {
                return new ClinicalUpsertResult($record, ClinicalUpsertResult::UNCHANGED, $currentVersion);
            }
            if (! hash_equals($currentVersion, $data->expectedVersion)) {
                throw new ConflictHttpException('The clinical record changed; fetch it and retry with its current version.');
            }

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

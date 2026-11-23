<?php

namespace App\Services\AgentApi;

use App\DataTransferObjects\AgentApi\AgentDeletionResult;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * An agent's delete of a clinical record or document: the browser's soft delete
 * (`deleted_at`), reached by record ID and current version.
 *
 * It is not retraction. Retraction is a source withdrawing its own claim and stays
 * within the caller's import namespace; a delete removes a record whoever wrote it,
 * a person in the browser included, exactly as that person could. The record leaves
 * every read and changes.list reports it as a deleted tombstone.
 *
 * The version is checked before anything changes, so a record edited since the
 * caller read it is never removed on the strength of an old read. A record already
 * deleted answers `unchanged` whatever version arrives: the delete it asked for has
 * happened, a retry after a lost response must settle rather than fail, and the
 * answer is only the tombstone changes.list already shows.
 */
final readonly class AgentRecordDeletionService
{
    public function __construct(private AgentClinicalRecordVersion $versions) {}

    public function deleteClinical(PhrPatient $patient, string $resource, int $recordId, string $expectedVersion): AgentDeletionResult
    {
        $definition = AgentClinicalResourceCatalog::definition($resource);
        $modelClass = $definition['model'] ?? null;
        abort_unless(is_string($modelClass) && isset($definition['write_rules']), 404);
        $extensionClass = $definition['write_extension'] ?? null;

        return $this->softDelete(
            $patient,
            $modelClass,
            $resource,
            $recordId,
            $expectedVersion,
            // What the browser's delete does beyond the model's own events (a fill
            // releases the medication only it kept alive).
            is_string($extensionClass)
                ? static fn (Model $record) => app($extensionClass)->afterDelete($patient, $record)
                : null,
        );
    }

    public function deleteDocument(PhrPatient $patient, int $documentId, string $expectedVersion): AgentDeletionResult
    {
        return $this->softDelete($patient, PhrDocument::class, 'documents', $documentId, $expectedVersion);
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  (Closure(Model): void)|null  $afterDelete
     */
    private function softDelete(
        PhrPatient $patient,
        string $modelClass,
        string $resourceType,
        int $recordId,
        string $expectedVersion,
        ?Closure $afterDelete = null,
    ): AgentDeletionResult {
        return DB::transaction(function () use ($patient, $modelClass, $resourceType, $recordId, $expectedVersion, $afterDelete): AgentDeletionResult {
            /** @var Model|null $record */
            $record = $modelClass::query()
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->where('patient_id', $patient->id)
                ->lockForUpdate()
                ->find($recordId);
            abort_if($record === null, 404);

            if ($record->getAttribute('deleted_at') !== null) {
                return $this->result(AgentDeletionResult::UNCHANGED, $resourceType, $patient, $record);
            }
            if (! hash_equals($this->versions->for($record), $expectedVersion)) {
                throw new ConflictHttpException('The record changed; fetch it and retry with its current version.');
            }

            // The model's own delete: its events (a fill or EOB reschedules claim
            // linking, a document advances its EOBs) run exactly as for the browser.
            $record->delete();
            if ($afterDelete !== null) {
                $afterDelete($record);
            }
            $record->refresh();

            return $this->result(AgentDeletionResult::DELETED, $resourceType, $patient, $record);
        }, 3);
    }

    /** changes.list dates a change by updated_at, which a soft delete sets with deleted_at. */
    private function result(string $outcome, string $resourceType, PhrPatient $patient, Model $record): AgentDeletionResult
    {
        $changedAt = $record->getRawOriginal('updated_at') ?? $record->getRawOriginal('deleted_at');

        return new AgentDeletionResult(
            $outcome,
            $resourceType,
            (int) $record->getKey(),
            (int) $patient->id,
            Carbon::parse((string) $changedAt),
        );
    }
}

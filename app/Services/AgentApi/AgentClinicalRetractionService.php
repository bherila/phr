<?php

namespace App\Services\AgentApi;

use App\Models\PhrPatient;
use App\Support\AgentApi\AgentApiClientIdentity;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use App\Support\PHR\PhrRecordLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Lets an integration withdraw a claim it made, and nothing else.
 *
 * Retraction is deliberately not a delete. The row survives, its identity stays
 * reserved so an ordinary upsert cannot quietly re-assert it, and the record
 * simply stops counting as current clinical data. What it means is "the source
 * withdraws a claim it made in error" -- never "the source no longer lists it".
 * Those are different, and an institution ageing data out past its own
 * retention policy produces the second without the first.
 *
 * Scope is the caller's own client namespace. A record another integration
 * wrote, or a person created in the browser, reports as missing rather than as
 * forbidden: saying "you may not retract this" would confirm that some other
 * integration holds that identifier.
 */
final readonly class AgentClinicalRetractionService
{
    public function __construct(private AgentClinicalRecordVersion $versions) {}

    public function retract(
        PhrPatient $patient,
        AgentApiClientIdentity $client,
        string $resource,
        int $recordId,
        string $expectedVersion,
    ): ClinicalRetractionResult {
        $definition = AgentClinicalResourceCatalog::definition($resource);
        $modelClass = $definition['model'] ?? null;
        abort_unless(is_string($modelClass) && isset($definition['write_rules']), 404);

        return DB::transaction(function () use ($patient, $client, $modelClass, $recordId, $expectedVersion): ClinicalRetractionResult {
            /** @var Model|null $record */
            $record = $modelClass::query()
                // A deleted record is gone as far as this operation is
                // concerned; retracting one would be a second, contradictory
                // account of why it is not there.
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->where('patient_id', $patient->id)
                ->where('import_source', $client->importSource())
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->find($recordId);

            abort_if($record === null, 404);

            $currentVersion = $this->versions->for($record);
            if (! hash_equals($currentVersion, $expectedVersion)) {
                throw new ConflictHttpException('The clinical record changed; fetch it and retry with its current version.');
            }

            // Retracting twice is the same request arriving twice. Settling it
            // as unchanged keeps a retried call from looking like a failure.
            if ($record->getAttribute('retracted_at') !== null) {
                return new ClinicalRetractionResult($record, ClinicalRetractionResult::UNCHANGED, $currentVersion);
            }

            $record->forceFill(['retracted_at' => now()])->save();
            $record->refresh();

            return new ClinicalRetractionResult(
                $record,
                ClinicalRetractionResult::RETRACTED,
                $this->versions->for($record),
            );
        });
    }

    public function lifecycleOf(Model $record): string
    {
        return PhrRecordLifecycle::of($record);
    }
}

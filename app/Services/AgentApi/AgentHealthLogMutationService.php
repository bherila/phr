<?php

namespace App\Services\AgentApi;

use App\DataTransferObjects\AgentApi\AgentAppendResult;
use App\DataTransferObjects\AgentApi\AgentDeletionResult;
use App\DataTransferObjects\AgentApi\ClinicalUpsertResult;
use App\DataTransferObjects\AgentApi\HealthLogCreateData;
use App\DataTransferObjects\AgentApi\HealthLogEntryAppendData;
use App\Models\PhrHealthLog;
use App\Models\PhrHealthLogEntry;
use App\Models\PhrPatient;
use App\Services\PHR\HealthLog\PhrHealthLogDao;
use App\Services\PHR\HealthLog\PhrHealthLogService;
use App\Support\AgentApi\AgentApiClientIdentity;
use App\Support\AgentApi\AgentApiRequestFingerprint;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class AgentHealthLogMutationService
{
    private const string CREATE_LOG = 'health_logs.create';

    private const string APPEND_ENTRY = 'health_log_entries.append';

    public function __construct(
        private AgentApiMutationIdentityDao $identities,
        private PhrHealthLogDao $healthLogs,
        private PhrHealthLogService $mutations,
        private AgentClinicalRecordVersion $versions,
        private AgentRecordDeletionService $deletions,
    ) {}

    public function createLog(
        PhrPatient $patient,
        int $actorId,
        AgentApiClientIdentity $client,
        HealthLogCreateData $data,
    ): AgentAppendResult {
        return $this->appendOnce(
            $patient,
            $client,
            self::CREATE_LOG,
            $data->externalId,
            $data->attributes,
            (new PhrHealthLog)->getTable(),
            fn (): PhrHealthLog => $this->mutations->createLog($patient, $actorId, $data->attributes),
            fn (int $id): PhrHealthLog => $this->healthLogs->log($patient->id, $id, true),
        );
    }

    public function appendEntry(
        PhrPatient $patient,
        PhrHealthLog $healthLog,
        int $actorId,
        AgentApiClientIdentity $client,
        HealthLogEntryAppendData $data,
    ): AgentAppendResult {
        return $this->appendOnce(
            $patient,
            $client,
            self::APPEND_ENTRY,
            $data->externalId,
            ['health_log_id' => $healthLog->id, ...$data->attributes],
            (new PhrHealthLogEntry)->getTable(),
            fn (): PhrHealthLogEntry => $this->mutations->createEntry(
                $patient,
                $healthLog,
                $actorId,
                $data->attributes,
            ),
            fn (int $id): PhrHealthLogEntry => $this->healthLogs->entry($patient->id, $healthLog->id, $id),
        );
    }

    /**
     * health_logs.update: the browser's log edit (a unique name, a kind), applied
     * under the log's row lock only when the caller holds its current version. A log
     * has no review status, so an effective change is simply saved.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateLog(PhrPatient $patient, int $healthLogId, string $expectedVersion, array $changes): ClinicalUpsertResult
    {
        return DB::transaction(function () use ($patient, $healthLogId, $expectedVersion, $changes): ClinicalUpsertResult {
            $log = $this->healthLogs->logsQuery($patient->id)->lockForUpdate()->findOrFail($healthLogId);
            $current = $this->assertVersion($log, $expectedVersion);
            $log->fill($changes);
            if ($log->isDirty()) {
                $this->mutations->updateLog($log, $changes);
            }
            $read = $this->healthLogs->log($patient->id, $healthLogId, true);

            return $log->wasChanged()
                ? new ClinicalUpsertResult($read, ClinicalUpsertResult::UPDATED, $this->versions->for($read))
                : new ClinicalUpsertResult($read, ClinicalUpsertResult::UNCHANGED, $current);
        }, 3);
    }

    /** @param array<string, mixed> $changes */
    public function updateEntry(PhrPatient $patient, PhrHealthLog $healthLog, int $entryId, string $expectedVersion, array $changes): ClinicalUpsertResult
    {
        return DB::transaction(function () use ($patient, $healthLog, $entryId, $expectedVersion, $changes): ClinicalUpsertResult {
            $entry = $this->healthLogs->entriesQuery($patient->id, $healthLog->id)->lockForUpdate()->findOrFail($entryId);
            $current = $this->assertVersion($entry, $expectedVersion);
            $entry->fill($changes);
            if (! $entry->isDirty()) {
                // An exact no-op answers with the entry as stored, not as filled.
                return new ClinicalUpsertResult($entry->refresh(), ClinicalUpsertResult::UNCHANGED, $current);
            }
            $updated = $this->mutations->updateEntry($entry, $changes);

            return new ClinicalUpsertResult($updated, ClinicalUpsertResult::UPDATED, $this->versions->for($updated));
        }, 3);
    }

    /**
     * The browser's delete (#444): the log is soft-deleted, and every entry still in it
     * with it. A repeat answers unchanged with the same tombstone.
     */
    public function deleteLog(PhrPatient $patient, int $healthLogId, string $expectedVersion): AgentDeletionResult
    {
        return $this->deletions->deleteHealthLog($patient, $healthLogId, $expectedVersion);
    }

    /** As the browser, the entry is soft-deleted; a repeat answers unchanged. */
    public function deleteEntry(PhrPatient $patient, PhrHealthLog $healthLog, int $entryId, string $expectedVersion): AgentDeletionResult
    {
        return $this->deletions->deleteHealthLogEntry($patient, (int) $healthLog->id, $entryId, $expectedVersion);
    }

    /** A stale or fabricated version is refused before anything changes, and discloses nothing. */
    private function assertVersion(Model $record, string $expectedVersion): string
    {
        $current = $this->versions->for($record);
        if (! hash_equals($current, $expectedVersion)) {
            throw new ConflictHttpException('The record changed; fetch it and retry with its current version.');
        }

        return $current;
    }

    /**
     * Serialize a patient's identity ledger so concurrent retries cannot create
     * two rows before the unique key becomes visible on every supported driver.
     *
     * @param  array<string, mixed>  $fingerprintPayload
     * @param  callable(): Model  $create
     * @param  callable(int): Model  $find
     */
    private function appendOnce(
        PhrPatient $patient,
        AgentApiClientIdentity $client,
        string $operation,
        string $externalId,
        array $fingerprintPayload,
        string $targetTable,
        callable $create,
        callable $find,
    ): AgentAppendResult {
        return DB::transaction(function () use (
            $patient, $client, $operation, $externalId, $fingerprintPayload,
            $targetTable, $create, $find,
        ): AgentAppendResult {
            PhrPatient::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $requestHashes = AgentApiRequestFingerprint::candidates($fingerprintPayload);
            $requestHash = $requestHashes[0];
            $identity = $this->identities->find($patient->id, $client, $operation, $externalId);

            if ($identity !== null) {
                if (! $this->matchesAny($identity->request_hash, $requestHashes)
                    || $identity->target_table !== $targetTable) {
                    throw new ConflictHttpException('The stable external identifier is already bound to different data.');
                }
                try {
                    $record = $find($identity->target_id);
                } catch (ModelNotFoundException) {
                    throw new ConflictHttpException('The stable external identifier refers to a record that no longer exists.');
                }

                return new AgentAppendResult($record, AgentAppendResult::UNCHANGED);
            }

            $record = $create();
            $this->identities->remember(
                $patient->id,
                $client,
                $operation,
                $externalId,
                $requestHash,
                $targetTable,
                (int) $record->getKey(),
            );

            return new AgentAppendResult($record, AgentAppendResult::CREATED);
        }, 3);
    }

    /** @param non-empty-list<string> $candidates */
    private function matchesAny(string $stored, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (hash_equals($stored, $candidate)) {
                return true;
            }
        }

        return false;
    }
}

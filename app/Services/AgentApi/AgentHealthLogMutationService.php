<?php

namespace App\Services\AgentApi;

use App\DataTransferObjects\AgentApi\AgentAppendResult;
use App\DataTransferObjects\AgentApi\HealthLogCreateData;
use App\DataTransferObjects\AgentApi\HealthLogEntryAppendData;
use App\Models\PhrHealthLog;
use App\Models\PhrHealthLogEntry;
use App\Models\PhrPatient;
use App\Services\PHR\HealthLog\PhrHealthLogDao;
use App\Services\PHR\HealthLog\PhrHealthLogService;
use App\Support\AgentApi\AgentApiClientIdentity;
use App\Support\AgentApi\AgentApiRequestFingerprint;
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

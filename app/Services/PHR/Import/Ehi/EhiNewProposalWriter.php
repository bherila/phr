<?php

namespace App\Services\PHR\Import\Ehi;

use App\Models\PhrEhiProposal;
use App\Models\PhrEhiProposalRevision;
use Illuminate\Events\Dispatcher;
use LogicException;
use ReflectionReference;

/** Inserts an all-new preview chunk inside its patient write transaction. */
final class EhiNewProposalWriter
{
    private const int CHUNK = 200;

    /** @param array<array-key, array{attributes: array<string, mixed>, revisions: list<int>}> $rows */
    public function insert(int $patientId, int $packageId, int $generation, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $prototype = new PhrEhiProposal;
        $connection = $prototype->getConnection();
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('New proposals require the patient write transaction.');
        }
        if (! $this->batchable($prototype, $rows)) {
            foreach ($rows as $row) {
                $proposal = PhrEhiProposal::query()->create($this->attributes($patientId, $packageId, $generation, $row['attributes']));
                PhrEhiProposalRevision::replaceFor($proposal, $row['revisions']);
            }

            return;
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $raw = [];
            $subjects = [];
            foreach ($chunk as $row) {
                // Keep Eloquent's fillable rules, casts and timestamps. The fast
                // path is used only when there are no model save observers.
                $proposal = new PhrEhiProposal($this->attributes($patientId, $packageId, $generation, $row['attributes']));
                if ($proposal->usesTimestamps()) {
                    $proposal->updateTimestamps();
                }
                $raw[] = $proposal->getAttributes();
                $subjects[] = $proposal->subject_key;
            }
            $connection->table($prototype->getTable())->insert($raw);
            // Read actual IDs using the unique proposal tuple. Neither generated
            // IDs nor database return order need to be consecutive or ordered.
            $ids = $connection->table($prototype->getTable())
                ->where('patient_id', $patientId)->where('package_id', $packageId)
                ->where('mapping', $raw[0]['mapping'])->where('mapping_version', $raw[0]['mapping_version'])
                ->whereIn('subject_key', $subjects)->pluck('id', 'subject_key');
            if ($ids->count() !== count($chunk)) {
                throw new LogicException('New proposal IDs could not be read back.');
            }
            $dependencies = [];
            foreach ($chunk as $position => $row) {
                $proposalId = $ids->get($subjects[$position]);
                if ($proposalId === null) {
                    throw new LogicException('A new proposal ID could not be read back.');
                }
                $now = now();
                foreach ($row['revisions'] as $revisionPosition => $revisionId) {
                    $dependencies[] = [
                        'patient_id' => $patientId, 'proposal_id' => (int) $proposalId,
                        'revision_id' => $revisionId, 'position' => $revisionPosition,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                    if (count($dependencies) === self::CHUNK) {
                        PhrEhiProposalRevision::query()->insert($dependencies);
                        $dependencies = [];
                    }
                }
            }
            if ($dependencies !== []) {
                PhrEhiProposalRevision::query()->insert($dependencies);
            }
        }
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function attributes(int $patientId, int $packageId, int $generation, array $attributes): array
    {
        return [...$attributes, 'patient_id' => $patientId, 'package_id' => $packageId,
            'status' => PhrEhiProposal::STATUS_PENDING, 'preview_generation' => $generation];
    }

    /** @param array<array-key, array{attributes: array<string, mixed>, revisions: list<int>}> $rows */
    private function batchable(PhrEhiProposal $prototype, array $rows): bool
    {
        $dispatcher = PhrEhiProposal::getEventDispatcher();
        if ($dispatcher !== null) {
            if ($dispatcher::class !== Dispatcher::class) {
                return false;
            }
            foreach (['saving', 'creating', 'created', 'saved'] as $event) {
                if ($dispatcher->hasListeners('eloquent.'.$event.': '.PhrEhiProposal::class)) {
                    return false;
                }
            }
        }
        if ($prototype->getTouchedRelations() !== [] || $prototype->usesUniqueIds() || $prototype->dispatchesEvents() !== []) {
            return false;
        }
        $first = reset($rows)['attributes'];
        $columns = array_keys($first);
        sort($columns);
        foreach ($rows as $row) {
            if (! $this->stable($row)) {
                return false;
            }
            $keys = array_keys($row['attributes']);
            sort($keys);
            if ($keys !== $columns || ! is_string($row['attributes']['subject_key'] ?? null)
                || preg_match('/\A[0-9a-f]{64}\z/', $row['attributes']['subject_key']) !== 1
                || ($row['attributes']['mapping'] ?? null) !== ($first['mapping'] ?? null)
                || ($row['attributes']['mapping_version'] ?? null) !== ($first['mapping_version'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<array-key, mixed> $row */
    private function stable(array $row, int $depth = 1): bool
    {
        if ($depth >= 511) {
            return false;
        }
        foreach ($row as $key => $value) {
            if (ReflectionReference::fromArrayElement($row, $key) !== null) {
                return false;
            }
            if (is_array($value)) {
                if (! $this->stable($value, $depth + 1)) {
                    return false;
                }
            } elseif ($value !== null && ! is_scalar($value)) {
                return false;
            }
        }

        return true;
    }
}

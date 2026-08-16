<?php

namespace App\Services\PHR\HealthLog;

use App\Models\PhrHealthLog;
use App\Models\PhrHealthLogEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** Typed patient-scoped data access for health logs and entries. */
final class PhrHealthLogDao
{
    /** @return Builder<PhrHealthLog> */
    public function logsQuery(int $patientId): Builder
    {
        return PhrHealthLog::query()->where('patient_id', $patientId);
    }

    /** @return Collection<int, PhrHealthLog> */
    public function logs(int $patientId): Collection
    {
        return $this->withEntrySummary($this->logsQuery($patientId))
            ->orderBy('archived_at')
            ->orderBy('name')
            ->get();
    }

    public function log(int $patientId, int $healthLogId, bool $withSummary = false): PhrHealthLog
    {
        $query = $this->logsQuery($patientId);
        if ($withSummary) {
            $query = $this->withEntrySummary($query);
        }

        return $query->findOrFail($healthLogId);
    }

    /** @return Builder<PhrHealthLogEntry> */
    public function entriesQuery(int $patientId, int $healthLogId): Builder
    {
        return PhrHealthLogEntry::query()
            ->where('patient_id', $patientId)
            ->where('health_log_id', $healthLogId);
    }

    /** @return Collection<int, PhrHealthLogEntry> */
    public function entries(int $patientId, int $healthLogId): Collection
    {
        return $this->entriesQuery($patientId, $healthLogId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();
    }

    public function entry(int $patientId, int $healthLogId, int $entryId): PhrHealthLogEntry
    {
        return $this->entriesQuery($patientId, $healthLogId)->findOrFail($entryId);
    }

    /** @param array<string, mixed> $attributes */
    public function createLog(array $attributes): PhrHealthLog
    {
        return PhrHealthLog::query()->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function createEntry(array $attributes): PhrHealthLogEntry
    {
        return PhrHealthLogEntry::query()->create($attributes);
    }

    public function nameExists(int $patientId, string $name, ?int $ignoreLogId = null): bool
    {
        return $this->logsQuery($patientId)
            ->where('name', $name)
            ->when($ignoreLogId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreLogId))
            ->exists();
    }

    /** @param array<string, mixed> $attributes */
    public function firstOrCreateLog(int $patientId, string $name, array $attributes): PhrHealthLog
    {
        return PhrHealthLog::query()->firstOrCreate(
            ['patient_id' => $patientId, 'name' => $name],
            $attributes,
        );
    }

    public function refreshWithEntrySummary(PhrHealthLog $healthLog): PhrHealthLog
    {
        return $this->withEntrySummary(PhrHealthLog::query())->findOrFail($healthLog->id);
    }

    /**
     * @param  Builder<PhrHealthLog>  $query
     * @return Builder<PhrHealthLog>
     */
    private function withEntrySummary(Builder $query): Builder
    {
        return $query
            ->withCount('entries')
            ->withMax('entries as latest_entry_at', 'occurred_at');
    }
}

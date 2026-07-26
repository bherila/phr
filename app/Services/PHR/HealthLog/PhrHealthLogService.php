<?php

namespace App\Services\PHR\HealthLog;

use App\Models\PhrHealthLog;
use App\Models\PhrHealthLogEntry;
use App\Models\PhrPatient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class PhrHealthLogService
{
    /** @param array<string, mixed> $attributes */
    public function createLog(PhrPatient $patient, int $actorId, array $attributes): PhrHealthLog
    {
        $this->ensureNameIsUnique($patient->id, (string) $attributes['name']);

        $healthLog = PhrHealthLog::query()->create([
            ...$attributes,
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'created_by_user_id' => $actorId,
        ]);

        return $this->withEntrySummary($healthLog);
    }

    public function findOrCreateLog(
        PhrPatient $patient,
        int $actorId,
        string $name,
        string $kind = PhrHealthLog::KIND_CUSTOM,
        ?string $description = null,
    ): PhrHealthLog {
        $healthLog = PhrHealthLog::query()->firstOrCreate(
            [
                'patient_id' => $patient->id,
                'name' => $name,
            ],
            [
                'user_id' => $patient->owner_user_id,
                'created_by_user_id' => $actorId,
                'kind' => $kind,
                'description' => $description,
            ],
        );

        return $this->withEntrySummary($healthLog);
    }

    /** @param array<string, mixed> $attributes */
    public function updateLog(PhrHealthLog $healthLog, array $attributes): PhrHealthLog
    {
        if (isset($attributes['name'])) {
            $this->ensureNameIsUnique($healthLog->patient_id, (string) $attributes['name'], $healthLog->id);
        }

        $healthLog->update($attributes);

        return $this->withEntrySummary($healthLog);
    }

    /** @param array<string, mixed> $attributes */
    public function createEntry(
        PhrPatient $patient,
        PhrHealthLog $healthLog,
        int $actorId,
        array $attributes,
    ): PhrHealthLogEntry {
        $this->ensureLogBelongsToPatient($healthLog, $patient);

        return PhrHealthLogEntry::query()->create([
            ...$attributes,
            'health_log_id' => $healthLog->id,
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'recorded_by_user_id' => $actorId,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function updateEntry(PhrHealthLogEntry $entry, array $attributes): PhrHealthLogEntry
    {
        $entry->update($attributes);

        return $entry->refresh();
    }

    private function ensureLogBelongsToPatient(PhrHealthLog $healthLog, PhrPatient $patient): void
    {
        if ($healthLog->patient_id !== $patient->id) {
            throw (new ModelNotFoundException)->setModel(PhrHealthLog::class, [$healthLog->id]);
        }
    }

    private function ensureNameIsUnique(int $patientId, string $name, ?int $ignoreLogId = null): void
    {
        $query = PhrHealthLog::query()
            ->where('patient_id', $patientId)
            ->where('name', $name);

        if ($ignoreLogId !== null) {
            $query->whereKeyNot($ignoreLogId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A health log with this name already exists for the patient.',
            ]);
        }
    }

    private function withEntrySummary(PhrHealthLog $healthLog): PhrHealthLog
    {
        return PhrHealthLog::query()
            ->withCount('entries')
            ->withMax('entries as latest_entry_at', 'occurred_at')
            ->findOrFail($healthLog->id);
    }
}

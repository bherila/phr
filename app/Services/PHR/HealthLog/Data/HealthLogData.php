<?php

namespace App\Services\PHR\HealthLog\Data;

use App\Models\PhrHealthLog;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
readonly class HealthLogData
{
    public function __construct(
        public int $id,
        public int $patient_id,
        public int $user_id,
        public ?int $created_by_user_id,
        public string $name,
        public string $kind,
        public ?string $description,
        public ?string $archived_at,
        public int $entries_count,
        public ?string $latest_entry_at,
        public ?string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(PhrHealthLog $healthLog): self
    {
        return new self(
            id: $healthLog->id,
            patient_id: $healthLog->patient_id,
            user_id: $healthLog->user_id,
            created_by_user_id: $healthLog->created_by_user_id,
            name: $healthLog->name,
            kind: $healthLog->kind,
            description: $healthLog->description,
            archived_at: $healthLog->archived_at?->toDateTimeString(),
            entries_count: (int) ($healthLog->entries_count ?? 0),
            latest_entry_at: $healthLog->latest_entry_at?->toDateTimeString(),
            created_at: $healthLog->created_at?->toDateTimeString(),
            updated_at: $healthLog->updated_at?->toDateTimeString(),
        );
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'user_id' => $this->user_id,
            'created_by_user_id' => $this->created_by_user_id,
            'name' => $this->name,
            'kind' => $this->kind,
            'description' => $this->description,
            'archived_at' => $this->archived_at,
            'entries_count' => $this->entries_count,
            'latest_entry_at' => $this->latest_entry_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

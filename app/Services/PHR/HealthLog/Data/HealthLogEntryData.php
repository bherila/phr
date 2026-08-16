<?php

namespace App\Services\PHR\HealthLog\Data;

use App\Models\PhrHealthLogEntry;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
readonly class HealthLogEntryData
{
    /**
     * @param  list<string>  $tags
     * @param  array<string, mixed>|object|null  $details
     */
    public function __construct(
        public int $id,
        public int $health_log_id,
        public int $patient_id,
        public int $user_id,
        public ?int $recorded_by_user_id,
        public string $occurred_at,
        public ?string $title,
        public ?string $notes,
        public ?int $intensity,
        #[LiteralTypeScriptType('Array<string>')]
        public array $tags,
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public array|object|null $details,
        public ?string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(PhrHealthLogEntry $entry): self
    {
        return new self(
            id: $entry->id,
            health_log_id: $entry->health_log_id,
            patient_id: $entry->patient_id,
            user_id: $entry->user_id,
            recorded_by_user_id: $entry->recorded_by_user_id,
            occurred_at: $entry->occurred_at->toDateTimeString(),
            title: $entry->title,
            notes: $entry->notes,
            intensity: $entry->intensity,
            tags: $entry->tags ?? [],
            // JSON objects decode through Eloquent's array cast. Preserve the
            // empty-object wire shape instead of serializing it back as [].
            details: $entry->details === [] ? (object) [] : $entry->details,
            created_at: $entry->created_at?->toDateTimeString(),
            updated_at: $entry->updated_at?->toDateTimeString(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'health_log_id' => $this->health_log_id,
            'patient_id' => $this->patient_id,
            'user_id' => $this->user_id,
            'recorded_by_user_id' => $this->recorded_by_user_id,
            'occurred_at' => $this->occurred_at,
            'title' => $this->title,
            'notes' => $this->notes,
            'intensity' => $this->intensity,
            'tags' => $this->tags,
            'details' => $this->details,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

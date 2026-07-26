<?php

namespace Database\Factories;

use App\Models\PhrHealthLog;
use App\Models\PhrHealthLogEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhrHealthLogEntry>
 */
class PhrHealthLogEntryFactory extends Factory
{
    protected $model = PhrHealthLogEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'health_log_id' => PhrHealthLog::factory(),
            'patient_id' => fn (array $attributes): int => PhrHealthLog::query()
                ->findOrFail($attributes['health_log_id'])
                ->patient_id,
            'user_id' => fn (array $attributes): int => PhrHealthLog::query()
                ->findOrFail($attributes['health_log_id'])
                ->user_id,
            'recorded_by_user_id' => fn (array $attributes): int => (int) $attributes['user_id'],
            'occurred_at' => fake()->dateTimeBetween('-30 days'),
            'title' => fake()->optional()->sentence(4),
            'notes' => fake()->optional()->sentence(),
            'intensity' => fake()->optional()->numberBetween(0, 10),
            'tags' => ['synthetic'],
            'details' => ['source' => 'factory'],
        ];
    }
}

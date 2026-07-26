<?php

namespace Database\Factories;

use App\Models\PhrPatient;
use App\Models\PhrRespiratoryEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhrRespiratoryEvent>
 */
class PhrRespiratoryEventFactory extends Factory
{
    protected $model = PhrRespiratoryEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phr_patient_id' => function (): int {
                $owner = User::factory()->create();

                return PhrPatient::query()->create([
                    'owner_user_id' => $owner->id,
                    'display_name' => fake()->name(),
                ])->id;
            },
            'client_event_uuid' => fake()->uuid(),
            'event_type' => fake()->randomElement(PhrRespiratoryEvent::EVENT_TYPES),
            'occurred_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'tz_offset_min' => fake()->randomElement([-480, -420, -300, 0, 60]),
            'duration_ms' => fake()->numberBetween(80, 4000),
            'confidence' => fake()->randomFloat(4, 0.5, 1),
            'burst_count' => fake()->numberBetween(1, 5),
            'source' => fake()->randomElement(PhrRespiratoryEvent::SOURCES),
            'device_id' => fake()->uuid(),
            'model_version' => 'v'.fake()->numberBetween(1, 3).'.'.fake()->numberBetween(0, 9),
        ];
    }
}

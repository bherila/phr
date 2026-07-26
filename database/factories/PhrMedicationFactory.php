<?php

namespace Database\Factories;

use App\Models\PhrMedication;
use App\Models\PhrPatient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhrMedication>
 */
class PhrMedicationFactory extends Factory
{
    protected $model = PhrMedication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => function (): int {
                $owner = User::factory()->create();

                return PhrPatient::query()->create([
                    'owner_user_id' => $owner->id,
                    'display_name' => fake()->name(),
                ])->id;
            },
            'user_id' => fn (array $attributes): int => PhrPatient::query()
                ->findOrFail($attributes['patient_id'])
                ->owner_user_id,
            'name' => fake()->randomElement(['Metformin', 'Aspirin', 'Lisinopril']),
            'rxnorm_code' => (string) fake()->numberBetween(100000, 999999),
            'dose' => (string) fake()->numberBetween(1, 1000),
            'dose_unit' => fake()->randomElement(['mg', 'mcg', 'mL']),
            'route' => fake()->randomElement(['PO', 'IV', 'Topical']),
            'frequency' => fake()->randomElement(['daily', 'BID', 'PRN']),
            'started_on' => fake()->date(),
            'ended_on' => null,
            'status' => 'active',
            'prescriber_name' => fake()->name(),
            'reason_for_use' => fake()->sentence(),
            'raw_text' => fake()->sentence(8),
        ];
    }
}

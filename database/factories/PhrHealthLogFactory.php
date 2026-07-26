<?php

namespace Database\Factories;

use App\Models\PhrHealthLog;
use App\Models\PhrPatient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhrHealthLog>
 */
class PhrHealthLogFactory extends Factory
{
    protected $model = PhrHealthLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => function (): int {
                $owner = User::factory()->create();

                return PhrPatient::query()->create([
                    'owner_user_id' => $owner->id,
                    'display_name' => 'Synthetic Patient',
                    'relationship' => 'self',
                ])->id;
            },
            'user_id' => fn (array $attributes): int => PhrPatient::query()
                ->findOrFail($attributes['patient_id'])
                ->owner_user_id,
            'created_by_user_id' => fn (array $attributes): int => (int) $attributes['user_id'],
            'name' => ucfirst(fake()->unique()->words(3, true)),
            'kind' => fake()->randomElement(PhrHealthLog::KINDS),
            'description' => fake()->optional()->sentence(),
            'archived_at' => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\PhrPatient;
use App\Models\PhrSinusSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhrSinusSetting>
 */
class PhrSinusSettingFactory extends Factory
{
    protected $model = PhrSinusSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $updatedAt = fake()->dateTimeBetween('-30 days', 'now');

        return [
            'phr_patient_id' => function (): int {
                $owner = User::factory()->create();

                return PhrPatient::query()->create([
                    'owner_user_id' => $owner->id,
                    'display_name' => fake()->name(),
                ])->id;
            },
            'settings' => [
                'sensitivity' => fake()->randomFloat(2, 0, 1),
                'quiet_start' => fake()->numberBetween(20, 23),
                'quiet_end' => fake()->numberBetween(5, 9),
            ],
            'settings_updated_at' => $updatedAt,
            'received_at' => $updatedAt,
            'updated_by_device' => fake()->uuid(),
        ];
    }
}

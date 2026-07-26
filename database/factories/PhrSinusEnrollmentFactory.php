<?php

namespace Database\Factories;

use App\Models\PhrPatient;
use App\Models\PhrRespiratoryEvent;
use App\Models\PhrSinusEnrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhrSinusEnrollment>
 */
class PhrSinusEnrollmentFactory extends Factory
{
    protected $model = PhrSinusEnrollment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A small stand-in for YAMNet's 1024-d embedding; the column stores raw
        // little-endian f32 bytes, so build them the same way the device does.
        $dim = 8;
        $embedding = '';

        for ($i = 0; $i < $dim; $i++) {
            $embedding .= pack('g', fake()->randomFloat(4, -1, 1));
        }

        return [
            'phr_patient_id' => function (): int {
                $owner = User::factory()->create();

                return PhrPatient::query()->create([
                    'owner_user_id' => $owner->id,
                    'display_name' => fake()->name(),
                ])->id;
            },
            'client_enrollment_uuid' => random_bytes(PhrSinusEnrollment::UUID_BYTES),
            'class' => fake()->randomElement(PhrRespiratoryEvent::EVENT_TYPES),
            'is_negative' => false,
            'negative_scoped' => false,
            'embedding' => $embedding,
            'embedding_dim' => $dim,
            'model_version' => 'yamnet+proto@0',
            'similarity' => fake()->randomFloat(4, 0.5, 1),
            'separation' => fake()->randomFloat(4, 0, 0.4),
            'peak_dbfs' => fake()->randomFloat(2, -60, -3),
            'source_event_uuid' => null,
            'device_id' => fake()->uuid(),
            'captured_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}

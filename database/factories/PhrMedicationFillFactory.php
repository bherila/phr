<?php

namespace Database\Factories;

use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhrMedicationFill>
 */
class PhrMedicationFillFactory extends Factory
{
    protected $model = PhrMedicationFill::class;

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
            'ndc_status' => PhrMedicationFill::NDC_RESOLVED,
            'ndc_as_captured' => '58151-0155-77',
            'ndc_10' => '58151-155-77',
            'ndc_11' => '58151-0155-77',
            'substance_names' => ['atorvastatin calcium trihydrate'],
            'strengths' => ['10 mg/1'],
            'brand_name' => 'Lipitor',
            'dosage_form' => 'tablet, film coated',
            'route' => 'oral',
            'labeler_name' => 'Viatris Specialty LLC',
            'package_description' => '90 tablet, film coated in 1 bottle (58151-155-77)',
            'rxcui' => '617310',
            'drug_as_printed' => 'LIPITOR 10MG TAB',
            'filled_on' => fake()->date(),
            'quantity' => 90,
            'quantity_unit' => 'tablet',
            'days_supply' => 90,
            'rx_number' => (string) fake()->numerify('#######'),
            'refills_remaining' => 3,
            'directions' => 'Take 1 tablet by mouth daily',
            'prescriber_name' => fake()->name(),
            'paid_cents' => 1250,
            'retail_cents' => 31999,
            'pharmacy_name' => 'Synthetic Pharmacy',
            'pharmacy_location' => 'Springfield',
            'source_kind' => 'manual',
        ];
    }
}

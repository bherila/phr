<?php

namespace Tests\Feature\PHR;

use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\Fills\MedicationFillMedicationLinker;
use Tests\TestCase;

/** How a fill finds, or creates, the medication it belongs to. */
final class MedicationFillLinkerTest extends TestCase
{
    private PhrPatient $patient;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->owner->id, 'display_name' => 'Linker Patient']);
    }

    /** @param array<string, mixed> $attributes */
    private function fill(array $attributes): PhrMedicationFill
    {
        return PhrMedicationFill::factory()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'rxcui' => null, 'brand_name' => null,
            'dosage_form' => 'tablet', 'medication_id' => null, ...$attributes,
        ]);
    }

    public function test_combination_drugs_with_the_same_numbers_on_different_ingredients_are_different_medications(): void
    {
        $linker = app(MedicationFillMedicationLinker::class);
        $first = $this->fill(['ndc_11' => '11111111101', 'substance_names' => ['alpha', 'beta'], 'strengths' => ['5 mg/1', '10 mg/1']]);
        $swapped = $this->fill(['ndc_11' => '22222222201', 'substance_names' => ['alpha', 'beta'], 'strengths' => ['10 mg/1', '5 mg/1']]);
        $same = $this->fill(['ndc_11' => '33333333301', 'substance_names' => ['beta', 'alpha'], 'strengths' => ['5 mg/1', '10 mg/1']]);

        $one = $linker->attach($this->patient, $first);
        $two = $linker->attach($this->patient, $swapped);
        $three = $linker->attach($this->patient, $same);

        $this->assertTrue($one['created']);
        $this->assertTrue($two['created'], 'A 10 mg + B 5 mg is not A 5 mg + B 10 mg');
        $this->assertNotSame($one['medication']->id, $two['medication']->id);
        $this->assertFalse($three['created'], 'the same pairs (alpha 10, beta 5) listed in another order are the same formulation');
        $this->assertSame($two['medication']->id, $three['medication']->id);
    }

    public function test_reconcile_does_not_keep_a_medication_of_the_swapped_formulation(): void
    {
        $linker = app(MedicationFillMedicationLinker::class);
        $first = $this->fill(['ndc_11' => '11111111101', 'substance_names' => ['alpha', 'beta'], 'strengths' => ['5 mg/1', '10 mg/1']]);
        $linker->attach($this->patient, $first);
        $first->refresh();
        $first->forceFill(['strengths' => ['10 mg/1', '5 mg/1'], 'ndc_11' => '44444444401'])->save();

        $result = $linker->reconcile($this->patient, $first);

        $this->assertTrue($result['created']);
    }
}

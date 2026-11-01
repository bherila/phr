<?php

namespace Tests\Feature\PHR;

use App\Models\PhrMedication;
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

    public function test_an_unresolved_fills_ndc_digits_are_not_a_product_identity(): void
    {
        $linker = app(MedicationFillMedicationLinker::class);
        $one = $this->fill(['ndc_status' => 'unresolved', 'ndc_10' => '11111-111-01', 'ndc_11' => '11111111101', 'substance_names' => null, 'strengths' => null, 'drug_as_printed' => 'Alpha Tab']);
        $two = $this->fill(['ndc_status' => 'unresolved', 'ndc_10' => '11111-111-02', 'ndc_11' => '11111111102', 'substance_names' => null, 'strengths' => null, 'drug_as_printed' => 'Beta Tab']);

        $first = $linker->attach($this->patient, $one);
        $second = $linker->attach($this->patient, $two);

        $this->assertNotSame($first['medication']->id, $second['medication']->id, 'the same unverified product digits do not merge different printed names');
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

    public function test_fills_of_different_dosage_forms_do_not_merge_through_name_and_dose(): void
    {
        $linker = app(MedicationFillMedicationLinker::class);
        $tablet = $this->fill(['ndc_11' => '11111111101', 'substance_names' => ['alpha'], 'strengths' => ['10 mg/1'], 'dosage_form' => 'tablet']);
        $capsule = $this->fill(['ndc_11' => '22222222201', 'substance_names' => ['alpha'], 'strengths' => ['10 mg/1'], 'dosage_form' => 'capsule']);
        $sameTablet = $this->fill(['ndc_11' => '33333333301', 'substance_names' => ['alpha'], 'strengths' => ['10 mg/1'], 'dosage_form' => 'tablet']);

        $one = $linker->attach($this->patient, $tablet);
        $two = $linker->attach($this->patient, $capsule);
        $three = $linker->attach($this->patient, $sameTablet);

        $this->assertTrue($two['created'], 'a capsule is not the tablet medication');
        $this->assertNotSame($one['medication']->id, $two['medication']->id);
        $this->assertFalse($three['created']);
        $this->assertSame($one['medication']->id, $three['medication']->id, 'the same formulation still joins');
    }

    public function test_an_edit_does_not_keep_a_medication_other_fills_show_to_be_another_formulation(): void
    {
        $linker = app(MedicationFillMedicationLinker::class);
        $tablet = $this->fill(['ndc_11' => '11111111101', 'substance_names' => ['alpha'], 'strengths' => ['10 mg/1'], 'dosage_form' => 'tablet']);
        $medication = $linker->attach($this->patient, $tablet)['medication'];
        $capsule = $this->fill(['ndc_11' => '22222222201', 'substance_names' => ['alpha'], 'strengths' => ['10 mg/1'], 'dosage_form' => 'capsule', 'medication_id' => $medication->id]);

        $result = $linker->reconcile($this->patient, $capsule);

        $this->assertTrue($result['created'], 'name and dose cannot vouch for an entry that tablet fills already describe');
        $this->assertNotSame($medication->id, $result['medication']->id);
    }

    public function test_a_hand_entered_medication_without_fills_still_matches_by_name_and_dose(): void
    {
        $linker = app(MedicationFillMedicationLinker::class);
        $entered = PhrMedication::factory()->create(['patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'name' => 'alpha', 'dose' => '10', 'dose_unit' => 'mg', 'rxnorm_code' => null]);
        $fill = $this->fill(['ndc_11' => '11111111101', 'substance_names' => ['alpha'], 'strengths' => ['10 mg/1'], 'dosage_form' => 'capsule']);

        $result = $linker->attach($this->patient, $fill);

        $this->assertFalse($result['created']);
        $this->assertSame($entered->id, $result['medication']->id);
        // Once a fill describes it, a different dosage form no longer joins it.
        $other = $this->fill(['ndc_11' => '22222222201', 'substance_names' => ['alpha'], 'strengths' => ['10 mg/1'], 'dosage_form' => 'tablet']);
        $this->assertTrue($linker->attach($this->patient, $other)['created']);
    }

    public function test_a_medication_named_with_the_old_slash_delimiter_still_matches_a_combination_fill(): void
    {
        $linker = app(MedicationFillMedicationLinker::class);
        $legacy = PhrMedication::factory()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->owner->id,
            'name' => 'amoxicillin / clavulanate potassium', 'dose' => '600 mg/5 mL / 42.9 mg/5 mL', 'dose_unit' => null, 'rxnorm_code' => null,
        ]);
        $fill = $this->fill([
            'ndc_11' => '33333333301', 'substance_names' => ['amoxicillin', 'clavulanate potassium'],
            'strengths' => ['600 mg/5 mL', '42.9 mg/5 mL'], 'dosage_form' => 'powder, for suspension',
        ]);

        $result = $linker->attach($this->patient, $fill);

        $this->assertFalse($result['created']);
        $this->assertSame($legacy->id, $result['medication']->id);
    }
}

<?php

namespace Tests\Feature\PHR;

use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\Export\PhrExportDataService;
use App\Services\PHR\Export\PhrFhirExporter;
use App\Support\PHR\PhrReviewStatus;
use Tests\TestCase;

/** Fills leave the system as FHIR MedicationDispense, under the same review rule as every clinical export. */
final class MedicationFillExportTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function dispenses(PhrPatient $patient): array
    {
        $data = app(PhrExportDataService::class)->load($patient);
        $bundle = json_decode(app(PhrFhirExporter::class)->bundleJson($data), true, 512, JSON_THROW_ON_ERROR);

        return array_values(array_filter(
            array_column($bundle['entry'], 'resource'),
            static fn (array $resource): bool => $resource['resourceType'] === 'MedicationDispense',
        ));
    }

    public function test_a_confirmed_fill_is_exported_as_a_medication_dispense(): void
    {
        $owner = User::factory()->create();
        $patient = PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Export Patient']);
        $fill = PhrMedicationFill::factory()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id, 'review_status' => PhrReviewStatus::CONFIRMED,
            'filled_on' => '2026-10-28', 'quantity' => '90', 'quantity_unit' => 'tablet', 'days_supply' => 90,
            'rx_number' => 'RX-1', 'directions' => 'Take 1 tablet by mouth daily', 'prescriber_name' => 'Dr. A. Smith',
            'paid_cents' => 1250, 'retail_cents' => 31999, 'refills_remaining' => 3,
            'pharmacy_name' => 'Example Pharmacy', 'pharmacy_location' => 'Seattle WA',
        ]);

        $dispenses = $this->dispenses($patient);

        $this->assertCount(1, $dispenses);
        $dispense = $dispenses[0];
        $this->assertSame('phr-medication-fill-'.$fill->id, $dispense['id']);
        $this->assertSame('completed', $dispense['status']);
        $this->assertSame(['reference' => 'Patient/phr-patient-'.$patient->id], $dispense['subject']);
        $this->assertSame('2026-10-28', $dispense['whenHandedOver']);
        $this->assertSame(['value' => 90, 'unit' => 'tablet'], $dispense['quantity']);
        $this->assertSame(90, $dispense['daysSupply']['value']);
        $this->assertSame('d', $dispense['daysSupply']['code']);
        $this->assertSame([['system' => 'urn:phr:rx-number', 'value' => 'RX-1']], $dispense['identifier']);
        $this->assertSame([['text' => 'Take 1 tablet by mouth daily']], $dispense['dosageInstruction']);
        $this->assertSame(['display' => 'Example Pharmacy, Seattle WA'], $dispense['location']);
        $this->assertSame(
            [
                ['system' => 'http://hl7.org/fhir/sid/ndc', 'code' => '58151015577', 'display' => 'Lipitor'],
                ['system' => 'http://www.nlm.nih.gov/research/umls/rxnorm', 'code' => '617310', 'display' => 'Lipitor'],
            ],
            $dispense['medicationCodeableConcept']['coding'],
        );
        $this->assertSame('Lipitor 10 mg/1 tablet, film coated', $dispense['medicationCodeableConcept']['text']);
        $extensions = array_column($dispense['extension'], null, 'url');
        $this->assertSame(['value' => 12.5, 'currency' => 'USD'], $extensions['urn:phr:fhir:paid-amount']['valueMoney']);
        $this->assertSame(['value' => 319.99, 'currency' => 'USD'], $extensions['urn:phr:fhir:retail-amount']['valueMoney']);
        $this->assertSame('Dr. A. Smith', $extensions['urn:phr:fhir:prescriber']['valueString']);
        $this->assertSame(3, $extensions['urn:phr:fhir:refills-remaining']['valueInteger']);
    }

    public function test_unreviewed_rejected_and_retracted_fills_are_not_exported(): void
    {
        $owner = User::factory()->create();
        $patient = PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Export Patient']);
        $make = fn (string $status, array $extra = []) => PhrMedicationFill::factory()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id, 'review_status' => $status, ...$extra,
        ]);
        $make(PhrReviewStatus::PENDING);
        $make(PhrReviewStatus::REJECTED);
        $make(PhrReviewStatus::CONFIRMED, ['retracted_at' => now()]);
        $make(PhrReviewStatus::CONFIRMED)->delete();
        $kept = $make(PhrReviewStatus::CONFIRMED);

        $this->assertSame(['phr-medication-fill-'.$kept->id], array_column($this->dispenses($patient), 'id'));
    }

    public function test_a_fill_without_a_resolved_drug_is_described_by_what_the_label_said(): void
    {
        $owner = User::factory()->create();
        $patient = PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Export Patient']);
        PhrMedicationFill::factory()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id, 'review_status' => PhrReviewStatus::CONFIRMED,
            'ndc_status' => 'unresolved', 'ndc_10' => null, 'ndc_11' => null, 'rxcui' => null, 'brand_name' => null,
            'substance_names' => null, 'strengths' => null, 'dosage_form' => null, 'drug_as_printed' => 'MYSTERY CAPSULES',
        ]);

        $concept = $this->dispenses($patient)[0]['medicationCodeableConcept'];

        $this->assertSame('MYSTERY CAPSULES', $concept['text']);
        $this->assertArrayNotHasKey('coding', $concept);
    }
}

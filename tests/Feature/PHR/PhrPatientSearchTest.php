<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrImmunization;
use App\Models\PhrMedicationFill;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatientVital;
use Tests\TestCase;

class PhrPatientSearchTest extends TestCase
{
    public function test_it_searches_structured_and_extracted_text_only_within_the_selected_patient(): void
    {
        $owner = $this->createUser();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Synthetic Patient',
        ])->assertCreated()->json('patient.id');
        $otherPatientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Other Synthetic Patient',
        ])->assertCreated()->json('patient.id');

        $visit = PhrOfficeVisit::query()->create([
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            'visit_date' => '2030-04-15',
            'visit_type' => 'Example visit',
            'provider_name' => 'Example Clinician',
            'raw_text' => 'Searchable synthetic phrase',
        ]);
        $document = PhrDocument::query()->create([
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            'title' => 'Example document',
            'document_type' => 'other',
            'source' => 'manual_upload',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => 'patients/example/document.pdf',
            'extracted_text' => 'Searchable synthetic phrase in extracted text',
        ]);
        PhrOfficeVisit::query()->create([
            'patient_id' => $otherPatientId,
            'user_id' => $owner->id,
            'visit_type' => 'Other patient visit',
            'raw_text' => 'Searchable synthetic phrase',
        ]);

        $this->actingAs($owner)
            ->getJson("/api/phr/patients/{$patientId}/search?q=synthetic")
            ->assertOk()
            ->assertJsonCount(2, 'results')
            ->assertJsonFragment([
                'id' => $visit->id,
                'category' => 'Visit',
                'module_id' => 'office-visit-detail',
            ])
            ->assertJsonFragment([
                'id' => $document->id,
                'category' => 'Document',
                'module_id' => 'document-viewer',
            ])
            ->assertJsonMissing(['label' => 'Other patient visit']);
    }

    public function test_it_requires_a_nontrivial_query(): void
    {
        $owner = $this->createUser();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Synthetic Patient',
        ])->assertCreated()->json('patient.id');

        $this->actingAs($owner)
            ->getJson("/api/phr/patients/{$patientId}/search?q=x")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }

    public function test_it_searches_immunizations_and_vitals(): void
    {
        $owner = $this->createUser();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Synthetic Patient',
        ])->assertCreated()->json('patient.id');

        $immunization = PhrImmunization::query()->create([
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            'vaccine_name' => 'Synthetic vaccine',
            'manufacturer' => 'Example manufacturer',
            'administered_on' => '2030-04-15',
        ]);
        $vital = PhrPatientVital::query()->create([
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            'vital_name' => 'Synthetic vital',
            'vital_value' => '123',
            'unit' => 'units',
            'observed_at' => '2030-04-16 08:00:00',
        ]);

        $this->actingAs($owner)
            ->getJson("/api/phr/patients/{$patientId}/search?q=synthetic")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $immunization->id,
                'category' => 'Immunization',
                'module_id' => 'immunization-detail',
            ])
            ->assertJsonFragment([
                'id' => $vital->id,
                'category' => 'Vital',
                'module_id' => 'vitals-reading-detail',
            ]);
    }

    public function test_it_finds_fills_by_name_ndc_rx_number_and_pharmacy(): void
    {
        $owner = $this->createUser();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Synthetic Patient'])
            ->assertCreated()->json('patient.id');
        $fill = PhrMedicationFill::factory()->create([
            'patient_id' => $patientId, 'user_id' => $owner->id, 'filled_on' => '2026-10-28',
            'substance_names' => ['atorvastatin calcium'], 'strengths' => ['20 mg'], 'dosage_form' => 'tablet',
            'drug_as_printed' => 'LIPITOR 20MG TAB', 'brand_name' => 'Lipitor', 'ndc_10' => '0071-0155-23', 'ndc_11' => '00071015523',
            'ndc_as_captured' => '0071-0155-23', 'rx_number' => 'RX7654321', 'pharmacy_name' => 'Example Pharmacy',
            'quantity' => '90.000', 'quantity_unit' => 'tablet',
        ]);

        foreach (['atorvastatin', 'lipitor 20mg', 'lipitor', '0071-0155-23', '00071015523', '0071015523', 'rx7654321', 'example pharm'] as $query) {
            $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/search?q=".urlencode($query))
                ->assertOk()->assertJsonCount(1, 'results')
                ->assertJsonPath('results.0.id', $fill->id)
                ->assertJsonPath('results.0.category', 'Fill')
                ->assertJsonPath('results.0.module_id', 'medication-fills')
                ->assertJsonPath('results.0.label', 'atorvastatin calcium 20 mg tablet')
                ->assertJsonPath('results.0.description', '26.10.28 · Example Pharmacy · Qty 90 tablet')
                ->assertJsonPath('results.0.date', '2026-10-28');
        }
    }

    public function test_fill_search_is_patient_scoped_and_hides_rejected_and_retracted_fills(): void
    {
        $owner = $this->createUser();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Synthetic Patient'])
            ->assertCreated()->json('patient.id');
        $otherPatientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Other Patient'])
            ->assertCreated()->json('patient.id');
        $base = ['user_id' => $owner->id, 'rx_number' => 'ZZ9999'];
        PhrMedicationFill::factory()->create($base + ['patient_id' => $otherPatientId]);
        PhrMedicationFill::factory()->create($base + ['patient_id' => $patientId, 'review_status' => 'rejected']);
        PhrMedicationFill::factory()->create($base + ['patient_id' => $patientId, 'retracted_at' => now()]);
        $visible = PhrMedicationFill::factory()->create($base + ['patient_id' => $patientId]);

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/search?q=zz9999")
            ->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.id', $visible->id);

        $stranger = $this->createUser();
        $this->actingAs($stranger)->getJson("/api/phr/patients/{$patientId}/search?q=zz9999")->assertStatus(404);
    }
}

<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrOfficeVisit;
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
}

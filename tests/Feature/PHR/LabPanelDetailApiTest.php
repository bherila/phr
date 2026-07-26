<?php

namespace Tests\Feature\PHR;

use App\Models\PhrLabResult;
use Tests\TestCase;

class LabPanelDetailApiTest extends TestCase
{
    public function test_it_returns_panel_rows_with_trend_and_source_document_link(): void
    {
        $owner = $this->createUser();

        $patientResponse = $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Primary',
            'relationship' => 'self',
        ])->assertCreated();

        $patientId = (int) $patientResponse->json('patient.id');

        PhrLabResult::query()->create([
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            'test_name' => 'Comprehensive Metabolic Panel',
            'collection_datetime' => '2026-05-18 08:00:00',
            'analyte' => 'Glucose',
            'value_numeric' => 95,
            'value' => '95',
            'unit' => 'mg/dL',
        ]);

        $glucoseCurrent = PhrLabResult::query()->create([
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            'test_name' => 'Comprehensive Metabolic Panel',
            'collection_datetime' => '2026-05-19 08:00:00',
            'ordering_provider' => 'Dr. Rivera',
            'resulting_lab' => 'Quest Diagnostics',
            'analyte' => 'Glucose',
            'value_numeric' => 111,
            'value' => '111',
            'unit' => 'mg/dL',
            'abnormal_flag' => 'H',
            'source_document_id' => 77,
            'source' => 'MyChart',
        ]);

        PhrLabResult::query()->create([
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            'test_name' => 'Comprehensive Metabolic Panel',
            'collection_datetime' => '2026-05-19 08:00:00',
            'ordering_provider' => 'Dr. Rivera',
            'resulting_lab' => 'Quest Diagnostics',
            'analyte' => 'Sodium',
            'value_numeric' => 139,
            'value' => '139',
            'unit' => 'mmol/L',
            'source_document_id' => 77,
            'source' => 'MyChart',
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/phr/patients/{$patientId}/labs/{$glucoseCurrent->id}")
            ->assertOk();

        $response
            ->assertJsonPath('panel.panel_name', 'Comprehensive Metabolic Panel')
            ->assertJsonPath('panel.ordering_provider', 'Dr. Rivera')
            ->assertJsonPath('panel.source_document_id', 77)
            ->assertJsonPath('panel.source_document_url', "http://localhost/api/phr/patients/{$patientId}/documents/77/file")
            ->assertJsonCount(2, 'panel.rows')
            ->assertJsonPath('panel.rows.0.analyte', 'Glucose')
            ->assertJsonPath('panel.rows.0.trend', 'up');
    }
}

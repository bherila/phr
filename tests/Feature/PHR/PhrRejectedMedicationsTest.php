<?php

namespace Tests\Feature\PHR;

use App\Models\User;
use Tests\TestCase;

class PhrRejectedMedicationsTest extends TestCase
{
    public function test_it_lists_rejected_medications_and_fills_for_approval_and_nothing_else(): void
    {
        $owner = User::factory()->create();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Synthetic patient', 'relationship' => 'self'])->assertCreated()->json('patient.id');
        $base = "/api/phr/patients/{$patientId}";

        $rejectedMedication = (int) $this->postJson("{$base}/medications", ['name' => 'Synthetic rejected medication'])->assertCreated()->json('medication.id');
        $kept = (int) $this->postJson("{$base}/medications", ['name' => 'Synthetic kept medication'])->assertCreated()->json('medication.id');
        $rejectedFill = (int) $this->postJson("{$base}/medication-fills", ['drug_as_printed' => 'Synthetic rejected drug', 'filled_on' => '2026-01-20', 'quantity' => '30'])->assertCreated()->json('medication_fill.id');
        $this->patchJson("{$base}/medications/{$rejectedMedication}/review", ['review_status' => 'rejected'])->assertOk();
        $this->patchJson("{$base}/medication-fills/{$rejectedFill}/review", ['review_status' => 'rejected'])->assertOk();
        $this->patchJson("{$base}/medications/{$kept}/review", ['review_status' => 'confirmed'])->assertOk();

        $response = $this->getJson("{$base}/review/rejected")->assertOk()->assertJsonPath('can_manage', true)->assertJsonPath('truncated', false);
        $this->assertEqualsCanonicalizing(
            ["record:medications:{$rejectedMedication}", "record:medication-fills:{$rejectedFill}"],
            array_column($response->json('items'), 'key'),
        );

        // Approved after all, through the record's own review route, it leaves the list and rejoins its section.
        $item = collect($response->json('items'))->firstWhere('key', "record:medication-fills:{$rejectedFill}");
        $this->patchJson("{$base}/medication-fills/{$rejectedFill}/review", ['review_status' => 'confirmed', 'version' => $item['version']])->assertOk();
        $this->assertSame(["record:medications:{$rejectedMedication}"], array_column($this->getJson("{$base}/review/rejected")->json('items'), 'key'));
    }

    public function test_only_someone_with_access_to_the_patient_can_read_it(): void
    {
        $owner = User::factory()->create();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Synthetic patient', 'relationship' => 'self'])->assertCreated()->json('patient.id');

        $this->actingAs(User::factory()->create())->getJson("/api/phr/patients/{$patientId}/review/rejected")->assertNotFound();
    }
}

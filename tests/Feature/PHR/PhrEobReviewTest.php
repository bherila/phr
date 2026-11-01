<?php

namespace Tests\Feature\PHR;

use App\Models\PhrEob;
use App\Models\PhrPatientUserAccess;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\PHR\PhrReviewStatus;
use Tests\TestCase;

class PhrEobReviewTest extends TestCase
{
    public function test_a_person_confirms_or_rejects_an_eob_an_agent_wrote(): void
    {
        $owner = $this->createUser();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Synthetic Review Patient'])->assertCreated()->json('patient.id');
        $eob = PhrEob::query()->create([
            'patient_id' => $patientId, 'user_id' => $owner->id, 'import_source' => 'agent-client:x', 'external_id' => 'eob-1',
            'claim_type' => 'medical', 'review_status' => PhrReviewStatus::PENDING,
        ]);
        $url = "/api/phr/patients/{$patientId}/eobs/{$eob->id}/review";

        // A stale version is refused, a pending status is not a decision.
        $this->patchJson($url, ['review_status' => 'confirmed', 'version' => str_repeat('0', 64)])->assertConflict();
        $this->patchJson($url, ['review_status' => PhrReviewStatus::PENDING])->assertUnprocessable();
        $this->assertSame(PhrReviewStatus::PENDING, $eob->refresh()->review_status);

        $version = app(AgentClinicalRecordVersion::class)->for($eob);
        $this->patchJson($url, ['review_status' => 'confirmed', 'version' => $version])
            ->assertOk()
            ->assertJsonPath('eob.review_status', 'confirmed');
        $this->assertSame(PhrReviewStatus::CONFIRMED, $eob->refresh()->review_status);
        $this->patchJson($url, ['review_status' => 'rejected'])->assertOk()->assertJsonPath('eob.review_status', 'rejected');
    }

    public function test_a_viewer_cannot_review(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Synthetic Review Patient'])->assertCreated()->json('patient.id');
        PhrPatientUserAccess::query()->create(['patient_id' => $patientId, 'user_id' => $viewer->id, 'access_level' => PhrPatientUserAccess::LEVEL_VIEWER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $eob = PhrEob::query()->create(['patient_id' => $patientId, 'user_id' => $owner->id, 'import_source' => 'x', 'external_id' => 'eob-2', 'claim_type' => 'medical', 'review_status' => PhrReviewStatus::PENDING]);

        $this->actingAs($viewer)->patchJson("/api/phr/patients/{$patientId}/eobs/{$eob->id}/review", ['review_status' => 'confirmed'])->assertForbidden();
        $this->assertSame(PhrReviewStatus::PENDING, $eob->refresh()->review_status);
    }
}

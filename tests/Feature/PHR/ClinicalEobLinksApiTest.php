<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrOfficeVisit;
use App\Models\PhrProcedure;
use Tests\TestCase;

class ClinicalEobLinksApiTest extends TestCase
{
    public function test_it_searches_by_service_date_and_links_or_unlinks_eobs(): void
    {
        $owner = $this->createUser();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Example Patient',
        ])->assertCreated()->json('patient.id');

        $document = PhrDocument::query()->create([
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            'title' => 'Example claim document',
            'document_type' => 'insurance',
            'storage_disk' => 'phr_documents',
            'storage_path' => 'patients/example/eob.pdf',
            'mime_type' => 'application/pdf',
        ]);
        $matchingEob = $this->eob($patientId, $owner->id, 'EXAMPLE-001', $document->id);
        $this->line($matchingEob, '2030-04-15', '2030-04-15');
        $rangeEob = $this->eob($patientId, $owner->id, 'EXAMPLE-002');
        $this->line($rangeEob, '2030-04-14', '2030-04-16');
        $otherEob = $this->eob($patientId, $owner->id, 'EXAMPLE-003');
        $this->line($otherEob, '2030-05-01', null);

        $visit = PhrOfficeVisit::query()->create([
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            'visit_date' => '2030-04-15',
            'visit_type' => 'Office',
        ]);
        $procedure = PhrProcedure::query()->create([
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            'name' => 'Example procedure',
            'performed_on' => '2030-04-15',
            'status' => 'completed',
        ]);

        $this->actingAs($owner)
            ->getJson("/api/phr/patients/{$patientId}/eobs?service_date=2030-04-15")
            ->assertOk()
            ->assertJsonCount(2, 'eobs')
            ->assertJsonPath('can_manage', true)
            ->assertJsonFragment(['claim_number' => 'EXAMPLE-001'])
            ->assertJsonFragment(['claim_number' => 'EXAMPLE-002'])
            ->assertJsonMissing(['claim_number' => 'EXAMPLE-003'])
            ->assertJsonPath('eobs.0.service_start', '2030-04-14');

        $visitLink = "/api/phr/patients/{$patientId}/office-visits/{$visit->id}/eobs/{$matchingEob->id}";
        $this->actingAs($owner)->postJson($visitLink)->assertCreated();
        $this->actingAs($owner)->postJson($visitLink)->assertCreated();
        self::assertDatabaseCount('phr_office_visit_eobs', 1);

        $this->actingAs($owner)
            ->getJson("/api/phr/patients/{$patientId}/office-visits/{$visit->id}")
            ->assertOk()
            ->assertJsonPath('can_manage', true)
            ->assertJsonPath('office_visit.eobs.0.id', $matchingEob->id)
            ->assertJsonPath(
                'office_visit.eobs.0.source_document_url',
                "http://localhost/api/phr/patients/{$patientId}/documents/{$document->id}/file",
            );

        $procedureLink = "/api/phr/patients/{$patientId}/procedures/{$procedure->id}/eobs/{$rangeEob->id}";
        $this->actingAs($owner)->postJson($procedureLink)->assertCreated();
        $this->actingAs($owner)
            ->getJson("/api/phr/patients/{$patientId}/procedures/{$procedure->id}")
            ->assertOk()
            ->assertJsonPath('procedure.eobs.0.id', $rangeEob->id);

        $this->actingAs($owner)->deleteJson($visitLink)->assertNoContent();
        $this->actingAs($owner)->deleteJson($procedureLink)->assertNoContent();
        self::assertDatabaseCount('phr_office_visit_eobs', 0);
        self::assertDatabaseCount('phr_procedure_eobs', 0);
    }

    public function test_viewers_can_read_links_but_cannot_change_them(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Example Patient',
        ])->assertCreated()->json('patient.id');
        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/access", [
            'email' => $viewer->email,
            'access_level' => 'viewer',
        ])->assertCreated();

        $visit = PhrOfficeVisit::query()->create([
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            'visit_date' => '2030-04-15',
        ]);
        $eob = $this->eob($patientId, $owner->id, 'EXAMPLE-004');
        $this->line($eob, '2030-04-15', null);
        $visit->eobs()->attach($eob->id, ['patient_id' => $patientId]);

        $link = "/api/phr/patients/{$patientId}/office-visits/{$visit->id}/eobs/{$eob->id}";
        $this->actingAs($viewer)
            ->getJson("/api/phr/patients/{$patientId}/office-visits/{$visit->id}")
            ->assertOk()
            ->assertJsonPath('can_manage', false)
            ->assertJsonPath('office_visit.eobs.0.id', $eob->id);
        $this->actingAs($viewer)->postJson($link)->assertForbidden();
        $this->actingAs($viewer)->deleteJson($link)->assertForbidden();
    }

    private function eob(int $patientId, int $userId, string $claimNumber, ?int $documentId = null): PhrEob
    {
        return PhrEob::query()->create([
            'patient_id' => $patientId,
            'user_id' => $userId,
            'source_document_id' => $documentId,
            'import_source' => 'synthetic_test',
            'external_id' => $claimNumber,
            'claim_number' => $claimNumber,
            'claim_type' => 'medical',
            'provider_name' => 'Example Clinic',
            'processed_date' => '2030-04-20',
        ]);
    }

    private function line(PhrEob $eob, string $serviceStart, ?string $serviceEnd): void
    {
        PhrEobLine::query()->create([
            'eob_id' => $eob->id,
            'patient_id' => $eob->patient_id,
            'line_number' => 1,
            'procedure_code' => '99213',
            'code_type' => 'CPT',
            'service_start' => $serviceStart,
            'service_end' => $serviceEnd,
        ]);
    }
}

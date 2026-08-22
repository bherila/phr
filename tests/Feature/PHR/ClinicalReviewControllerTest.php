<?php

namespace Tests\Feature\PHR;

use App\Models\PhrAllergy;
use App\Models\PhrCondition;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatientVital;
use App\Models\PhrProcedure;
use App\Models\User;
use App\Support\PHR\PhrReviewStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The browser review surface: the only place a record may leave `pending_review`.
 */
class ClinicalReviewControllerTest extends TestCase
{
    /**
     * Every clinical resource carrying a review lifecycle, with the path segment,
     * response key, and the fields needed to build one.
     *
     * @return array<string, array{0: string, 1: string, 2: class-string, 3: array<string, mixed>}>
     */
    public static function reviewableResources(): array
    {
        return [
            'allergies' => ['allergies', 'allergy', PhrAllergy::class, ['substance' => 'Penicillin']],
            'conditions' => ['conditions', 'condition', PhrCondition::class, ['name' => 'Hypertension']],
            'immunizations' => ['immunizations', 'immunization', PhrImmunization::class, ['vaccine_name' => 'Influenza']],
            'lab-results' => ['lab-results', 'lab_result', PhrLabResult::class, ['test_name' => 'CBC', 'analyte' => 'Hemoglobin']],
            'medications' => ['medications', 'medication', PhrMedication::class, ['name' => 'Metformin']],
            'vitals' => ['vitals', 'vital', PhrPatientVital::class, ['vital_name' => 'Blood Pressure', 'vital_value' => '120/80']],
            'office-visits' => ['office-visits', 'office_visit', PhrOfficeVisit::class, ['visit_date' => '2026-01-15']],
            'procedures' => ['procedures', 'procedure', PhrProcedure::class, ['name' => 'Colonoscopy']],
        ];
    }

    /**
     * @param  class-string  $modelClass
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('reviewableResources')]
    public function test_a_reviewer_can_confirm_and_reject_every_clinical_resource(
        string $path,
        string $key,
        string $modelClass,
        array $attributes,
    ): void {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();
        $record = $this->createRecord($modelClass, $patientId, $owner, $attributes);
        $url = "/api/phr/patients/{$patientId}/{$path}/{$record->id}/review";

        $this->actingAs($owner)->patchJson($url, ['review_status' => PhrReviewStatus::CONFIRMED])
            ->assertOk()
            ->assertJsonPath("{$key}.review_status", PhrReviewStatus::CONFIRMED);
        $this->assertSame(PhrReviewStatus::CONFIRMED, $record->fresh()?->review_status);

        $this->actingAs($owner)->patchJson($url, ['review_status' => PhrReviewStatus::REJECTED])
            ->assertOk()
            ->assertJsonPath("{$key}.review_status", PhrReviewStatus::REJECTED);
        $this->assertSame(PhrReviewStatus::REJECTED, $record->fresh()?->review_status);

        // A rejection is recoverable, which is why rejected rows are kept.
        $this->actingAs($owner)->patchJson($url, ['review_status' => PhrReviewStatus::CONFIRMED])
            ->assertOk()
            ->assertJsonPath("{$key}.review_status", PhrReviewStatus::CONFIRMED);
    }

    /**
     * @param  class-string  $modelClass
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('reviewableResources')]
    public function test_rejected_records_leave_the_working_list_but_stay_reachable(
        string $path,
        string $key,
        string $modelClass,
        array $attributes,
    ): void {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();
        $kept = $this->createRecord($modelClass, $patientId, $owner, $attributes);
        $rejected = $this->createRecord($modelClass, $patientId, $owner, $attributes);

        $this->actingAs($owner)
            ->patchJson("/api/phr/patients/{$patientId}/{$path}/{$rejected->id}/review", [
                'review_status' => PhrReviewStatus::REJECTED,
            ])->assertOk();

        $collectionKey = str_replace('-', '_', $path === 'lab-results' ? 'lab_results' : $path);
        $listUrl = "/api/phr/patients/{$patientId}/{$path}";

        $default = $this->actingAs($owner)->getJson($listUrl)->assertOk()->json($collectionKey);
        $this->assertSame([$kept->id], array_column($default, 'id'));

        $withRejected = $this->actingAs($owner)->getJson("{$listUrl}?include_rejected=1")->assertOk()->json($collectionKey);
        $this->assertEqualsCanonicalizing([$kept->id, $rejected->id], array_column($withRejected, 'id'));

        // Hidden from the list is not hidden from the app: it must still be fetchable.
        $this->actingAs($owner)->getJson("{$listUrl}/{$rejected->id}")
            ->assertOk()
            ->assertJsonPath("{$key}.review_status", PhrReviewStatus::REJECTED);
    }

    public function test_review_rejects_a_status_a_reviewer_may_not_assign(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();
        $record = $this->createRecord(PhrAllergy::class, $patientId, $owner, ['substance' => 'Penicillin']);
        $url = "/api/phr/patients/{$patientId}/allergies/{$record->id}/review";

        // Returning a record to the queue is the server's job, not a reviewer's.
        $this->actingAs($owner)->patchJson($url, ['review_status' => PhrReviewStatus::PENDING])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['review_status']);

        $this->actingAs($owner)->patchJson($url, ['review_status' => 'not-a-status'])
            ->assertStatus(422);

        $this->actingAs($owner)->patchJson($url, [])
            ->assertStatus(422);

        $this->assertSame(PhrReviewStatus::CONFIRMED, $record->fresh()?->review_status);
    }

    public function test_review_enforces_the_same_access_rules_as_any_other_write(): void
    {
        [
            'owner' => $owner,
            'manager' => $manager,
            'viewer' => $viewer,
            'patientId' => $patientId,
        ] = $this->createPatientWithAccess();
        $record = $this->createRecord(PhrAllergy::class, $patientId, $owner, ['substance' => 'Penicillin']);
        $url = "/api/phr/patients/{$patientId}/allergies/{$record->id}/review";
        $payload = ['review_status' => PhrReviewStatus::REJECTED];

        $this->actingAs($manager)->patchJson($url, $payload)->assertOk();
        $this->actingAs($viewer)->patchJson($url, $payload)->assertForbidden();
        $this->actingAs($this->createUser())->patchJson($url, $payload)->assertNotFound();
    }

    public function test_review_requires_authentication(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();
        $record = $this->createRecord(PhrAllergy::class, $patientId, $owner, ['substance' => 'Penicillin']);

        $this->app['auth']->forgetGuards();

        $this->patchJson("/api/phr/patients/{$patientId}/allergies/{$record->id}/review", [
            'review_status' => PhrReviewStatus::REJECTED,
        ])->assertUnauthorized();
    }

    public function test_review_cannot_reach_another_patients_record(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();
        ['patientId' => $otherPatientId] = $this->createPatientWithAccess();
        $record = $this->createRecord(PhrAllergy::class, $otherPatientId, $owner, ['substance' => 'Latex']);

        $this->actingAs($owner)
            ->patchJson("/api/phr/patients/{$patientId}/allergies/{$record->id}/review", [
                'review_status' => PhrReviewStatus::REJECTED,
            ])->assertNotFound();

        $this->assertSame(PhrReviewStatus::CONFIRMED, $record->fresh()?->review_status);
    }

    public function test_the_backfill_returns_only_machine_asserted_confirmations_to_the_queue(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        // Asserted by an agent before the server owned the column.
        $selfAsserted = $this->createRecord(PhrAllergy::class, $patientId, $owner, [
            'substance' => 'Penicillin',
            'import_source' => 'agent-client:0199f2b7-0000-7000-8000-000000000001',
            'review_status' => PhrReviewStatus::CONFIRMED,
        ]);
        // Entered by a person in the browser.
        $humanEntered = $this->createRecord(PhrAllergy::class, $patientId, $owner, [
            'substance' => 'Latex',
            'review_status' => PhrReviewStatus::CONFIRMED,
        ]);
        // Written by an agent and never confirmed.
        $alreadyPending = $this->createRecord(PhrAllergy::class, $patientId, $owner, [
            'substance' => 'Sulfa',
            'import_source' => 'agent-client:0199f2b7-0000-7000-8000-000000000001',
            'review_status' => PhrReviewStatus::PENDING,
        ]);
        // Written by a non-agent importer.
        $otherImport = $this->createRecord(PhrAllergy::class, $patientId, $owner, [
            'substance' => 'Shellfish',
            'import_source' => 'ccda-upload',
            'review_status' => PhrReviewStatus::CONFIRMED,
        ]);

        require_once database_path('migrations/2026_08_22_090000_reset_agent_self_asserted_review_status.php');
        (require database_path('migrations/2026_08_22_090000_reset_agent_self_asserted_review_status.php'))->up();

        $this->assertSame(PhrReviewStatus::PENDING, $selfAsserted->fresh()?->review_status);
        $this->assertSame(PhrReviewStatus::CONFIRMED, $humanEntered->fresh()?->review_status);
        $this->assertSame(PhrReviewStatus::PENDING, $alreadyPending->fresh()?->review_status);
        $this->assertSame(PhrReviewStatus::CONFIRMED, $otherImport->fresh()?->review_status);
    }

    /**
     * @param  class-string  $modelClass
     * @param  array<string, mixed>  $attributes
     */
    private function createRecord(string $modelClass, int $patientId, User $owner, array $attributes): mixed
    {
        return $modelClass::create([
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            ...$attributes,
        ]);
    }

    /**
     * @return array{owner: User, manager: User, viewer: User, patientId: int}
     */
    private function createPatientWithAccess(): array
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $viewer = $this->createUser();

        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Review Patient',
            'relationship' => 'self',
        ])->assertCreated()->json('patient.id');

        foreach (['manager' => $manager, 'viewer' => $viewer] as $level => $user) {
            $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/access", [
                'email' => $user->email,
                'access_level' => $level,
            ])->assertCreated();
        }

        return compact('owner', 'manager', 'viewer', 'patientId');
    }
}

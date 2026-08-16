<?php

namespace Tests\Feature;

use App\Models\AgentApiAudit;
use App\Models\PhrAllergy;
use App\Models\PhrCondition;
use App\Models\PhrHealthLog;
use App\Models\PhrHealthLogEntry;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrNativeRecordIdentity;
use App\Models\PhrNativeRestoreAttempt;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\PhrPatientVital;
use App\Models\PhrProcedure;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AgentApiClinicalReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        config([
            'passport.private_key' => $privateKey,
            'passport.public_key' => $details['key'],
        ]);
    }

    public function test_patient_discovery_is_bounded_and_exposes_only_the_callers_access_metadata(): void
    {
        $actor = $this->user('agent-reader@example.test');
        $otherOwner = $this->user('other-owner@example.test');
        $unrelated = $this->user('unrelated@example.test');
        $owned = $this->patient($actor, 'Synthetic Owned Patient', notes: 'Synthetic private note');
        $shared = $this->patient($otherOwner, 'Synthetic Shared Patient');
        $hidden = $this->patient($unrelated, 'Synthetic Hidden Patient');
        PhrPatientUserAccess::query()->create([
            'patient_id' => $shared->id,
            'user_id' => $actor->id,
            'access_level' => PhrPatientUserAccess::LEVEL_VIEWER,
            'granted_by_user_id' => $otherOwner->id,
            'granted_at' => now(),
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $shared->id,
            'user_id' => $unrelated->id,
            'access_level' => PhrPatientUserAccess::LEVEL_MANAGER,
            'granted_by_user_id' => $otherOwner->id,
            'granted_at' => now(),
        ]);

        Passport::actingAs($actor, [AgentApiScopes::PATIENTS_READ]);

        $first = $this->getJson('/api/v1/patients?limit=1')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.limit', 1)
            ->assertJsonPath('pagination.has_more', true)
            ->json();
        $this->assertIsString($first['pagination']['next_cursor']);

        $second = $this->getJson('/api/v1/patients?limit=1&cursor='.urlencode($first['pagination']['next_cursor']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json();
        $patientIds = [$first['data'][0]['id'], $second['data'][0]['id']];
        sort($patientIds);
        $expectedIds = [$owned->id, $shared->id];
        sort($expectedIds);
        $this->assertSame($expectedIds, $patientIds);

        $sharedResponse = $this->getJson("/api/v1/patients/{$shared->id}")
            ->assertOk()
            ->assertJsonPath('data.access.level', PhrPatientUserAccess::LEVEL_VIEWER)
            ->assertJsonPath('data.access.is_owner', false)
            ->assertJsonPath('data.access.can_write', false)
            ->json();
        $encodedShared = json_encode($sharedResponse, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('other-owner@example.test', $encodedShared);
        $this->assertStringNotContainsString('unrelated@example.test', $encodedShared);
        $this->assertStringNotContainsString('owner_user_id', $encodedShared);
        $this->assertStringNotContainsString('access_grants', $encodedShared);

        $this->getJson("/api/v1/patients/{$owned->id}")
            ->assertOk()
            ->assertJsonPath('data.notes', 'Synthetic private note')
            ->assertJsonPath('data.access.level', PhrPatientUserAccess::LEVEL_OWNER)
            ->assertJsonPath('data.access.can_write', true);
        $this->getJson("/api/v1/patients/{$hidden->id}")->assertNotFound();
    }

    public function test_patient_and_clinical_scopes_are_independent_and_default_deny(): void
    {
        $actor = $this->user('scope-reader@example.test');
        $patient = $this->patient($actor, 'Synthetic Scope Patient');
        $visit = PhrOfficeVisit::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $actor->id,
            'visit_type' => 'synthetic',
        ]);

        Passport::actingAs($actor, [AgentApiScopes::PATIENTS_READ]);
        $this->getJson('/api/v1/patients')->assertOk();
        $this->getJson("/api/v1/patients/{$patient->id}/office-visits")->assertForbidden();

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ]);
        $this->getJson('/api/v1/patients')->assertForbidden();
        $this->getJson("/api/v1/patients/{$patient->id}/office-visits/{$visit->id}")->assertOk();

        Passport::actingAs($actor, [AgentApiScopes::IDENTITY_READ]);
        $this->getJson('/api/v1/patients')->assertForbidden();
        $this->getJson("/api/v1/patients/{$patient->id}/office-visits")->assertForbidden();
    }

    public function test_patient_update_windows_advance_when_the_callers_grant_changes(): void
    {
        $owner = $this->user('window-owner@example.test');
        $actor = $this->user('window-reader@example.test');
        $this->travelTo(Carbon::parse('2026-08-16 10:00:00'));
        $patient = $this->patient($owner, 'Synthetic Grant Window Patient');
        $this->travelTo(Carbon::parse('2026-08-16 11:00:00'));
        $grant = PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $actor->id,
            'access_level' => PhrPatientUserAccess::LEVEL_VIEWER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);

        Passport::actingAs($actor, [AgentApiScopes::PATIENTS_READ]);
        $this->getJson('/api/v1/patients?updated_after=2026-08-16T10:30:00Z')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $patient->id)
            ->assertJsonPath('data.0.access.level', PhrPatientUserAccess::LEVEL_VIEWER);

        $this->travelTo(Carbon::parse('2026-08-16 12:00:00'));
        $grant->update(['access_level' => PhrPatientUserAccess::LEVEL_MANAGER]);
        $this->getJson('/api/v1/patients?updated_after=2026-08-16T11:30:00Z')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.access.level', PhrPatientUserAccess::LEVEL_MANAGER)
            ->assertJsonPath('data.0.access.can_write', true);

        $this->travelBack();
    }

    public function test_health_log_update_windows_advance_when_entries_mutate(): void
    {
        $actor = $this->user('health-window-reader@example.test');
        $patient = $this->patient($actor, 'Synthetic Health Window Patient');
        $this->travelTo(Carbon::parse('2026-08-16 10:00:00'));
        $healthLog = PhrHealthLog::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $actor->id,
            'created_by_user_id' => $actor->id,
            'name' => 'Synthetic Window Log',
            'kind' => PhrHealthLog::KIND_CUSTOM,
        ]);
        $this->travelTo(Carbon::parse('2026-08-16 11:00:00'));
        PhrHealthLogEntry::query()->create([
            'health_log_id' => $healthLog->id,
            'patient_id' => $patient->id,
            'user_id' => $actor->id,
            'recorded_by_user_id' => $actor->id,
            'occurred_at' => now(),
            'title' => 'Synthetic window entry',
        ]);

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ]);
        $this->getJson("/api/v1/patients/{$patient->id}/health-logs?updated_after=2026-08-16T10:30:00Z")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $healthLog->id)
            ->assertJsonPath('data.0.entries_count', 1);

        $this->travelBack();
    }

    public function test_update_windows_use_restore_ingestion_time_without_rewriting_archived_timestamps(): void
    {
        $actor = $this->user('restore-window-reader@example.test');
        $this->travelTo(Carbon::parse('2026-08-16 09:00:00'));
        $patient = $this->patient($actor, 'Synthetic Restored Window Patient');
        $visit = PhrOfficeVisit::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $actor->id,
            'visit_type' => 'synthetic-restored',
        ]);
        $archivedPatientUpdatedAt = $patient->updated_at?->toDateTimeString();
        $archivedVisitUpdatedAt = $visit->updated_at?->toDateTimeString();

        $this->travelTo(Carbon::parse('2026-08-16 11:00:00'));
        $attempt = PhrNativeRestoreAttempt::query()->create([
            'actor_user_id' => $actor->id,
            'source_storage_disk' => 'phr_exports',
            'source_file_size_bytes' => 1,
            'uploaded_bytes' => 1,
            'archive_sha256' => str_repeat('a', 64),
            'schema_version' => 1,
            'patient_native_id' => (string) Str::uuid(),
            'target_patient_root_id' => $patient->id,
            'plan_digest' => str_repeat('b', 64),
            'plan_counts_json' => ['tables' => [], 'artifacts' => [], 'blockers' => []],
            'status' => PhrNativeRestoreAttempt::STATUS_FINALIZING,
            'expires_at' => now()->addDay(),
        ]);
        foreach ([
            ['table' => 'phr_patients', 'record_id' => $patient->id],
            ['table' => 'phr_office_visits', 'record_id' => $visit->id],
        ] as $identity) {
            PhrNativeRecordIdentity::query()->create([
                'patient_id' => $patient->id,
                'record_table' => $identity['table'],
                'record_id' => $identity['record_id'],
                'native_id' => (string) Str::uuid(),
                // A graph commit is visible just before the finalizer publishes
                // its timestamp. This sentinel must behave as newly restored.
                'restored_at' => null,
                'restore_attempt_id' => $attempt->id,
            ]);
        }

        Passport::actingAs($actor, [AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ]);
        $this->getJson('/api/v1/patients?updated_after=2026-08-16T10:00:00Z')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $patient->id);
        $this->getJson("/api/v1/patients/{$patient->id}/office-visits?updated_after=2026-08-16T10:00:00Z")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visit->id);
        $this->getJson('/api/v1/patients?updated_before=2026-08-16T10:00:00Z')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/patients/{$patient->id}/office-visits?updated_before=2026-08-16T10:00:00Z")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        PhrNativeRecordIdentity::query()
            ->where('restore_attempt_id', $attempt->id)
            ->update(['restored_at' => now()]);

        $this->assertSame($archivedPatientUpdatedAt, $patient->fresh()?->updated_at?->toDateTimeString());
        $this->assertSame($archivedVisitUpdatedAt, $visit->fresh()?->updated_at?->toDateTimeString());
        $this->travelBack();
    }

    public function test_all_declared_core_clinical_resources_share_the_existing_serializers(): void
    {
        $actor = $this->user('clinical-reader@example.test');
        $patient = $this->patient($actor, 'Synthetic Clinical Patient');
        $documentId = 41;
        $records = [
            'office-visits' => PhrOfficeVisit::query()->create([
                'patient_id' => $patient->id,
                'user_id' => $actor->id,
                'visit_type' => 'synthetic',
                'import_source' => 'synthetic-import',
                'external_id' => 'synthetic-visit',
                'source_document_id' => $documentId,
            ]),
            'procedures' => PhrProcedure::query()->create([
                'patient_id' => $patient->id,
                'user_id' => $actor->id,
                'name' => 'Synthetic procedure',
                'import_source' => 'synthetic-import',
                'external_id' => 'synthetic-procedure',
                'source_document_id' => $documentId,
            ]),
            'immunizations' => PhrImmunization::query()->create([
                'patient_id' => $patient->id,
                'user_id' => $actor->id,
                'vaccine_name' => 'Synthetic vaccine',
                'import_source' => 'synthetic-import',
                'external_id' => 'synthetic-immunization',
                'source_document_id' => $documentId,
            ]),
            'medications' => PhrMedication::query()->create([
                'patient_id' => $patient->id,
                'user_id' => $actor->id,
                'name' => 'Synthetic medication',
                'import_source' => 'synthetic-import',
                'external_id' => 'synthetic-medication',
                'source_document_id' => $documentId,
            ]),
            'conditions' => PhrCondition::query()->create([
                'patient_id' => $patient->id,
                'user_id' => $actor->id,
                'name' => 'Synthetic condition',
                'import_source' => 'synthetic-import',
                'external_id' => 'synthetic-condition',
                'source_document_id' => $documentId,
            ]),
            'allergies' => PhrAllergy::query()->create([
                'patient_id' => $patient->id,
                'user_id' => $actor->id,
                'substance' => 'Synthetic allergen',
                'import_source' => 'synthetic-import',
                'external_id' => 'synthetic-allergy',
                'source_document_id' => $documentId,
            ]),
            'lab-results' => PhrLabResult::query()->create([
                'patient_id' => $patient->id,
                'user_id' => $actor->id,
                'test_name' => 'Synthetic panel',
                'import_source' => 'synthetic-import',
                'external_id' => 'synthetic-lab',
                'source_document_id' => $documentId,
            ]),
            'vitals' => PhrPatientVital::query()->create([
                'patient_id' => $patient->id,
                'user_id' => $actor->id,
                'vital_name' => 'Synthetic vital',
                'import_source' => 'synthetic-import',
                'external_id' => 'synthetic-vital',
                'source_document_id' => $documentId,
            ]),
            'health-logs' => PhrHealthLog::query()->create([
                'patient_id' => $patient->id,
                'user_id' => $actor->id,
                'created_by_user_id' => $actor->id,
                'name' => 'Synthetic log',
                'kind' => PhrHealthLog::KIND_CUSTOM,
            ]),
        ];
        $healthLog = $records['health-logs'];
        $this->assertInstanceOf(PhrHealthLog::class, $healthLog);
        PhrHealthLogEntry::query()->create([
            'health_log_id' => $healthLog->id,
            'patient_id' => $patient->id,
            'user_id' => $actor->id,
            'recorded_by_user_id' => $actor->id,
            'occurred_at' => now(),
            'title' => 'Synthetic entry',
        ]);

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ]);

        $this->assertSame(AgentClinicalResourceCatalog::ids(), array_keys($records));
        foreach ($records as $resource => $record) {
            $list = $this->getJson("/api/v1/patients/{$patient->id}/{$resource}?limit=1")
                ->assertOk()
                ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
                ->assertJsonPath('resource_type', $resource)
                ->assertJsonPath('patient_id', $patient->id)
                ->assertJsonPath('data.0.id', $record->id)
                ->assertJsonPath('pagination.limit', 1);

            $this->getJson("/api/v1/patients/{$patient->id}/{$resource}/{$record->id}")
                ->assertOk()
                ->assertJsonPath('data.id', $record->id)
                ->assertJsonPath('data.patient_id', $patient->id);

            if ($resource === 'health-logs') {
                $list->assertJsonPath('data.0.entries_count', 1);
            } else {
                $list
                    ->assertJsonPath('data.0.import_source', 'synthetic-import')
                    ->assertJsonPath('data.0.source_document_id', $documentId);
            }
        }
    }

    public function test_clinical_lists_filter_paginate_and_never_cross_patient_boundaries(): void
    {
        $actor = $this->user('bounded-reader@example.test');
        $other = $this->user('bounded-other@example.test');
        $patient = $this->patient($actor, 'Synthetic Bounded Patient');
        $hiddenPatient = $this->patient($other, 'Synthetic Other Patient');
        foreach (['source-a', 'source-b', 'source-a'] as $index => $source) {
            PhrOfficeVisit::query()->create([
                'patient_id' => $patient->id,
                'user_id' => $actor->id,
                'visit_type' => 'synthetic-'.($index + 1),
                'import_source' => $source,
            ]);
        }
        $hiddenVisit = PhrOfficeVisit::query()->create([
            'patient_id' => $hiddenPatient->id,
            'user_id' => $other->id,
            'visit_type' => 'synthetic-hidden',
            'import_source' => 'source-a',
        ]);

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ]);
        $first = $this->getJson("/api/v1/patients/{$patient->id}/office-visits?limit=1&import_source=source-a")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.has_more', true)
            ->json();
        $this->assertIsString($first['pagination']['next_cursor']);
        $second = $this->getJson(
            "/api/v1/patients/{$patient->id}/office-visits?limit=1&import_source=source-a&cursor=".
            urlencode($first['pagination']['next_cursor']),
        )->assertOk()->assertJsonCount(1, 'data')->json();
        $this->assertNotSame($first['data'][0]['id'], $second['data'][0]['id']);

        $this->getJson("/api/v1/patients/{$patient->id}/office-visits?limit=101")
            ->assertUnprocessable()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->getJson("/api/v1/patients/{$patient->id}/office-visits?cursor=not-a-cursor")
            ->assertUnprocessable()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $emptyCursor = rtrim(strtr(base64_encode('{}'), '+/', '-_'), '=');
        $this->getJson("/api/v1/patients/{$patient->id}/office-visits?cursor=".urlencode($emptyCursor))
            ->assertUnprocessable();
        $hostileCursor = rtrim(strtr(base64_encode(json_encode([
            'id' => ['not-scalar'],
            '_pointsToNextItems' => true,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $this->getJson("/api/v1/patients/{$patient->id}/office-visits?cursor=".urlencode($hostileCursor))
            ->assertUnprocessable();
        Passport::actingAs($actor, [AgentApiScopes::PATIENTS_READ]);
        $this->getJson('/api/v1/patients?cursor='.urlencode($hostileCursor))->assertUnprocessable();
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ]);
        $this->getJson("/api/v1/patients/{$patient->id}/health-logs?import_source=source-a")->assertUnprocessable();
        $this->getJson("/api/v1/patients/{$patient->id}/unknown-resource")
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->getJson("/api/v1/patients/{$patient->id}/office-visits/{$hiddenVisit->id}")->assertNotFound();
        $this->getJson("/api/v1/patients/{$hiddenPatient->id}/office-visits")->assertNotFound();
    }

    public function test_invalid_bearers_share_a_normalized_pre_authentication_bucket_across_ids(): void
    {
        config(['agent_api.authentication_attempts_per_minute' => 2]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.91']);

        $this->withToken('synthetic-invalid-one')->getJson('/api/v1/patients/1')->assertUnauthorized();
        $this->withToken('synthetic-invalid-two')->getJson('/api/v1/patients/2')->assertUnauthorized();
        $this->withToken('synthetic-invalid-three')->getJson('/api/v1/patients/3')->assertTooManyRequests();
    }

    public function test_agent_clinical_audits_remain_metadata_only(): void
    {
        $actor = $this->user('audit-reader@example.test');
        $patient = $this->patient($actor, 'Synthetic Audit Patient');
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ]);

        $this->getJson(
            "/api/v1/patients/{$patient->id}/office-visits?import_source=should-never-be-persisted",
        )->assertOk();

        $audit = AgentApiAudit::query()->sole();
        $this->assertSame('agent-api.v1.clinical.index', $audit->route_name);
        $this->assertSame(200, $audit->response_status);
        $this->assertStringNotContainsString(
            'should-never-be-persisted',
            json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR),
        );

        $this->getJson("/api/v1/patients/{$patient->id}/office-visits?limit=101")
            ->assertUnprocessable();
        $this->assertSame(422, AgentApiAudit::query()->latest('id')->value('response_status'));

        $this->getJson("/api/v1/patients/{$patient->id}/office-visits/999999")
            ->assertNotFound();
        $this->assertSame(404, AgentApiAudit::query()->latest('id')->value('response_status'));
    }

    private function user(string $email): User
    {
        return User::factory()->create([
            'name' => 'Synthetic Agent User',
            'email' => $email,
            'user_role' => 'user',
        ]);
    }

    private function patient(User $owner, string $displayName, ?string $notes = null): PhrPatient
    {
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => $displayName,
            'relationship' => 'self',
            'birth_date' => '2000-01-01',
            'sex_at_birth' => 'unknown',
            'notes' => $notes,
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);

        return $patient;
    }
}

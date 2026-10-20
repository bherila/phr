<?php

namespace Tests\Feature;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * billing:read is the least-privilege scope a finance application gets: the
 * claim-level financial projection of EOBs, nothing clinical.
 */
final class AgentApiBillingReadTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    /** The EOB fields a billing-only credential may see (finance projection). */
    private const array EOB_FIELDS = [
        'id', 'public_id', 'patient_public_id', 'updated_at', 'claim_category',
        'service_start', 'service_end', 'processed_date', 'provider_name',
        'total_charges', 'total_accepted_fee', 'total_plan_payment', 'total_deductible_applied',
        'total_copay_applied', 'total_patient_responsibility', 'currency',
        'source_document_public_id', 'source_document_sha256',
    ];

    /** Clinical and identifying content that must never reach a billing-only credential. */
    private const array NEVER = [
        'Synthetic procedure narrative', 'D9999', 'Synthetic revenue', 'Synthetic Plan Name',
        'Synthetic Administrator', 'Synthetic Carrier', 'SYNTHETIC-MEMBER', 'SYNTHETIC-GROUP',
        'procedure_code', 'revenue_code', 'description', 'code_type', 'raw_text', 'parsed_data',
        'administrator', 'carrier', 'plan_name', 'external_id', 'submission_date',
        'claim_type', 'claim_number', 'import_source', 'SYNTHETIC-CLAIM-1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Storage::fake(PhrDocument::STORAGE_DISK);
    }

    public function test_a_billing_only_credential_reads_the_eob_finance_projection(): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $document = $this->document($patient, $owner, 'synthetic-eob-source');
        $eob = $this->eob($patient, $owner, $document);
        $this->line($eob, 1, '2026-07-01', null, '30.00');
        $this->line($eob, 2, '2026-07-03', '2026-07-05', '12.50');

        Passport::actingAs($owner, [AgentApiScopes::BILLING_READ]);
        $one = $this->getJson("/api/v1/patients/{$patient->id}/eobs/{$eob->id}")->assertOk()->json();
        $this->assertSame(self::EOB_FIELDS, array_keys($one['data']));
        $this->assertSame('2026-07-01', $one['data']['service_start']);
        $this->assertSame('2026-07-05', $one['data']['service_end']);
        $this->assertSame('42.50', $one['data']['total_patient_responsibility']);
        $this->assertSame('Synthetic Provider Group', $one['data']['provider_name']);
        $this->assertSame('dental_vision', $one['data']['claim_category']);
        $this->assertNull($one['data']['currency']);
        $this->assertSame($document->file_hash, $one['data']['source_document_sha256']);
        $this->assertNever($one);

        $page = $this->getJson("/api/v1/patients/{$patient->id}/eobs?updated_after=2000-01-01T00:00:00Z")->assertOk()->json();
        $this->assertSame(self::EOB_FIELDS, array_keys($page['data'][0]));
        $this->assertSame($one['data'], $page['data'][0]);
        $this->assertNever($page);
    }

    public function test_a_billing_only_credential_reaches_nothing_clinical(): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $eob = $this->eob($patient, $owner, null);
        $line = $this->line($eob, 1, '2026-07-01', null, '42.50');

        Passport::actingAs($owner, [AgentApiScopes::BILLING_READ]);
        foreach ([
            "/api/v1/patients/{$patient->id}/eobs/{$eob->id}/lines",
            "/api/v1/patients/{$patient->id}/eobs/{$eob->id}/lines/{$line->id}",
            "/api/v1/patients/{$patient->id}/records/search",
            "/api/v1/patients/{$patient->id}/timeline",
            "/api/v1/patients/{$patient->id}/changes",
            "/api/v1/patients/{$patient->id}/evidence-links?resource_type=eob&resource_id={$eob->id}",
            "/api/v1/patients/{$patient->id}/office-visits",
            "/api/v1/patients/{$patient->id}/documents",
        ] as $path) {
            $this->getJson($path)->assertForbidden();
        }
    }

    public function test_clinical_read_keeps_the_full_projection(): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $eob = $this->eob($patient, $owner, null);
        $this->line($eob, 1, '2026-07-01', null, '42.50');

        foreach ([[AgentApiScopes::CLINICAL_READ], [AgentApiScopes::CLINICAL_READ, AgentApiScopes::BILLING_READ]] as $scopes) {
            Passport::actingAs($owner, $scopes);
            $this->getJson("/api/v1/patients/{$patient->id}/eobs/{$eob->id}")->assertOk()
                ->assertJsonPath('data.plan_name', 'Synthetic Plan Name')
                ->assertJsonPath('data.lines_count', 1);
            $this->getJson("/api/v1/patients/{$patient->id}/eobs/{$eob->id}/lines")->assertOk()
                ->assertJsonPath('data.0.procedure_code', 'D9999');
        }
    }

    public function test_billing_read_still_needs_access_to_the_patient(): void
    {
        $owner = $this->createUser();
        $stranger = $this->createUser();
        $patient = $this->patient($owner);
        $eob = $this->eob($patient, $owner, null);

        Passport::actingAs($stranger, [AgentApiScopes::BILLING_READ]);
        $this->getJson("/api/v1/patients/{$patient->id}/eobs")->assertNotFound();
        $this->getJson("/api/v1/patients/{$patient->id}/eobs/{$eob->id}")->assertNotFound();
    }

    public function test_a_billing_only_credential_sees_only_patient_routing_and_public_ids(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $own = $this->patient($owner);
        $shared = $this->patient($this->createUser());
        PhrPatientUserAccess::query()->create([
            'patient_id' => $shared->id, 'user_id' => $owner->id,
            'access_level' => PhrPatientUserAccess::LEVEL_VIEWER,
            'granted_by_user_id' => $shared->owner_user_id, 'granted_at' => now(),
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $own->id, 'user_id' => $viewer->id,
            'access_level' => PhrPatientUserAccess::LEVEL_MANAGER,
            'granted_by_user_id' => $owner->id, 'granted_at' => now(),
        ]);

        Passport::actingAs($owner, [AgentApiScopes::BILLING_READ]);
        $list = $this->getJson('/api/v1/patients')->assertOk()->json();
        $this->assertSame([$own->id, $shared->id], array_column($list['data'], 'id'));
        foreach ($list['data'] as $patient) {
            $this->assertSame(['id', 'public_id'], array_keys($patient));
        }
        $one = $this->getJson("/api/v1/patients/{$shared->id}")->assertOk()->json();
        $this->assertSame(['id', 'public_id'], array_keys($one['data']));
        foreach ([$list, $one] as $response) {
            $encoded = json_encode($response, JSON_THROW_ON_ERROR);
            foreach (['2000-01-01', 'Synthetic patient notes', 'birth_date', 'sex_at_birth', 'relationship', 'notes', 'granted_at', 'display_name', 'access_level', 'Synthetic Billing Patient'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $encoded);
            }
        }
        $this->postJson('/api/v1/patients', ['display_name' => 'Synthetic New Patient'])->assertForbidden();

        Passport::actingAs($viewer, [AgentApiScopes::BILLING_READ]);
        $this->getJson("/api/v1/patients/{$own->id}")->assertOk()
            ->assertJsonPath('data.id', $own->id)->assertJsonMissingPath('data.access_level');
        $this->getJson("/api/v1/patients/{$shared->id}")->assertNotFound();

        Passport::actingAs($owner, [AgentApiScopes::PATIENTS_READ, AgentApiScopes::BILLING_READ]);
        $this->getJson("/api/v1/patients/{$shared->id}")->assertOk()
            ->assertJsonPath('data.access_level', PhrPatientUserAccess::LEVEL_VIEWER)
            ->assertJsonPath('data.access.level', PhrPatientUserAccess::LEVEL_VIEWER)
            ->assertJsonPath('data.sex_at_birth', 'unknown');
    }

    public function test_billing_read_cannot_download_even_with_another_credentials_signature(): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $source = $this->document($patient, $owner, 'synthetic-eob-source');
        $this->eob($patient, $owner, $source);
        $letter = $this->document($patient, $owner, 'synthetic-letter');

        Passport::actingAs($owner, [AgentApiScopes::DOCUMENTS_READ]);
        $accesses = [];
        foreach ([$source, $letter] as $document) {
            $access = $this->postJson("/api/v1/patients/{$patient->id}/documents/{$document->id}/download-access")->assertOk()->json();
            $this->get($access['download_url'])->assertOk();
            $accesses[] = $access;
        }

        Passport::actingAs($owner, [AgentApiScopes::BILLING_READ]);
        foreach ([$source, $letter] as $index => $document) {
            $this->postJson("/api/v1/patients/{$patient->id}/documents/{$document->id}/download-access")->assertForbidden();
            $this->get($accesses[$index]['download_url'])->assertForbidden();
        }
    }

    /** @return array<string, array{string, string}> */
    public static function claimCategories(): array
    {
        return [
            'medical' => ['medical', 'medical'],
            'pharmacy' => ['pharmacy', 'pharmacy'],
            'dental' => ['dental', 'dental_vision'],
            'vision' => ['vision', 'dental_vision'],
            'unknown' => ['unknown', 'medical'],
            'future type' => ['synthetic-future-type', 'medical'],
        ];
    }

    #[DataProvider('claimCategories')]
    public function test_claim_types_are_mapped_to_a_closed_category_set(string $claimType, string $expected): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $eob = $this->eob($patient, $owner, null);
        $eob->update(['claim_type' => $claimType]);
        Passport::actingAs($owner, [AgentApiScopes::BILLING_READ]);

        $data = $this->getJson("/api/v1/patients/{$patient->id}/eobs/{$eob->id}")->assertOk()->json('data');
        $this->assertSame(self::EOB_FIELDS, array_keys($data));
        $this->assertSame($expected, $data['claim_category']);
        $this->assertNull($data['source_document_public_id']);
        $this->assertNull($data['source_document_sha256']);
    }

    public function test_billing_filters_cannot_recover_excluded_claim_attributes(): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $this->eob($patient, $owner, null);
        Passport::actingAs($owner, [AgentApiScopes::BILLING_READ]);
        foreach (['claim_type' => 'dental', 'import_source' => 'synthetic-eob', 'source_document_id' => 1] as $key => $value) {
            $this->getJson("/api/v1/patients/{$patient->id}/eobs?".http_build_query([$key => $value]))->assertUnprocessable();
        }
    }

    public function test_document_metadata_excludes_deleted_and_other_patient_sources(): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $document = $this->document($patient, $owner, 'synthetic-source-metadata');
        $eob = $this->eob($patient, $owner, $document);
        Passport::actingAs($owner, [AgentApiScopes::BILLING_READ]);
        $url = "/api/v1/patients/{$patient->id}/eobs/{$eob->id}";
        $this->getJson($url)->assertOk()->assertJsonPath('data.source_document_sha256', $document->file_hash);

        $document->delete();
        $this->getJson($url)->assertOk()->assertJsonPath('data.source_document_public_id', null)
            ->assertJsonPath('data.source_document_sha256', null);

        $document->restore();
        $document->update(['patient_id' => $this->patient($owner)->id]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.source_document_public_id', null)
            ->assertJsonPath('data.source_document_sha256', null);
    }

    public function test_billing_date_filters_do_not_fall_back_to_excluded_dates(): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $eob = $this->eob($patient, $owner, null);
        $eob->update(['processed_date' => null]);
        $url = "/api/v1/patients/{$patient->id}/eobs?date_from=2026-07-01";

        Passport::actingAs($owner, [AgentApiScopes::BILLING_READ]);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        Passport::actingAs($owner, [AgentApiScopes::CLINICAL_READ]);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
    }

    /** @return array<string, array{string, bool}> */
    public static function sourceChanges(): array
    {
        return [
            'hash changes' => ['hash', true],
            'source soft-deleted' => ['delete', false],
            'source restored' => ['restore', true],
            'source reassigned' => ['reassign', false],
            'source permanently deleted' => ['force-delete', false],
        ];
    }

    #[DataProvider('sourceChanges')]
    public function test_source_changes_advance_the_eob_projection_window(string $change, bool $hasSource): void
    {
        $this->travelTo('2026-01-01 12:00:00');
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $document = $this->document($patient, $owner, 'synthetic-changing-source');
        $eob = $this->eob($patient, $owner, $document);
        if ($change === 'restore') {
            $document->delete();
        }
        Passport::actingAs($owner, [AgentApiScopes::BILLING_READ]);
        $url = "/api/v1/patients/{$patient->id}/eobs";
        $window = '?'.http_build_query(['updated_after' => '2026-01-02T00:00:00Z', 'updated_before' => '2026-01-04T00:00:00Z']);
        $this->getJson($url.$window)->assertOk()->assertJsonCount(0, 'data');
        $this->travelTo('2026-01-03 12:00:00');

        match ($change) {
            'hash' => $document->update(['file_hash' => hash('sha256', 'synthetic changed source')]),
            'delete' => $document->delete(),
            'restore' => $document->restore(),
            'reassign' => $document->update(['patient_id' => $this->patient($owner)->id]),
            'force-delete' => $document->forceDelete(),
        };

        $data = $this->getJson($url.$window)->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame($eob->id, $data['id']);
        $this->assertSame('2026-01-03T12:00:00+00:00', $data['updated_at']);
        $this->assertSame($hasSource ? $document->file_hash : null, $data['source_document_sha256']);
        $this->assertSame($hasSource, $data['source_document_public_id'] !== null);
        $this->getJson($url.'?updated_before=2026-01-02T00:00:00Z')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unexposed_document_edits_do_not_advance_the_eob_projection_window(): void
    {
        $this->travelTo('2026-01-01 12:00:00');
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $document = $this->document($patient, $owner, 'synthetic-title-change');
        $this->eob($patient, $owner, $document);
        $this->travelTo('2026-01-03 12:00:00');
        $document->update(['title' => 'Synthetic changed title']);
        Passport::actingAs($owner, [AgentApiScopes::BILLING_READ]);

        $this->getJson("/api/v1/patients/{$patient->id}/eobs?updated_after=2026-01-02T00:00:00Z")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_billing_patient_filters_cannot_reveal_excluded_state(): void
    {
        $owner = $this->createUser();
        $this->patient($owner);
        Passport::actingAs($owner, [AgentApiScopes::BILLING_READ]);
        foreach (['archived' => 'only', 'updated_after' => '2026-01-01', 'updated_before' => '2027-01-01'] as $key => $value) {
            $this->getJson('/api/v1/patients?'.http_build_query([$key => $value]))->assertUnprocessable();
        }

        Passport::actingAs($owner, [AgentApiScopes::PATIENTS_READ]);
        $this->getJson('/api/v1/patients?archived=only&updated_after=2026-01-01&updated_before=2027-01-01')->assertOk();
    }

    public function test_billing_read_is_a_rest_only_alternative_and_reported_as_one(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/api/v1')->assertOk()
            ->assertJsonFragment([AgentApiScopes::BILLING_READ]);
        $this->assertNotContains(
            AgentApiScopes::BILLING_READ,
            $this->getJson('/.well-known/oauth-protected-resource/api/v1/mcp')->assertOk()->json('scopes_supported'),
        );
        $this->assertSame(
            ['available' => true, 'scope' => AgentApiScopes::CLINICAL_READ, 'any_scopes' => [AgentApiScopes::CLINICAL_READ, AgentApiScopes::BILLING_READ]],
            $this->getJson('/api/v1/capabilities')->assertOk()->json('operations')['eobs.list'],
        );

        Passport::actingAs($this->createUser(), [AgentApiScopes::IDENTITY_READ, AgentApiScopes::BILLING_READ]);
        $operations = $this->getJson('/api/v1/me')->assertOk()->json('operations');
        $this->assertEqualsCanonicalizing(
            ['capabilities.get', 'identity.get', 'patients.list', 'patients.get', 'eobs.list', 'eobs.get', 'oauth.consent.revoke', 'oauth.disconnect'],
            $operations['available'],
        );
        foreach (['eobs.list', 'eobs.get'] as $available) {
            $this->assertContains($available, $operations['available']);
        }
        $withheld = array_column($operations['withheld'], null, 'operation');
        $this->assertSame(AgentApiScopes::CLINICAL_READ, $withheld['eob_lines.get']['detail']);
        $this->assertArrayHasKey('eob_lines.list', $withheld);
        $this->assertSame(AgentApiScopes::CLINICAL_READ, $withheld['eob_lines.list']['detail']);
        $this->assertSame(AgentApiScopes::DOCUMENTS_READ, $withheld['documents.download']['detail']);
        $this->assertSame(AgentApiScopes::DOCUMENTS_READ, $withheld['documents.download_access.create']['detail']);
        $this->assertSame(AgentApiScopes::CLINICAL_READ, $withheld['clinical.list']['detail']);

        Passport::actingAs($this->createUser(), [AgentApiScopes::IDENTITY_READ]);
        $withheld = array_column($this->getJson('/api/v1/me')->assertOk()->json('operations.withheld'), null, 'operation');
        $this->assertSame(AgentApiScopes::CLINICAL_READ.'|'.AgentApiScopes::BILLING_READ, $withheld['eobs.list']['detail']);
    }

    /** @param array<string, mixed> $response */
    private function assertNever(array $response): void
    {
        $encoded = json_encode($response, JSON_THROW_ON_ERROR);
        foreach (self::NEVER as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    private function patient(User $owner): PhrPatient
    {
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id, 'display_name' => 'Synthetic Billing Patient',
            'relationship' => 'self', 'birth_date' => '2000-01-01', 'sex_at_birth' => 'unknown',
            'notes' => 'Synthetic patient notes',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id,
            'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $owner->id, 'granted_at' => now(),
        ]);

        return $patient;
    }

    private function document(PhrPatient $patient, User $uploader, string $name, string $bytes = 'synthetic bytes'): PhrDocument
    {
        $path = "fixtures/{$name}.pdf";
        Storage::disk(PhrDocument::STORAGE_DISK)->put($path, $bytes);

        return PhrDocument::query()->create([
            'patient_id' => $patient->id, 'user_id' => $uploader->id, 'uploaded_by_user_id' => $uploader->id,
            'title' => 'Synthetic document', 'document_type' => 'other',
            'original_filename' => "{$name}.pdf", 'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => $path, 'mime_type' => 'application/pdf', 'byte_size' => strlen($bytes),
            'file_hash' => hash('sha256', $bytes), 'source' => 'manual_upload',
        ]);
    }

    private function eob(PhrPatient $patient, User $owner, ?PhrDocument $document): PhrEob
    {
        return PhrEob::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id,
            'source_document_id' => $document?->id, 'import_source' => 'synthetic-eob',
            'external_id' => 'synthetic-claim-key', 'claim_type' => 'dental',
            'claim_number' => 'SYNTHETIC-CLAIM-1', 'administrator' => 'Synthetic Administrator',
            'carrier' => 'Synthetic Carrier', 'plan_name' => 'Synthetic Plan Name',
            'member_id' => 'SYNTHETIC-MEMBER', 'group_number' => 'SYNTHETIC-GROUP',
            'provider_name' => 'Synthetic Provider Group',
            'submission_date' => '2026-07-06', 'processed_date' => '2026-07-10',
            'total_charges' => '200.00', 'total_accepted_fee' => '150.00',
            'total_ineligible_amount' => '5.00', 'total_deductible_applied' => '20.00',
            'total_copay_applied' => '10.00', 'total_plan_payment' => '107.50',
            'total_patient_responsibility' => '42.50',
            'raw_text' => 'Synthetic raw parser payload', 'parsed_data' => ['synthetic' => true],
        ]);
    }

    private function line(PhrEob $eob, int $number, string $start, ?string $end, string $responsibility): PhrEobLine
    {
        return PhrEobLine::query()->create([
            'patient_id' => $eob->patient_id, 'eob_id' => $eob->id, 'line_number' => $number,
            'procedure_code' => 'D9999', 'revenue_code' => 'Synthetic revenue', 'code_type' => 'CDT',
            'description' => 'Synthetic procedure narrative',
            'service_start' => $start, 'service_end' => $end,
            'total_charges' => '100.00', 'accepted_fee' => '75.00', 'deductible_applied' => '10.00',
            'copay_applied' => '5.00', 'plan_payment' => '50.00', 'patient_responsibility' => $responsibility,
            'raw_text' => 'Synthetic raw line', 'parsed_data' => ['synthetic' => true],
        ]);
    }
}

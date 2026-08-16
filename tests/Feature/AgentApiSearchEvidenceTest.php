<?php

namespace Tests\Feature;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\PhrAllergy;
use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\PhrProcedure;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AgentApiSearchEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        config(['passport.private_key' => $privateKey, 'passport.public_key' => $details['key']]);
    }

    public function test_unified_search_filters_paginates_and_uses_a_concise_projection(): void
    {
        $actor = $this->user('search-reader@example.test');
        $patient = $this->patient($actor, 'Synthetic Search Patient');
        $visit = PhrOfficeVisit::query()->create([
            'patient_id' => $patient->id, 'user_id' => $actor->id,
            'visit_started_at' => '2026-08-15 12:00:00', 'visit_type' => 'Synthetic follow-up',
            'provider_name' => 'Synthetic Clinician', 'facility_name' => 'Synthetic Clinic',
            'assessment' => 'Synthetic private assessment',
            'icd10_codes' => [['code' => 'Z00.00', 'description' => 'Synthetic code']],
            'import_source' => 'synthetic-source', 'source_document_id' => 91,
        ]);
        $allergy = PhrAllergy::query()->create([
            'patient_id' => $patient->id, 'user_id' => $actor->id,
            'substance' => 'Synthetic allergen', 'verification_status' => 'provisional',
            'import_source' => 'synthetic-source',
        ]);
        PhrAllergy::query()->create([
            'patient_id' => $patient->id, 'user_id' => $actor->id,
            'substance' => 'Synthetic second allergen', 'verification_status' => 'unconfirmed',
            'import_source' => 'synthetic-source',
        ]);

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ]);
        $filtered = $this->getJson("/api/v1/patients/{$patient->id}/records/search?provider=Synthetic%20Clinician&code=Z00.00")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.resource_type', 'office-visits')
            ->assertJsonPath('data.0.id', $visit->id)
            ->assertJsonPath('data.0.source_document_id', 91)
            ->json();
        $encoded = json_encode($filtered, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private assessment', $encoded);
        $this->assertStringNotContainsString('raw_text', $encoded);

        $this->getJson("/api/v1/patients/{$patient->id}/timeline?review_status=provisional")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.resource_type', 'allergies')
            ->assertJsonPath('data.0.id', $allergy->id);
        $this->getJson("/api/v1/patients/{$patient->id}/timeline?review_status=confirmed")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/patients/{$patient->id}/timeline?code=description")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/patients/{$patient->id}/timeline?source=synthetic")
            ->assertOk()->assertJsonCount(0, 'data');

        $first = $this->getJson("/api/v1/patients/{$patient->id}/timeline?limit=1")
            ->assertOk()->assertJsonPath('pagination.has_more', true)->json();
        $second = $this->getJson("/api/v1/patients/{$patient->id}/timeline?limit=1&cursor=".urlencode($first['pagination']['next_cursor']))
            ->assertOk()->assertJsonCount(1, 'data')->json();
        $this->assertNotSame($first['data'][0]['resource_type'].'-'.$first['data'][0]['id'], $second['data'][0]['resource_type'].'-'.$second['data'][0]['id']);
        $this->getJson("/api/v1/patients/{$patient->id}/timeline?resource_type[]=unknown")->assertUnprocessable();
        $this->getJson("/api/v1/patients/{$patient->id}/timeline?cursor=invalid")->assertUnprocessable();
    }

    public function test_eob_lines_and_links_are_patient_scoped_bounded_and_omit_parser_payloads(): void
    {
        $actor = $this->user('evidence-reader@example.test');
        $other = $this->user('evidence-other@example.test');
        $patient = $this->patient($actor, 'Synthetic Evidence Patient');
        $hiddenPatient = $this->patient($other, 'Synthetic Hidden Evidence Patient');
        $document = $this->document($patient, $actor, 'visible/synthetic.pdf');
        $visit = PhrOfficeVisit::query()->create([
            'patient_id' => $patient->id, 'user_id' => $actor->id,
            'visit_type' => 'Synthetic evidence visit', 'source_document_id' => $document->id,
        ]);
        $procedure = PhrProcedure::query()->create([
            'patient_id' => $patient->id, 'user_id' => $actor->id,
            'name' => 'Synthetic evidence procedure', 'source_document_id' => $document->id,
        ]);
        $eob = PhrEob::query()->create([
            'patient_id' => $patient->id, 'user_id' => $actor->id,
            'source_document_id' => $document->id, 'import_source' => 'synthetic-eob',
            'external_id' => 'eob:meritain:'.str_repeat('a', 64), 'claim_number' => 'SYNTHETIC-001',
            'submission_date' => '2026-08-10',
            'raw_text' => 'Synthetic raw parser payload', 'parsed_data' => ['secret' => 'synthetic'],
        ]);
        $line = PhrEobLine::query()->create([
            'patient_id' => $patient->id, 'eob_id' => $eob->id, 'line_number' => 1,
            'procedure_code' => 'D0000', 'description' => 'Synthetic line',
            'raw_text' => 'Synthetic raw line', 'parsed_data' => ['secret' => 'synthetic'],
        ]);
        $eob->officeVisits()->attach($visit->id, ['patient_id' => $patient->id]);
        $eob->procedures()->attach($procedure->id, ['patient_id' => $patient->id]);
        $hiddenEob = PhrEob::query()->create([
            'patient_id' => $hiddenPatient->id, 'user_id' => $other->id,
            'import_source' => 'synthetic-hidden', 'external_id' => 'synthetic-hidden-claim',
        ]);

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ]);
        $response = $this->getJson("/api/v1/patients/{$patient->id}/eobs/{$eob->id}")
            ->assertOk()->assertJsonPath('data.lines_count', 1)
            ->assertJsonPath('data.external_id', null)->json();
        $encoded = json_encode($response, JSON_THROW_ON_ERROR);
        foreach (['raw_text', 'parsed_data', 'member'.'_id', 'provider_tin', 'check_number', 'user_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
        $this->assertStringNotContainsString(str_repeat('a', 64), $encoded);
        $this->getJson("/api/v1/patients/{$patient->id}/eobs?date_from=2026-08-10&date_to=2026-08-10")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eob->id);
        $this->getJson("/api/v1/patients/{$patient->id}/eobs/{$eob->id}/lines/{$line->id}")
            ->assertOk()->assertJsonPath('data.procedure_code', 'D0000');
        $links = $this->getJson("/api/v1/patients/{$patient->id}/evidence-links?resource_type=eob&resource_id={$eob->id}&limit=2")
            ->assertOk()->assertJsonPath('pagination.has_more', true)->json();
        $this->assertIsString($links['pagination']['next_cursor']);
        $this->getJson("/api/v1/patients/{$patient->id}/evidence-links?resource_type=document&resource_id={$document->id}")
            ->assertForbidden();
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::DOCUMENTS_READ]);
        $this->getJson("/api/v1/patients/{$patient->id}/evidence-links?resource_type=document&resource_id={$document->id}")
            ->assertOk();
        $this->getJson("/api/v1/patients/{$patient->id}/eobs/{$hiddenEob->id}")->assertNotFound();
        $this->getJson("/api/v1/patients/{$hiddenPatient->id}/eobs")->assertNotFound();
    }

    public function test_documents_require_their_own_scope_and_download_needs_bearer_plus_short_lived_signature(): void
    {
        Storage::fake(PhrDocument::STORAGE_DISK);
        $actor = $this->user('document-reader@example.test');
        $other = $this->user('document-other@example.test');
        $patient = $this->patient($actor, 'Synthetic Document Patient');
        $hiddenPatient = $this->patient($other, 'Synthetic Hidden Document Patient');
        $document = $this->document($patient, $actor, 'patient/'.$patient->id.'/synthetic.pdf');
        $documentHash = str_repeat('c', 64);
        $document->update([
            'tags' => ['Cardiology'],
            'external_id' => 'eob:meritain:'.$documentHash,
        ]);
        Storage::disk(PhrDocument::STORAGE_DISK)->put((string) $document->storage_path, 'synthetic-file-bytes');
        $hidden = $this->document($hiddenPatient, $other, 'patient/'.$hiddenPatient->id.'/hidden.pdf');

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ]);
        $this->getJson("/api/v1/patients/{$patient->id}/documents")->assertForbidden();
        Passport::actingAs($actor, [AgentApiScopes::DOCUMENTS_READ]);
        $metadata = $this->getJson("/api/v1/patients/{$patient->id}/documents/{$document->id}")
            ->assertOk()->assertJsonPath('data.has_file', true)->json();
        $encoded = json_encode($metadata, JSON_THROW_ON_ERROR);
        foreach (['storage_path', 'storage_disk', 'file_hash', 'extracted_text', 'user_id', 'genai_job_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
        $this->assertNull($metadata['data']['external_id']);
        $this->assertStringNotContainsString($documentHash, $encoded);
        $this->getJson("/api/v1/patients/{$patient->id}/documents?tag=cardiology")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $document->id);
        $access = $this->postJson("/api/v1/patients/{$patient->id}/documents/{$document->id}/download-access")
            ->assertOk()->json();
        $this->assertTrue(URL::hasValidSignature(request()->create($access['download_url'])));
        Auth::forgetGuards();
        $this->withToken('')->get($access['download_url'])->assertUnauthorized();
        Passport::actingAs($actor, [AgentApiScopes::DOCUMENTS_READ]);
        $this->get($access['download_url'])
            ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="synthetic.pdf"')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $unsigned = "/api/v1/patients/{$patient->id}/documents/{$document->id}/file";
        $this->get($unsigned)->assertForbidden();
        $this->getJson("/api/v1/patients/{$patient->id}/documents/{$hidden->id}")->assertNotFound();
    }

    public function test_document_update_windows_include_linked_processing_job_transitions(): void
    {
        $actor = $this->user('document-window-reader@example.test');
        $patient = $this->patient($actor, 'Synthetic Document Window Patient');
        $this->travelTo(Carbon::parse('2026-08-16 10:00:00'));
        $job = GenAiImportJob::query()->create([
            'user_id' => $actor->id, 'job_type' => 'phr_document',
            'file_hash' => str_repeat('b', 64), 'original_filename' => 'synthetic-window.pdf',
            's3_path' => 'synthetic/window.pdf', 'file_size_bytes' => 10, 'status' => 'pending',
        ]);
        $document = $this->document($patient, $actor, 'patient/'.$patient->id.'/window.pdf');
        $document->update(['genai_job_id' => $job->id]);

        $this->travelTo(Carbon::parse('2026-08-16 11:00:00'));
        $job->update(['status' => 'processing']);
        Passport::actingAs($actor, [AgentApiScopes::DOCUMENTS_READ]);
        $this->getJson("/api/v1/patients/{$patient->id}/documents?updated_after=2026-08-16T10:30:00Z")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $document->id)
            ->assertJsonPath('data.0.processing_state', 'processing');
        $this->getJson("/api/v1/patients/{$patient->id}/documents?updated_before=2026-08-16T10:30:00Z")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->travelBack();
    }

    private function user(string $email): User
    {
        return User::factory()->create(['name' => 'Synthetic Agent User', 'email' => $email, 'user_role' => 'user']);
    }

    private function patient(User $owner, string $name): PhrPatient
    {
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id, 'display_name' => $name, 'relationship' => 'self',
            'birth_date' => '2000-01-01', 'sex_at_birth' => 'unknown',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id,
            'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $owner->id, 'granted_at' => now(),
        ]);

        return $patient;
    }

    private function document(PhrPatient $patient, User $owner, string $path): PhrDocument
    {
        return PhrDocument::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id,
            'title' => 'Synthetic document', 'document_type' => 'other',
            'original_filename' => 'synthetic.pdf', 'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => $path, 'mime_type' => 'application/pdf', 'byte_size' => 20,
            'file_hash' => str_repeat('a', 64), 'extracted_text' => 'Synthetic extracted private text',
            'source' => 'manual_upload', 'tags' => ['synthetic'],
        ]);
    }
}

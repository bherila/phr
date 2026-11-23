<?php

namespace Tests\Feature\PHR;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\PhrDocument;
use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Export\PhrCcdaExporter;
use App\Services\PHR\Export\PhrExportDataService;
use App\Services\PHR\Export\PhrFhirExporter;
use App\Services\PHR\Import\PhrDocumentImporter;
use App\Services\PHR\Import\PhrFhirImporter;
use App\Services\PHR\Import\PhrStructuredDataImporter;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * Documents carry a review status (phr#408): what a person uploads is confirmed, what a
 * machine writes waits for that person, and only a confirmed document still standing
 * leaves PHR in a FHIR or C-CDA export. Synthetic fixture only ("Pat Example").
 */
final class PhrDocumentReviewTest extends TestCase
{
    use ConfiguresPassportKeys;

    private User $owner;

    private PhrPatient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake(PhrDocument::STORAGE_DISK);
        $this->owner = $this->createUser();
        $this->patient = PhrPatient::query()->create([
            'owner_user_id' => $this->owner->id, 'display_name' => 'Pat Example', 'relationship' => 'self', 'birth_date' => '1950-06-15',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $this->owner->id, 'granted_at' => now(),
        ]);
    }

    public function test_existing_documents_backfill_as_confirmed_and_console_writes_stay_confirmed(): void
    {
        $legacy = $this->document('Synthetic legacy consent', []);
        $this->assertSame(PhrReviewStatus::CONFIRMED, $legacy->refresh()->review_status, 'the column default backfills every existing row');

        // What a console import stores from a local file (the Meritain and Delta Dental
        // importers, phr:import:pdf) is an operator's own upload.
        $path = tempnam(sys_get_temp_dir(), 'phr-review-');
        $this->assertIsString($path);
        file_put_contents($path, '%PDF-1.4 synthetic EOB');
        $stored = app(PhrDocumentImporter::class)->storeLocalDocument($this->patient, (int) $this->owner->id, $path, ['source' => 'manual_upload', 'title' => 'Synthetic EOB']);
        @unlink($path);
        $this->assertSame(PhrReviewStatus::CONFIRMED, $stored->refresh()->review_status);
    }

    public function test_a_persons_own_upload_is_confirmed_and_an_agents_waits_for_review(): void
    {
        $this->actingAs($this->owner)->post("/api/phr/patients/{$this->patient->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent('consent.txt', 'Synthetic consent for treatment.'),
            'title' => 'Consent for treatment',
            'document_type' => 'consent',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('document.review_status', PhrReviewStatus::CONFIRMED);

        $client = $this->agent([AgentApiScopes::MCP_USE, AgentApiScopes::DOCUMENTS_READ, AgentApiScopes::DOCUMENTS_WRITE, AgentApiScopes::CLINICAL_READ]);
        $agent = $this->post("/api/v1/patients/{$this->patient->id}/documents", [
            'external_id' => 'synthetic-progress-note',
            'file' => UploadedFile::fake()->createWithContent('note.md', "# Daily Progress Note\n\nNephrology Follow-Up."),
            'title' => 'Daily Progress Note',
            'document_type' => 'office_visit_note',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.review_status', PhrReviewStatus::PENDING)->json('data');
        $this->assertSame('agent-client:'.$client->id, PhrDocument::query()->findOrFail($agent['id'])->import_source);

        // documents.list filters by it, and records.search now applies the filter to documents.
        $this->getJson("/api/v1/patients/{$this->patient->id}/documents?review_status=pending_review")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $agent['id']);
        $this->getJson("/api/v1/patients/{$this->patient->id}/documents?review_status=confirmed")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Consent for treatment');
        $this->getJson("/api/v1/patients/{$this->patient->id}/documents?review_status=approved")->assertUnprocessable();
        $found = $this->getJson("/api/v1/patients/{$this->patient->id}/records/search?resource_type[]=documents&review_status=pending_review")->assertOk()->json('data');
        $this->assertSame([$agent['id']], array_column($found, 'id'));
        $this->assertSame(PhrReviewStatus::PENDING, $found[0]['review_status']);
    }

    public function test_an_agent_edit_reopens_review_and_an_exact_repeat_keeps_it(): void
    {
        $this->agent([AgentApiScopes::DOCUMENTS_READ, AgentApiScopes::DOCUMENTS_WRITE]);
        $document = $this->document('Daily Progress Note', ['source' => 'agent_upload', 'review_status' => PhrReviewStatus::CONFIRMED]);
        $url = "/api/v1/patients/{$this->patient->id}/documents/{$document->id}";
        $version = $this->getJson($url)->assertOk()->assertJsonPath('data.review_status', PhrReviewStatus::CONFIRMED)->json('data.version');

        $same = $this->patchJson($url, ['expected_version' => $version, 'data' => ['title' => 'Daily Progress Note']])->assertOk()
            ->assertJsonPath('outcome', 'unchanged')->assertJsonPath('data.review_status', PhrReviewStatus::CONFIRMED)->json('version');
        $this->assertSame($version, $same);

        $this->patchJson($url, ['expected_version' => $version, 'data' => ['summary' => 'Nephrology follow-up.']])->assertOk()
            ->assertJsonPath('outcome', 'updated')->assertJsonPath('data.review_status', PhrReviewStatus::PENDING);
        $this->assertSame(PhrReviewStatus::PENDING, $document->refresh()->review_status);
    }

    public function test_a_person_edits_metadata_without_touching_review(): void
    {
        $document = $this->document('Synthetic EMS run report', ['source' => 'agent_upload', 'review_status' => PhrReviewStatus::PENDING]);
        $this->actingAs($this->owner)->patchJson("/api/phr/patients/{$this->patient->id}/documents/{$document->id}", ['title' => 'EMS run report'])->assertOk()
            ->assertJsonPath('document.review_status', PhrReviewStatus::PENDING);
    }

    public function test_pending_documents_wait_on_the_review_page_and_are_approved_or_rejected_there(): void
    {
        $note = $this->document('Daily Progress Note', ['source' => 'agent_upload', 'review_status' => PhrReviewStatus::PENDING, 'document_type' => 'office_visit_note', 'observed_at' => '2026-02-11 09:30:00', 'import_source' => 'agent-client:0199f2b7-0000-7000-8000-000000000001']);
        $estimate = $this->document('Cost estimate', ['source' => 'agent_upload', 'review_status' => PhrReviewStatus::PENDING]);
        $this->document('Medication education leaflet', []);

        $this->actingAs($this->owner)->getJson("/api/phr/patients/{$this->patient->id}/review/summary")->assertOk()
            ->assertJsonPath('summary.sections.documents.pending', 2)
            ->assertJsonPath('summary.sections.documents.records', 3)
            ->assertJsonPath('summary.total', 2);
        $items = collect($this->actingAs($this->owner)->getJson("/api/phr/patients/{$this->patient->id}/review")->assertOk()->json('groups'))
            ->flatMap(static fn (array $group): array => $group['items'])->keyBy('id');
        $this->assertSame(['documents', 'documents'], [$items[$note->id]['section'], $items[$note->id]['review_segment']]);
        $this->assertSame('Daily Progress Note', $items[$note->id]['title']);
        $this->assertStringContainsString('Office Visit Note', (string) $items[$note->id]['detail']);

        // The decision is conditional on the version listed: a document changed since is refused.
        $review = "/api/phr/patients/{$this->patient->id}/documents/{$note->id}/review";
        $listed = $items[$note->id]['version'];
        $note->update(['summary' => 'Changed after the page loaded']);
        $this->actingAs($this->owner)->patchJson($review, ['review_status' => PhrReviewStatus::CONFIRMED, 'version' => $listed])->assertConflict();
        $this->assertSame(PhrReviewStatus::PENDING, $note->refresh()->review_status);
        $this->actingAs($this->owner)->patchJson($review, ['review_status' => PhrReviewStatus::PENDING])->assertUnprocessable();

        $this->actingAs($this->owner)->patchJson($review, ['review_status' => PhrReviewStatus::CONFIRMED])->assertOk()
            ->assertJsonPath('document.review_status', PhrReviewStatus::CONFIRMED);
        $this->actingAs($this->owner)->patchJson("/api/phr/patients/{$this->patient->id}/documents/{$estimate->id}/review", ['review_status' => PhrReviewStatus::REJECTED])->assertOk()
            ->assertJsonPath('document.review_status', PhrReviewStatus::REJECTED);

        $this->actingAs($this->owner)->getJson("/api/phr/patients/{$this->patient->id}/review/summary")->assertOk()
            ->assertJsonPath('summary.sections.documents.pending', 0)
            ->assertJsonPath('summary.total', 0);
        // A rejected document leaves the working list, as a rejected record does, until asked for.
        $listedTitles = array_column($this->actingAs($this->owner)->getJson("/api/phr/patients/{$this->patient->id}/documents")->assertOk()->json('documents'), 'title');
        $this->assertEqualsCanonicalizing(['Daily Progress Note', 'Medication education leaflet'], $listedTitles);
        $this->actingAs($this->owner)->getJson("/api/phr/patients/{$this->patient->id}/documents?include_rejected=1")->assertOk()->assertJsonCount(3, 'documents');

        // Only someone who may write to the patient decides.
        $viewer = $this->createUser();
        PhrPatientUserAccess::query()->create(['patient_id' => $this->patient->id, 'user_id' => $viewer->id, 'access_level' => PhrPatientUserAccess::LEVEL_VIEWER, 'granted_by_user_id' => $this->owner->id, 'granted_at' => now()]);
        $this->actingAs($viewer)->patchJson("/api/phr/patients/{$this->patient->id}/documents/{$estimate->id}/review", ['review_status' => PhrReviewStatus::CONFIRMED])->assertForbidden();
    }

    public function test_only_confirmed_documents_still_standing_are_exported_and_nothing_points_at_one_that_is_not(): void
    {
        $confirmed = $this->document('Consent for treatment', ['document_type' => 'consent']);
        $pending = $this->document('POLST agent draft', ['source' => 'agent_upload', 'review_status' => PhrReviewStatus::PENDING]);
        $rejected = $this->document('Release of information draft', ['source' => 'agent_upload', 'review_status' => PhrReviewStatus::REJECTED]);
        $retracted = $this->document('EMS run report', ['source' => 'fhir_import']);
        $retracted->forceFill(['retracted_at' => now()])->save();
        $citesRetracted = $this->lab('Potassium', $retracted);
        $citesPending = $this->lab('Creatinine', $pending);
        $citesConfirmed = $this->lab('Sodium', $confirmed);

        $data = app(PhrExportDataService::class)->load($this->patient);
        $fhir = app(PhrFhirExporter::class)->bundleJson($data);
        $ccda = app(PhrCcdaExporter::class)->documentXml($data);

        $references = collect(json_decode($fhir, true, flags: JSON_THROW_ON_ERROR)['entry'])->pluck('resource')->where('resourceType', 'DocumentReference');
        $this->assertSame(['phr-document-'.$confirmed->id], $references->pluck('id')->values()->all());
        $documentsSection = $this->ccdaSectionText($ccda, 'Documents');
        $this->assertStringContainsString('Consent for treatment', $documentsSection);
        foreach ([$pending, $rejected, $retracted] as $excluded) {
            $this->assertStringNotContainsString((string) $excluded->title, $fhir);
            $this->assertStringNotContainsString((string) $excluded->title, $ccda);
            $this->assertStringNotContainsString('phr-document-'.$excluded->id.'"', $fhir);
        }

        // The labs that cite them are confirmed and still exported, without a dangling reference.
        foreach (['Potassium', 'Creatinine', 'Sodium'] as $analyte) {
            $this->assertStringContainsString($analyte, $fhir);
            $this->assertStringContainsString($analyte, $ccda);
        }
        $labs = $data['lab_results']->keyBy('id');
        $this->assertNull($labs[$citesRetracted->id]->source_document_id);
        $this->assertNull($labs[$citesPending->id]->source_document_id);
        $this->assertSame($confirmed->id, $labs[$citesConfirmed->id]->source_document_id);
        // Only the export omits the link: the stored record keeps it.
        $this->assertSame($retracted->id, $citesRetracted->refresh()->source_document_id);

        // Once a person approves the pending one, it is exported too.
        $pending->update(['review_status' => PhrReviewStatus::CONFIRMED]);
        $data = app(PhrExportDataService::class)->load($this->patient);
        $this->assertStringContainsString('POLST agent draft', $this->ccdaSectionText(app(PhrCcdaExporter::class)->documentXml($data), 'Documents'));
        $this->assertStringContainsString('phr-document-'.$pending->id, app(PhrFhirExporter::class)->bundleJson($data));
        $this->assertSame($pending->id, $data['lab_results']->keyBy('id')[$citesPending->id]->source_document_id);
    }

    public function test_a_document_an_agent_wrote_is_never_exported_as_clinician_authenticated(): void
    {
        $this->document('Daily Progress Note', ['source' => 'agent_upload', 'document_type' => 'office_visit_note', 'summary' => 'Signed by a nephrology fellow, per the agent.']);
        $data = app(PhrExportDataService::class)->load($this->patient);

        $reference = collect(json_decode(app(PhrFhirExporter::class)->bundleJson($data), true, flags: JSON_THROW_ON_ERROR)['entry'])
            ->pluck('resource')->firstWhere('resourceType', 'DocumentReference');
        $this->assertIsArray($reference);
        foreach (['authenticator', 'docStatus', 'author', 'custodian'] as $claim) {
            $this->assertArrayNotHasKey($claim, $reference);
        }
        $xpath = $this->ccdaXPath(app(PhrCcdaExporter::class)->documentXml($data));
        $this->assertSame(0, $xpath->query('//c:legalAuthenticator | //c:authenticator')->length);
    }

    public function test_a_fhir_document_reference_waits_for_review_and_an_unchanged_reimport_keeps_its_confirmation(): void
    {
        $bundle = static fn (string $description): array => ['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [['resource' => [
            'resourceType' => 'DocumentReference', 'id' => 'synthetic-polst',
            'description' => $description, 'type' => ['text' => 'consent'],
            'content' => [['attachment' => ['contentType' => 'application/pdf', 'title' => 'polst.pdf']]],
        ]]]];
        $import = fn (string $description) => app(PhrFhirImporter::class)->importBundle($bundle($description), $this->patient, (int) $this->owner->id);

        $import('POLST');
        $document = PhrDocument::query()->where('patient_id', $this->patient->id)->sole();
        $this->assertSame(PhrReviewStatus::PENDING, $document->review_status);

        $document->update(['review_status' => PhrReviewStatus::CONFIRMED]);
        $import('POLST');
        $this->assertSame(PhrReviewStatus::CONFIRMED, $document->refresh()->review_status, 'a re-import that changes nothing keeps the confirmation');

        $import('POLST, revised');
        $this->assertSame(PhrReviewStatus::PENDING, $document->refresh()->review_status, 'a re-import that changes the document reopens its review');
        $this->assertSame(1, PhrDocument::query()->where('patient_id', $this->patient->id)->count());
    }

    public function test_a_document_an_ai_read_created_waits_for_review_and_an_accepted_ai_update_keeps_the_review(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('genai-import/synthetic.pdf', '%PDF-1.4 synthetic genai');
        $job = GenAiImportJob::query()->create([
            'user_id' => $this->owner->id, 'job_type' => 'phr_document', 'file_hash' => hash('sha256', 'synthetic genai'),
            'original_filename' => 'synthetic.pdf', 's3_path' => 'genai-import/synthetic.pdf', 'mime_type' => 'application/pdf',
            'file_size_bytes' => 24, 'context_json' => json_encode(['patient_id' => $this->patient->id], JSON_THROW_ON_ERROR), 'status' => 'parsed',
        ]);
        $created = app(PhrStructuredDataImporter::class)->storeGenAiDocument($this->patient, (int) $this->owner->id, $job, ['title' => 'Wound photo note']);
        $this->assertSame(PhrReviewStatus::PENDING, $created->refresh()->review_status);

        // The person who accepts an AI proposal for their own upload reviewed what it changes,
        // as they did the records it brings, so the upload keeps its review.
        $upload = $this->document('Medication education leaflet', []);
        app(PhrStructuredDataImporter::class)->updateDocumentFromGenAiResult($upload, $job, ['summary' => 'Synthetic leaflet summary.']);
        $this->assertSame(PhrReviewStatus::CONFIRMED, $upload->refresh()->review_status);
    }

    /** @param list<string> $scopes */
    private function agent(array $scopes): Client
    {
        $this->configurePassportKeys();
        $client = Client::query()->create([
            'name' => 'Synthetic Review Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        Passport::actingAs($this->owner, $scopes, 'api', $client);

        return $client;
    }

    /** @param array<string, mixed> $attributes */
    private function document(string $title, array $attributes): PhrDocument
    {
        return PhrDocument::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'uploaded_by_user_id' => $this->owner->id,
            'title' => $title, 'document_type' => 'other', 'storage_disk' => PhrDocument::STORAGE_DISK, 'byte_size' => 0,
            'source' => 'manual_upload', ...$attributes,
        ]);
    }

    private function lab(string $analyte, PhrDocument $source): PhrLabResult
    {
        return PhrLabResult::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'test_name' => 'Basic metabolic panel', 'analyte' => $analyte,
            'value' => '1', 'review_status' => PhrReviewStatus::CONFIRMED, 'source_document_id' => $source->id, 'result_datetime' => '2026-02-11 06:00:00',
        ]);
    }

    private function ccdaXPath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml));
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('c', 'urn:hl7-org:v3');

        return $xpath;
    }

    private function ccdaSectionText(string $xml, string $title): string
    {
        $sections = $this->ccdaXPath($xml)->query("//c:section[c:title='{$title}']");
        $this->assertNotFalse($sections);
        $this->assertSame(1, $sections->length, "the CCD has one {$title} section");

        return (string) $sections->item(0)?->textContent;
    }
}

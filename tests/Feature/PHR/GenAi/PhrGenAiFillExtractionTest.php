<?php

namespace Tests\Feature\PHR\GenAi;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Models\GenAiImportResult;
use App\GenAiProcessor\Services\PhrGenAiRequestPreparationService;
use App\GenAiProcessor\Services\Prompts\Phr\PhrPromptTemplate;
use App\Models\PhrDocument;
use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakesOpenFda;
use Tests\TestCase;

/** AI extraction (Inbox and server-side AI) produces prescription fills through the fills write path (#268 part A). */
final class PhrGenAiFillExtractionTest extends TestCase
{
    use FakesOpenFda;

    private User $owner;

    private PhrPatient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeOpenFda();
        $this->owner = $this->createUser();
        $this->patient = PhrPatient::create(['owner_user_id' => $this->owner->id, 'display_name' => 'Jane Doe', 'relationship' => 'self']);
        PhrPatientUserAccess::query()->create(['patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $this->owner->id, 'granted_at' => now()]);
    }

    public function test_a_receipt_with_several_fills_writes_one_pending_fill_per_line(): void
    {
        $document = $this->document('receipt.pdf', 'application/pdf');
        $this->accept($document, [
            $this->fill(['ndc' => '58151-0155-77', 'rx_number' => '1111111', 'source_locator' => 'line 1', 'paid_dollars' => 10.5, 'retail_dollars' => 189.99]),
            $this->fill(['ndc' => '60505-0134-0', 'rx_number' => '2222222', 'source_locator' => 'line 2']),
            $this->fill(['ndc' => '68180-518-01', 'rx_number' => null, 'source_locator' => 'line 3', 'drug_as_printed' => 'THIRD DRUG']),
        ]);

        $fills = PhrMedicationFill::query()->where('patient_id', $this->patient->id)->orderBy('id')->get();
        $this->assertCount(3, $fills);
        foreach ($fills as $fill) {
            $this->assertSame(PhrReviewStatus::PENDING, $fill->review_status);
            $this->assertSame('genai', $fill->import_source);
            $this->assertSame($document->id, $fill->source_document_id);
            $this->assertSame('receipt_pdf', $fill->source_kind);
            $this->assertSame(PhrMedicationFill::NDC_RESOLVED, $fill->ndc_status);
            $this->assertNotNull($fill->medication_id, 'the server links or creates the medication');
        }
        $this->assertSame([1050, 18999], [$fills[0]->paid_cents, $fills[0]->retail_cents], 'dollars become cents');
        $this->assertCount(3, $fills->pluck('external_id')->unique());
    }

    public function test_a_label_photo_with_one_fill_is_a_label_photo_fill(): void
    {
        $this->accept($this->document('label.jpg', 'image/jpeg'), [$this->fill(['ndc' => '58151-0155-77', 'rx_number' => '3333333'])]);

        $fill = PhrMedicationFill::query()->sole();
        $this->assertSame('label_photo', $fill->source_kind);
        $this->assertSame('Example Pharmacy', $fill->pharmacy_name);
        $this->assertSame('90', rtrim(rtrim((string) $fill->quantity, '0'), '.'));
    }

    public function test_a_mismatched_patient_name_keeps_the_fill_on_the_document_patient_and_flags_it(): void
    {
        $this->accept($this->document('receipt.pdf', 'application/pdf'), [
            $this->fill(['ndc' => '58151-0155-77', 'rx_number' => '1', 'patient_name_as_printed' => 'DOE, JANE']),
            $this->fill(['ndc' => '60505-0134-0', 'rx_number' => '2', 'patient_name_as_printed' => 'John Smith']),
        ])->assertJsonFragment(['warnings' => ['A prescription fill is printed for "John Smith", not Jane Doe; it was kept on this patient and flagged for review.']]);

        $this->assertSame(2, PhrMedicationFill::query()->where('patient_id', $this->patient->id)->count());
        $items = collect($this->actingAs($this->owner)->getJson("/api/phr/patients/{$this->patient->id}/review")->assertOk()->json('groups'))
            ->flatMap(fn (array $group): array => $group['items'])
            ->where('section', 'medication-fills')->keyBy('title');
        $this->assertCount(2, $items);
        $flagged = $items->filter(fn (array $item): bool => $item['flags'] !== []);
        $this->assertCount(1, $flagged);
        $this->assertSame('patient_name_mismatch', $flagged->first()['flags'][0]['code']);
        $this->assertStringContainsString('John Smith', $flagged->first()['flags'][0]['message']);
    }

    public function test_medications_describing_fill_lines_are_dropped_and_others_kept(): void
    {
        $document = $this->document('receipt.pdf', 'application/pdf');
        $this->accept($document, [$this->fill(['ndc' => '58151-0155-77', 'rx_number' => '1', 'drug_as_printed' => 'LIPITOR 10MG TAB'])], [
            ['record_key' => 'm1', 'name' => 'Lipitor 10mg tab'],
            ['record_key' => 'm2', 'name' => 'Something else', 'raw_text' => 'NDC 58151-0155-77 on the receipt'],
            ['record_key' => 'm3', 'name' => 'Unrelated vitamin'],
        ]);

        $fill = PhrMedicationFill::query()->sole();
        $names = PhrMedication::query()->where('patient_id', $this->patient->id)->pluck('name')->all();
        $this->assertContains('Unrelated vitamin', $names);
        $this->assertNotContains('Lipitor 10mg tab', $names);
        $this->assertNotContains('Something else', $names);
        $this->assertSame(1, PhrMedication::query()->where('patient_id', $this->patient->id)->where('id', $fill->medication_id)->count());
        $this->assertSame(2, PhrMedication::query()->where('patient_id', $this->patient->id)->count());
    }

    public function test_reprocessing_the_same_document_does_not_duplicate_fills_or_medications(): void
    {
        $document = $this->document('receipt.pdf', 'application/pdf');
        $fills = [
            $this->fill(['ndc' => '58151-0155-77', 'rx_number' => null, 'source_locator' => 'line 1']),
            $this->fill(['ndc' => '60505-0134-0', 'rx_number' => null, 'source_locator' => 'line 2']),
        ];
        $this->accept($document, $fills);
        $medications = PhrMedication::query()->count();
        $this->accept($document, $fills)->assertJsonPath('import.created', 0)->assertJsonPath('import.unchanged', 2);

        $this->assertSame(2, PhrMedicationFill::query()->count());
        $this->assertSame($medications, PhrMedication::query()->count());
    }

    public function test_an_unresolved_ndc_is_kept_with_the_drug_as_printed(): void
    {
        $this->accept($this->document('label.jpg', 'image/jpeg'), [
            $this->fill(['ndc' => '99999-9999-99', 'rx_number' => '4', 'drug_as_printed' => 'MYSTERY CAPSULE 5MG']),
            $this->fill(['ndc' => null, 'drug_as_printed' => null, 'rx_number' => '5']),
        ]);

        $fill = PhrMedicationFill::query()->sole();
        $this->assertSame(PhrMedicationFill::NDC_UNRESOLVED, $fill->ndc_status);
        $this->assertSame('MYSTERY CAPSULE 5MG', $fill->drug_as_printed);
        $this->assertSame('99999-9999-99', $fill->ndc_as_captured);
        $this->assertNotNull($fill->medication_id);
    }

    public function test_the_request_schema_offers_optional_printed_only_fills(): void
    {
        $job = $this->job($this->document('receipt.pdf', 'application/pdf'));
        $schema = app(PhrGenAiRequestPreparationService::class)->prepare($job)->submissionSchema;

        $records = $schema['properties']['records'];
        $this->assertSame('phr_pdf_bundle.v1', $schema['properties']['schema_version']['const']);
        $this->assertArrayHasKey('medication_fills', $records['properties']);
        $this->assertNotContains('medication_fills', $records['required']);
        $this->assertContains('medications', $records['required']);
        $fill = $records['properties']['medication_fills']['items'];
        $this->assertSame(['filled_on', 'quantity'], $fill['required']);
        $this->assertFalse($fill['additionalProperties']);
        $this->assertSame([
            'days_supply', 'directions', 'drug_as_printed', 'filled_on', 'ndc', 'paid_dollars', 'patient_name_as_printed',
            'pharmacy_location', 'pharmacy_name', 'prescriber_name', 'quantity', 'quantity_unit', 'raw_text', 'record_key',
            'refills_remaining', 'retail_dollars', 'rx_number', 'source_locator',
        ], collect(array_keys($fill['properties']))->sort()->values()->all());
        foreach (['brand_name', 'substance_names', 'strengths', 'dosage_form', 'labeler_name', 'rxcui', 'ndc_10', 'ndc_11', 'fda_snapshot', 'paid_cents'] as $fdaField) {
            $this->assertArrayNotHasKey($fdaField, $fill['properties']);
        }
        $this->assertStringContainsString('"medication_fills"', (new PhrPromptTemplate('phr_document'))->build([]));
    }

    public function test_an_old_format_result_without_fills_still_maps(): void
    {
        $document = $this->document('visit.pdf', 'application/pdf');
        $this->accept($document, null, [['record_key' => 'm1', 'name' => 'Fluticasone']])
            ->assertJsonPath('import.created', 1);

        $this->assertSame(0, PhrMedicationFill::query()->count());
        $this->assertSame('Fluticasone', PhrMedication::query()->sole()->name);
    }

    /**
     * @param  list<array<string, mixed>>|null  $fills
     * @param  list<array<string, mixed>>  $medications
     */
    private function accept(PhrDocument $document, ?array $fills, array $medications = []): TestResponse
    {
        $job = $this->job($document);
        $records = ['medications' => $medications];
        if ($fills !== null) {
            $records['medication_fills'] = $fills;
        }
        $result = GenAiImportResult::create([
            'job_id' => $job->id,
            'result_index' => 0,
            'status' => 'pending_review',
            'result_json' => json_encode([
                'schema_version' => 'phr_pdf_bundle.v1',
                'source_document' => ['title' => 'Pharmacy document', 'document_type' => 'other'],
                'records' => $records,
            ], JSON_THROW_ON_ERROR),
        ]);

        return $this->actingAs($this->owner)
            ->postJson("/api/phr/genai/jobs/{$job->id}/results/{$result->id}/accept")
            ->assertOk();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fill(array $overrides = []): array
    {
        return [
            'patient_name_as_printed' => 'Jane Doe',
            'ndc' => '58151-0155-77',
            'drug_as_printed' => 'ATORVASTATIN 10MG TAB',
            'filled_on' => '2026-10-28',
            'quantity' => 90,
            'quantity_unit' => 'tablet',
            'days_supply' => 90,
            'rx_number' => '1234567',
            'refills_remaining' => 3,
            'directions' => 'Take 1 tablet daily',
            'prescriber_name' => 'Dr. A. Smith',
            'paid_dollars' => 10.0,
            'retail_dollars' => 20.0,
            'pharmacy_name' => 'Example Pharmacy',
            'pharmacy_location' => '#1234, Seattle WA',
            'source_locator' => 'page 1',
            ...$overrides,
        ];
    }

    private function job(PhrDocument $document): GenAiImportJob
    {
        return GenAiImportJob::create([
            'user_id' => $this->owner->id,
            'job_type' => 'phr_document',
            'file_hash' => hash('sha256', 'job'.microtime().random_int(0, PHP_INT_MAX)),
            'original_filename' => $document->original_filename,
            's3_path' => 'genai-import/source',
            'mime_type' => $document->mime_type,
            'file_size_bytes' => 100,
            'context_json' => json_encode(['patient_id' => $this->patient->id, 'document_id' => $document->id], JSON_THROW_ON_ERROR),
            'status' => 'parsed',
        ]);
    }

    private function document(string $filename, string $mime): PhrDocument
    {
        return PhrDocument::create([
            'patient_id' => $this->patient->id,
            'user_id' => $this->owner->id,
            'uploaded_by_user_id' => $this->owner->id,
            'title' => 'Source document',
            'document_type' => 'other',
            'original_filename' => $filename,
            'storage_disk' => 'phr_documents',
            'storage_path' => 'phr/documents/'.$filename,
            'mime_type' => $mime,
            'byte_size' => 100,
            'file_hash' => hash('sha256', $filename.microtime()),
            'source' => 'manual_upload',
        ]);
    }
}

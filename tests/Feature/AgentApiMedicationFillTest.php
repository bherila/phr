<?php

namespace Tests\Feature;

use App\Models\PhrDocument;
use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\Support\FakesOpenFda;
use Tests\TestCase;

/** Prescription fills over the versioned REST API: NDC resolution, medication link, provenance and merge. */
final class AgentApiMedicationFillTest extends TestCase
{
    use ConfiguresPassportKeys;
    use FakesOpenFda;
    use RefreshDatabase;

    private User $actor;

    private PhrPatient $patient;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePassportKeys();
        Cache::flush();
        $this->actor = User::factory()->create(['user_role' => 'user']);
        $this->patient = $this->makePatient($this->actor, 'Synthetic Fill Patient', '1980-01-02');
        $this->client = $this->makeClient('Synthetic Fill Importer');
        $this->actingWith([AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE]);
        $this->fakeOpenFda();
    }

    // ---- patient resolution ---------------------------------------------------------------

    public function test_patients_list_resolves_a_printed_name_to_the_one_matching_patient(): void
    {
        $this->makePatient($this->actor, 'Other Person', '1990-03-04');

        foreach (['Synthetic Fill Patient', 'SYNTHETIC FILL PATIENT', 'Patient, Synthetic Fill', ' synthetic   fill  patient '] as $printed) {
            $this->getJson('/api/v1/patients?'.http_build_query(['name' => $printed]))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $this->patient->id);
        }
        $this->getJson('/api/v1/patients?'.http_build_query(['name' => 'Fill Patient, Synthetic', 'birth_date' => '1980-01-02']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->patient->id);
    }

    public function test_an_unmatched_name_asks_the_agent_to_ask_and_creates_nothing(): void
    {
        $before = PhrPatient::query()->count();

        $this->getJson('/api/v1/patients?'.http_build_query(['name' => 'Nobody Known']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'patient_match_none')
            ->assertJsonPath('errors.name.0', fn (string $message): bool => str_contains($message, 'Ask the user') && str_contains($message, 'do not create a patient'));
        // A birth date that contradicts the only name match rules it out too.
        $this->getJson('/api/v1/patients?'.http_build_query(['name' => 'Synthetic Fill Patient', 'birth_date' => '1999-09-09']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'patient_match_none');
        $this->assertSame($before, PhrPatient::query()->count());
    }

    public function test_several_matching_patients_are_listed_for_the_agent_to_put_to_the_user(): void
    {
        $twin = $this->makePatient($this->actor, 'synthetic fill patient', '2010-05-06');

        $this->getJson('/api/v1/patients?'.http_build_query(['name' => 'Synthetic Fill Patient']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'patient_match_ambiguous')
            ->assertJsonCount(2, 'candidates')
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Ask the user') && str_contains($message, 'Do not guess'));
        // The date of birth printed on the source confirms which one.
        $this->getJson('/api/v1/patients?'.http_build_query(['name' => 'Synthetic Fill Patient', 'birth_date' => '2010-05-06']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $twin->id);
    }

    public function test_the_name_filter_only_sees_patients_the_caller_can_access(): void
    {
        $stranger = User::factory()->create(['user_role' => 'user']);
        $this->makePatient($stranger, 'Private Stranger', null);

        $this->getJson('/api/v1/patients?'.http_build_query(['name' => 'Private Stranger']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'patient_match_none');
    }

    // ---- ndc tools ------------------------------------------------------------------------

    public function test_ndc_lookup_and_search_are_clinical_read_operations(): void
    {
        $this->getJson('/api/v1/ndc/lookup?ndc=58151-0155-77')
            ->assertOk()
            ->assertJsonPath('status', 'resolved')
            ->assertJsonPath('selected.ndc_10', '58151-155-77')
            ->assertJsonPath('selected.ndc_11', '58151015577')
            ->assertJsonPath('selected.drug.brand_name', 'Lipitor')
            ->assertJsonPath('selected.drug.substance_names.0', 'atorvastatin calcium trihydrate')
            ->assertJsonPath('selected.drug.dosage_form', 'tablet, film coated');
        $this->getJson('/api/v1/ndc/lookup?ndc=nonsense')
            ->assertOk()
            ->assertJsonPath('status', 'unresolved')
            ->assertJsonPath('reason', 'invalid_ndc');
        $this->getJson('/api/v1/ndc/search?'.http_build_query(['name' => 'atorvastatin', 'strength' => '10 mg', 'package_size' => '90']))
            ->assertOk()
            ->assertJsonPath('status', 'candidates')
            ->assertJsonPath('candidates.0.match_reasons.0', fn (string $reason): bool => $reason !== '')
            ->assertJsonMissingPath('selected');
        $this->getJson('/api/v1/ndc/search')->assertUnprocessable();

        $this->actingWith([AgentApiScopes::CLINICAL_WRITE]);
        $this->getJson('/api/v1/ndc/lookup?ndc=58151-0155-77')->assertForbidden();
        $this->getJson('/api/v1/ndc/search?name=atorvastatin')->assertForbidden();
    }

    // ---- upsert ---------------------------------------------------------------------------

    public function test_a_fill_resolves_its_drug_creates_a_pending_medication_and_is_idempotent(): void
    {
        $document = $this->document();
        $payload = $this->fillPayload($document, ['ndc' => '58151-0155-77', 'rx_number' => '1234567']);

        $created = $this->putJson($this->url(), $payload)
            ->assertCreated()
            ->assertJsonPath('resource_type', 'medication-fills')
            ->assertJsonPath('outcome', 'created')
            ->assertJsonPath('medication_created', true)
            ->assertJsonPath('ndc.status', 'resolved')
            ->assertJsonPath('data.review_status', PhrReviewStatus::PENDING)
            ->assertJsonPath('data.import_source', 'agent-client:'.$this->client->id)
            ->assertJsonPath('data.source_document_id', $document->id)
            ->assertJsonPath('data.ndc_status', 'resolved')
            ->assertJsonPath('data.ndc_as_captured', '58151-0155-77')
            ->assertJsonPath('data.ndc_10', '58151-155-77')
            ->assertJsonPath('data.ndc_11', '58151015577')
            ->assertJsonPath('data.drug.brand_name', 'Lipitor')
            ->assertJsonPath('data.drug.substance_names', ['atorvastatin calcium trihydrate'])
            ->assertJsonPath('data.drug.strengths', ['10 mg/1'])
            ->assertJsonPath('data.drug.labeler_name', 'Viatris Specialty LLC')
            ->assertJsonPath('data.drug.rxcui', '259255')
            ->assertJsonPath('data.quantity', '90')
            ->assertJsonPath('data.paid_cents', 1250)
            ->assertJsonPath('data.retail_cents', 31999)
            ->json();
        $this->assertSame('rx:', substr((string) $created['data']['external_id'], 0, 3), 'derived from pharmacy, Rx number and fill date');

        $fill = PhrMedicationFill::query()->findOrFail($created['data']['id']);
        $this->assertSame(['product_ndc' => '58151-155'], ['product_ndc' => $fill->fda_snapshot['product_ndc']], 'the raw openFDA listing is kept');
        $this->assertNotNull($fill->fda_fetched_at);

        $medication = PhrMedication::query()->findOrFail($created['medication']['id']);
        $this->assertSame($medication->id, $fill->medication_id);
        $this->assertSame('atorvastatin calcium trihydrate', $medication->name);
        $this->assertSame('10', $medication->dose);
        $this->assertSame('mg', $medication->dose_unit);
        $this->assertSame('oral', $medication->route);
        $this->assertSame('259255', $medication->rxnorm_code);
        $this->assertSame('2026-10-28', $medication->started_on->toDateString());
        $this->assertSame('active', $medication->status);
        $this->assertSame(PhrReviewStatus::PENDING, $medication->review_status);
        $this->assertSame('phr-fill', $medication->import_source);
        $this->assertSame('fill:'.$fill->id, $medication->external_id, 'provenance points at the fill');
        $this->assertSame($document->id, $medication->source_document_id);

        $this->putJson($this->url(), $payload)
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('version', $created['version'])
            ->assertJsonPath('medication_created', false)
            ->assertJsonPath('medication.id', $medication->id);
        $this->assertSame(1, PhrMedicationFill::query()->count());
        $this->assertSame(1, PhrMedication::query()->count());
        $this->assertCount(1, array_filter($this->openFdaRequests, fn ($u) => str_contains($u, 'drug/ndc.json')), 'the retry did not call openFDA again');
    }

    public function test_all_caps_fda_values_are_stored_with_correct_casing_and_the_raw_listing_is_kept(): void
    {
        // openFDA lists this repackager's labeler and ingredient in capitals.
        $response = $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '70518-1906-1']))
            ->assertCreated()
            ->assertJsonPath('data.drug.substance_names', ['lisinopril'])
            ->assertJsonPath('data.drug.labeler_name', 'Remedyrepack Inc.')
            ->assertJsonPath('data.drug.package_description', '30 tablet in 1 blister pack (70518-1906-1)')
            ->assertJsonPath('data.drug.strengths', ['5 mg/1'])
            ->assertJsonPath('data.drug.brand_name', 'Lisinopril')
            ->assertJsonPath('data.drug.dosage_form', 'tablet')
            ->json();

        $snapshot = PhrMedicationFill::query()->findOrFail($response['data']['id'])->fda_snapshot;
        $this->assertSame('REMEDYREPACK INC.', $snapshot['labeler_name']);
        $this->assertSame('LISINOPRIL', $snapshot['active_ingredients'][0]['name']);
        $this->assertSame('30 TABLET in 1 BLISTER PACK (70518-1906-1)', $snapshot['packaging'][0]['description']);
        // Stored text keeps its casing; display casing is derived in the `display` object.
        $this->assertSame('Remedyrepack Inc.', PhrMedicationFill::query()->findOrFail($response['data']['id'])->labeler_name);
    }

    public function test_a_second_fill_of_the_same_drug_links_the_existing_medication(): void
    {
        $document = $this->document();
        $first = $this->putJson($this->url(), $this->fillPayload($document, ['ndc' => '58151-0155-77', 'rx_number' => 'A1']))->assertCreated()->json();
        $second = $this->putJson($this->url(), $this->fillPayload($document, ['ndc' => '58151-0155-77', 'rx_number' => 'A1', 'filled_on' => '2026-11-25']))
            ->assertCreated()
            ->assertJsonPath('medication_created', false)
            ->json();

        $this->assertNotSame($first['data']['external_id'], $second['data']['external_id']);
        $this->assertSame($first['medication']['id'], $second['medication']['id']);
        $this->assertSame(1, PhrMedication::query()->count());
        $this->assertSame(2, PhrMedicationFill::query()->count());
    }

    public function test_a_fill_links_the_patients_medication_by_rxnorm_code(): void
    {
        $existing = $this->medication(['name' => 'Lipitor 10', 'rxnorm_code' => '259255']);

        $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']))
            ->assertCreated()
            ->assertJsonPath('medication_created', false)
            ->assertJsonPath('medication.id', $existing->id);
        $this->assertSame(1, PhrMedication::query()->count());
    }

    public function test_a_fill_links_a_hand_entered_medication_with_the_same_name_and_strength(): void
    {
        $existing = $this->medication(['name' => 'Atorvastatin Calcium Trihydrate', 'dose' => '10', 'dose_unit' => 'mg', 'rxnorm_code' => null]);

        $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']))
            ->assertCreated()
            ->assertJsonPath('medication.id', $existing->id)
            ->assertJsonPath('medication_created', false);
    }

    public function test_a_fill_does_not_link_a_medication_of_another_patient_or_a_different_strength(): void
    {
        $other = $this->makePatient($this->actor, 'Another Patient', null);
        $this->medication(['name' => 'atorvastatin calcium trihydrate', 'dose' => '10', 'dose_unit' => 'mg', 'rxnorm_code' => '259255'], $other);
        $this->medication(['name' => 'atorvastatin calcium trihydrate', 'dose' => '40', 'dose_unit' => 'mg', 'rxnorm_code' => null]);

        $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']))
            ->assertCreated()
            ->assertJsonPath('medication_created', true);
        $this->assertSame(1, PhrMedication::query()->where('patient_id', $this->patient->id)->where('review_status', PhrReviewStatus::PENDING)->count());
    }

    public function test_an_explicit_medication_must_belong_to_the_patient(): void
    {
        $other = $this->makePatient($this->actor, 'Another Patient', null);
        $foreign = $this->medication(['name' => 'Foreign'], $other);
        $own = $this->medication(['name' => 'Own', 'rxnorm_code' => null, 'dose' => null]);

        $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77', 'medication_id' => $foreign->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('medication_id');
        $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77', 'medication_id' => $own->id]))
            ->assertCreated()
            ->assertJsonPath('medication.id', $own->id)
            ->assertJsonPath('medication_created', false);
    }

    public function test_a_receipt_and_a_label_photo_of_the_same_fill_merge_into_one_record(): void
    {
        $receipt = $this->document();
        $label = $this->document();
        $fromReceipt = $this->putJson($this->url(), [
            'source_document_id' => $receipt->id,
            'expected_version' => null,
            'data' => [
                'ndc' => '58151-0155-77', 'filled_on' => '2026-10-28', 'quantity' => 90, 'source_kind' => 'receipt_pdf',
                'rx_number' => 'RX-9001', 'pharmacy_name' => 'Example Pharmacy #1234', 'paid_cents' => 1000, 'retail_cents' => 18999,
            ],
        ])->assertCreated()->json();

        $labelData = [
            'ndc' => '58151-0155-77', 'filled_on' => '2026-10-28', 'quantity' => 90, 'source_kind' => 'label_photo',
            'rx_number' => 'rx-9001', 'pharmacy_name' => 'EXAMPLE PHARMACY #1234',
            'directions' => 'Take 1 tablet by mouth daily', 'prescriber_name' => 'Dr. A. Smith', 'days_supply' => 90,
            'drug_as_printed' => 'LIPITOR 10MG TAB',
        ];
        // Same identity, but a record that changed needs the version it was read at.
        $this->putJson($this->url(), ['source_document_id' => $label->id, 'expected_version' => null, 'data' => $labelData])->assertConflict();

        $merged = $this->putJson($this->url(), ['source_document_id' => $label->id, 'expected_version' => $fromReceipt['version'], 'data' => $labelData])
            ->assertOk()
            ->assertJsonPath('outcome', 'updated')
            ->assertJsonPath('data.id', $fromReceipt['data']['id'])
            ->assertJsonPath('data.external_id', $fromReceipt['data']['external_id'])
            ->assertJsonPath('data.directions', 'Take 1 tablet by mouth daily')
            ->assertJsonPath('data.source_document_id', $label->id)
            ->assertJsonPath('data.review_status', PhrReviewStatus::PENDING)
            ->json();

        $this->assertSame(1, PhrMedicationFill::query()->count());
        $this->assertSame(1, PhrMedication::query()->count());
        $this->assertNotSame($fromReceipt['version'], $merged['version']);
        $kept = PhrMedicationFill::query()->first();
        $this->assertSame([1000, 18999], [$kept->paid_cents, $kept->retail_cents], 'the price the label omitted is kept');
    }

    public function test_without_an_rx_number_identity_comes_from_the_document_and_locator(): void
    {
        $document = $this->document();
        $row = fn (string $locator, string $date): array => $this->fillPayload($document, [
            'ndc' => '58151-0155-77', 'rx_number' => null, 'source_kind' => 'csv', 'source_locator' => $locator, 'filled_on' => $date,
        ]);

        $one = $this->putJson($this->url(), $row('row 2', '2026-09-01'))->assertCreated()->json();
        $two = $this->putJson($this->url(), $row('row 3', '2026-10-01'))->assertCreated()->json();
        $this->putJson($this->url(), $row('row 2', '2026-09-01'))->assertOk()->assertJsonPath('outcome', 'unchanged');

        $this->assertStringStartsWith('doc:', $one['data']['external_id']);
        $this->assertNotSame($one['data']['external_id'], $two['data']['external_id']);
        $this->assertSame(2, PhrMedicationFill::query()->count());
    }

    public function test_an_explicit_external_id_is_used_and_one_is_required_when_none_can_be_derived(): void
    {
        $payload = ['source_document_id' => null, 'expected_version' => null, 'data' => [
            'filled_on' => '2026-10-28', 'quantity' => 30, 'source_kind' => 'manual', 'drug_as_printed' => 'Synthetic tablets',
        ]];

        $this->putJson($this->url(), $payload)->assertUnprocessable()->assertJsonValidationErrors('external_id');
        $this->putJson($this->url(), [...$payload, 'external_id' => 'my-own-id'])
            ->assertCreated()
            ->assertJsonPath('data.external_id', 'my-own-id')
            ->assertJsonPath('data.ndc_status', 'manual')
            ->assertJsonPath('ndc.status', 'unresolved');
    }

    public function test_provenance_is_required_and_the_agent_cannot_supply_drug_fields_or_a_review_status(): void
    {
        $document = $this->document();
        $good = $this->fillPayload($document, ['ndc' => '58151-0155-77']);

        $missingDocument = $good;
        $missingDocument['source_document_id'] = null;
        $this->putJson($this->url(), $missingDocument)->assertUnprocessable()->assertJsonValidationErrors('source_document_id');

        $noKind = $good;
        unset($noKind['data']['source_kind']);
        $this->putJson($this->url(), $noKind)->assertUnprocessable()->assertJsonValidationErrors('data.source_kind');

        $drug = $good;
        $drug['data']['brand_name'] = 'Invented';
        $drug['data']['strengths'] = ['999 mg'];
        $this->putJson($this->url(), $drug)->assertUnprocessable();

        $review = $good;
        $review['review_status'] = PhrReviewStatus::CONFIRMED;
        $this->putJson($this->url(), $review)->assertUnprocessable()->assertJsonValidationErrors('review_status');

        $foreignDocument = $this->document($this->makePatient($this->actor, 'Elsewhere', null));
        $this->putJson($this->url(), $this->fillPayload($foreignDocument, ['ndc' => '58151-0155-77']))->assertNotFound();
        $this->assertSame(0, PhrMedicationFill::query()->count());
    }

    public function test_an_ambiguous_ndc_is_saved_as_ambiguous_with_every_candidate_for_the_person_to_pick(): void
    {
        $this->fakeOpenFda(['00020-800-01']);
        $document = $this->document();
        $payload = $this->fillPayload($document, ['ndc' => '0002080001', 'drug_as_printed' => 'STERILE DILUENT 10ML']);

        $response = $this->putJson($this->url(), $payload)
            ->assertCreated()
            ->assertJsonPath('ndc.status', 'ambiguous')
            ->assertJsonPath('ndc.selected', null)
            ->assertJsonCount(2, 'ndc.candidates')
            ->assertJsonPath('data.ndc_status', 'ambiguous')
            ->assertJsonPath('data.ndc_10', null)
            ->assertJsonPath('data.drug.brand_name', null)
            ->json();
        $this->assertSame('STERILE DILUENT 10ML', $response['medication']['name'], 'a fill whose drug is unknown is listed under what the label said');

        // A retry lists the candidates again.
        $this->putJson($this->url(), $payload)->assertOk()->assertJsonCount(2, 'ndc.candidates');

        // The person picks one; the fill is updated and now resolved.
        $chosen = $payload;
        $chosen['data']['ndc_candidate'] = '00020-800-01';
        $chosen['expected_version'] = $response['version'];
        $this->putJson($this->url(), $chosen)
            ->assertOk()
            ->assertJsonPath('data.ndc_status', 'resolved')
            ->assertJsonPath('data.ndc_10', '00020-800-01')
            ->assertJsonPath('data.drug.brand_name', 'Sterile Diluent');
    }

    public function test_an_unresolved_ndc_is_saved_with_the_printed_drug_text_and_needs_it(): void
    {
        $payload = $this->fillPayload($this->document(), ['ndc' => '12345-6789-0']);
        $this->putJson($this->url(), $payload)->assertUnprocessable()->assertJsonValidationErrors('data.drug_as_printed');

        $payload['data']['drug_as_printed'] = 'MYSTERY CAPSULES';
        $this->putJson($this->url(), $payload)
            ->assertCreated()
            ->assertJsonPath('data.ndc_status', 'unresolved')
            ->assertJsonPath('data.ndc_as_captured', '12345-6789-0')
            ->assertJsonPath('ndc.status', 'unresolved')
            ->assertJsonPath('ndc.reason', 'not_found')
            ->assertJsonPath('medication.name', 'MYSTERY CAPSULES');
    }

    public function test_a_chosen_candidate_must_be_a_reading_of_the_ndc(): void
    {
        $payload = $this->fillPayload($this->document(), ['ndc' => '58151-0155-77', 'ndc_candidate' => '0002-0800-01']);

        $this->putJson($this->url(), $payload)->assertUnprocessable();
    }

    public function test_an_openfda_outage_saves_an_unresolved_fill_instead_of_guessing(): void
    {
        $this->fakeOpenFda(down: true);

        $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77', 'drug_as_printed' => 'LIPITOR 10MG']))
            ->assertCreated()
            ->assertJsonPath('data.ndc_status', 'unresolved')
            ->assertJsonPath('ndc.reason', 'lookup_unavailable')
            ->assertJsonPath('data.drug.brand_name', null);
    }

    public function test_a_write_only_caller_gets_receipts_that_disclose_nothing(): void
    {
        $this->actingWith([AgentApiScopes::CLINICAL_WRITE]);

        $response = $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']))
            ->assertCreated()
            ->assertJsonPath('receipt_only', true)
            ->assertJsonPath('data.review_status', PhrReviewStatus::PENDING)
            ->assertJsonMissingPath('data.drug')
            ->assertJsonMissingPath('medication.name')
            ->json();

        $this->assertSame(['id', 'review_status'], array_keys($response['medication']));
        $this->assertNull($response['ndc'], 'no stored NDC or drug detail for a caller who cannot read');
        $this->assertStringNotContainsString('58151', json_encode($response, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Lipitor', json_encode($response, JSON_THROW_ON_ERROR));
    }

    public function test_a_write_only_retry_and_an_ambiguous_fill_disclose_no_ndc_candidates_or_drug_detail(): void
    {
        $this->fakeOpenFda(['00020-800-01']);
        $this->actingWith([AgentApiScopes::CLINICAL_WRITE]);
        $resolved = $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']);
        $ambiguous = $this->fillPayload($this->document(), ['ndc' => '0002080001', 'rx_number' => 'AMB', 'drug_as_printed' => 'DILUENT']);

        foreach ([$resolved, $resolved, $ambiguous, $ambiguous] as $payload) {
            $body = $this->putJson($this->url(), $payload)->assertSuccessful()->assertJsonPath('receipt_only', true)->json();
            $this->assertNull($body['ndc']);
            $this->assertSame(['id', 'patient_id', 'review_status'], array_keys($body['data']));
            $this->assertStringNotContainsString('0002-0800', json_encode($body, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('candidates', json_encode($body, JSON_THROW_ON_ERROR));
        }
    }

    public function test_updating_a_fill_needs_clinical_read_so_a_write_only_token_cannot_reach_stored_detail(): void
    {
        $created = $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']))->assertCreated()->json();
        $this->actingWith([AgentApiScopes::CLINICAL_WRITE]);

        $this->patchJson($this->recordUrl($created['data']['id']), ['expected_version' => $created['version'], 'data' => ['quantity' => 30]])
            ->assertForbidden()
            ->assertJsonPath('reason', 'missing_scope');
    }

    public function test_a_writer_without_a_patient_grant_is_refused(): void
    {
        $stranger = $this->makePatient(User::factory()->create(['user_role' => 'user']), 'Not Yours', null);

        $this->putJson("/api/v1/patients/{$stranger->id}/medication-fills", $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']))
            ->assertNotFound();
    }

    // ---- update ---------------------------------------------------------------------------

    public function test_an_update_reopens_review_and_requires_the_current_version(): void
    {
        $created = $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']))->assertCreated()->json();
        $id = $created['data']['id'];
        PhrMedicationFill::query()->whereKey($id)->update(['review_status' => PhrReviewStatus::CONFIRMED]);
        $version = $this->getJson("/api/v1/patients/{$this->patient->id}/medication-fills/{$id}")->assertOk()->json('data.version');

        $this->patchJson($this->recordUrl($id), ['expected_version' => str_repeat('a', 64), 'data' => ['quantity' => 30]])->assertConflict();
        $this->assertSame(PhrReviewStatus::CONFIRMED, PhrMedicationFill::query()->findOrFail($id)->review_status);

        $this->patchJson($this->recordUrl($id), ['expected_version' => $version, 'data' => ['quantity' => 30, 'days_supply' => 30]])
            ->assertOk()
            ->assertJsonPath('outcome', 'updated')
            ->assertJsonPath('data.quantity', '30')
            ->assertJsonPath('data.days_supply', 30)
            ->assertJsonPath('data.review_status', PhrReviewStatus::PENDING);
    }

    public function test_an_update_that_changes_nothing_keeps_the_reviewers_decision_and_skips_openfda(): void
    {
        $created = $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']))->assertCreated()->json();
        $id = $created['data']['id'];
        PhrMedicationFill::query()->whereKey($id)->update(['review_status' => PhrReviewStatus::CONFIRMED]);
        $version = $this->getJson("/api/v1/patients/{$this->patient->id}/medication-fills/{$id}")->json('data.version');
        $requests = count($this->openFdaRequests);

        $this->patchJson($this->recordUrl($id), ['expected_version' => $version, 'data' => ['ndc' => '58151-0155-77', 'quantity' => 90]])
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('data.review_status', PhrReviewStatus::CONFIRMED);
        $this->assertSame($requests, count($this->openFdaRequests));
    }

    public function test_changing_the_ndc_re_resolves_the_drug_and_relinks_a_medication_that_no_longer_fits(): void
    {
        $created = $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']))->assertCreated()->json();
        $id = $created['data']['id'];
        $firstMedication = $created['medication']['id'];
        $version = $this->getJson("/api/v1/patients/{$this->patient->id}/medication-fills/{$id}")->json('data.version');

        $updated = $this->patchJson($this->recordUrl($id), ['expected_version' => $version, 'data' => ['ndc' => '60505-0134-00']])
            ->assertOk()
            ->assertJsonPath('data.ndc_10', '60505-134-00')
            ->assertJsonPath('data.drug.brand_name', 'Cyclosporine')
            ->assertJsonPath('data.drug.dosage_form', 'capsule, gelatin coated')
            ->assertJsonPath('data.review_status', PhrReviewStatus::PENDING)
            ->json('data');

        $this->assertNotSame($firstMedication, $updated['medication_id']);
        $this->assertSame('cyclosporine', $updated['medication']['name']);
        $this->assertNull(PhrMedication::query()->find($firstMedication), 'the pending medication this fill had created is withdrawn with it');
    }

    public function test_an_update_keeps_the_medication_link_unless_told_otherwise(): void
    {
        $created = $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']))->assertCreated()->json();
        $id = $created['data']['id'];
        $medicationId = $created['medication']['id'];
        $other = $this->medication(['name' => 'Chosen by hand', 'rxnorm_code' => null, 'dose' => null]);
        $version = fn () => $this->getJson("/api/v1/patients/{$this->patient->id}/medication-fills/{$id}")->json('data.version');

        $this->patchJson($this->recordUrl($id), ['expected_version' => $version(), 'data' => ['medication_id' => null, 'quantity' => 60]])
            ->assertOk()
            ->assertJsonPath('data.medication_id', $medicationId);
        $this->patchJson($this->recordUrl($id), ['expected_version' => $version(), 'data' => ['medication_id' => $other->id]])
            ->assertOk()
            ->assertJsonPath('data.medication_id', $other->id);
    }

    public function test_a_fill_can_be_retracted_by_its_writer_and_its_identity_stays_reserved(): void
    {
        $payload = $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']);
        $created = $this->putJson($this->url(), $payload)->assertCreated()->json();

        $this->postJson($this->recordUrl($created['data']['id']).'/retract', ['expected_version' => $created['version']])
            ->assertOk()
            ->assertJsonPath('lifecycle', 'retracted');
        $this->getJson("/api/v1/patients/{$this->patient->id}/medication-fills")->assertOk()->assertJsonCount(0, 'data');
        $this->putJson($this->url(), $payload)->assertConflict();
    }

    // ---- reads ----------------------------------------------------------------------------

    public function test_fills_list_filters_by_date_medication_pharmacy_and_statuses(): void
    {
        $document = $this->document();
        $make = fn (array $data, array $top = []) => $this->putJson($this->url(), $this->fillPayload($document, $data) + $top)->assertCreated()->json();
        $a = $make(['ndc' => '58151-0155-77', 'rx_number' => 'A', 'filled_on' => '2026-08-01', 'pharmacy_name' => 'Alpha Drug']);
        $b = $make(['ndc' => '60505-0134-0', 'rx_number' => 'B', 'filled_on' => '2026-09-15', 'pharmacy_name' => 'Beta Pharmacy']);
        $c = $make(['ndc' => '12345-6789-0', 'drug_as_printed' => 'X', 'rx_number' => 'C', 'filled_on' => '2026-10-20', 'pharmacy_name' => 'Alpha Drug']);
        PhrMedicationFill::query()->whereKey($b['data']['id'])->update(['review_status' => PhrReviewStatus::CONFIRMED]);

        $ids = fn (array $query): array => array_map(
            static fn (array $row): int => $row['id'],
            $this->getJson("/api/v1/patients/{$this->patient->id}/medication-fills?".http_build_query($query))->assertOk()->json('data'),
        );

        $this->assertEqualsCanonicalizing([$a['data']['id'], $b['data']['id'], $c['data']['id']], $ids([]));
        $this->assertSame([$b['data']['id'], $c['data']['id']], $ids(['filled_from' => '2026-09-01']));
        $this->assertSame([$a['data']['id'], $b['data']['id']], $ids(['filled_to' => '2026-09-30']));
        $this->assertSame([$b['data']['id']], $ids(['filled_from' => '2026-09-01', 'filled_to' => '2026-09-30']));
        $this->assertSame([$a['data']['id']], $ids(['medication_id' => $a['medication']['id']]));
        $this->assertSame([$a['data']['id'], $c['data']['id']], $ids(['pharmacy' => 'alpha']));
        $this->assertSame([$b['data']['id']], $ids(['review_status' => 'confirmed']));
        $this->assertSame([$c['data']['id']], $ids(['ndc_status' => 'unresolved']));
        $this->assertSame([$a['data']['id'], $b['data']['id']], $ids(['ndc_status' => 'resolved']));

        $page = $this->getJson("/api/v1/patients/{$this->patient->id}/medication-fills?limit=2")->json();
        $this->assertCount(2, $page['data']);
        $this->assertTrue($page['pagination']['has_more']);
        $this->getJson("/api/v1/patients/{$this->patient->id}/medication-fills?ndc_status=bogus")->assertUnprocessable();
        $this->getJson("/api/v1/patients/{$this->patient->id}/medications?pharmacy=x")->assertUnprocessable();
        $this->getJson("/api/v1/patients/{$this->patient->id}/medication-fills/{$a['data']['id']}")
            ->assertOk()
            ->assertJsonPath('data.version', $a['version']);
    }

    public function test_fills_appear_in_search_timeline_changes_and_evidence_links(): void
    {
        $document = $this->document();
        $created = $this->putJson($this->url(), $this->fillPayload($document, ['ndc' => '58151-0155-77', 'rx_number' => 'S1']))->assertCreated()->json();
        $id = $created['data']['id'];
        $base = "/api/v1/patients/{$this->patient->id}";

        $this->getJson("{$base}/records/search?".http_build_query(['resource_type' => ['medication-fills'], 'q' => 'lipitor']))
            ->assertOk()
            ->assertJsonPath('data.0.resource_type', 'medication-fills')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.summary', 'Lipitor')
            ->assertJsonPath('data.0.provider', 'Dr. A. Smith')
            ->assertJsonPath('data.0.event_at', fn (string $at): bool => str_starts_with($at, '2026-10-28'))
            ->assertJsonPath('data.0.review_status', PhrReviewStatus::PENDING);
        $this->getJson("{$base}/records/search?".http_build_query(['code' => '259255']))
            ->assertOk()
            ->assertJsonPath('data.0.resource_type', 'medication-fills');
        $this->getJson("{$base}/timeline?".http_build_query(['resource_type' => ['medication-fills']]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);
        $this->getJson("{$base}/changes?".http_build_query(['resource_type' => ['medication-fills']]))
            ->assertOk()
            ->assertJsonPath('data.0.change_type', 'upsert')
            ->assertJsonPath('data.0.data.id', $id);

        $this->actingWith([AgentApiScopes::CLINICAL_READ, AgentApiScopes::DOCUMENTS_READ]);
        $this->getJson("{$base}/evidence-links?".http_build_query(['resource_type' => 'document', 'resource_id' => $document->id]))
            ->assertOk()
            ->assertJsonFragment(['target_type' => 'medication-fill', 'target_id' => $id]);
        $this->getJson("{$base}/evidence-links?".http_build_query(['resource_type' => 'medication-fill', 'resource_id' => $id]))
            ->assertOk()
            ->assertJsonFragment(['target_type' => 'document', 'target_id' => $document->id]);

        $this->postJson("{$base}/medication-fills/resolve", ['external_ids' => [$created['data']['external_id'], 'nope']])
            ->assertOk()
            ->assertJsonPath("resolved.{$created['data']['external_id']}.id", $id)
            ->assertJsonPath('unresolved.0', 'nope');
    }

    // ---- review fixes ----------------------------------------------------------------------

    public function test_a_birth_date_without_a_name_is_refused_not_ignored(): void
    {
        $this->makePatient($this->actor, 'Second Patient', '1990-03-04');

        $this->getJson('/api/v1/patients?'.http_build_query(['birth_date' => '1980-01-02']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_the_update_resolves_the_ndc_before_it_opens_its_transaction_and_refuses_a_stale_version_without_calling_openfda(): void
    {
        $created = $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']))->assertCreated()->json();
        $id = $created['data']['id'];
        $version = $this->getJson($this->recordUrl($id))->json('data.version');
        $baseline = DB::transactionLevel();

        $this->fakeOpenFda();
        $this->patchJson($this->recordUrl($id), ['expected_version' => str_repeat('a', 64), 'data' => ['ndc' => '60505-0134-00']])->assertConflict();
        $this->assertSame([], $this->openFdaRequests, 'a stale version is refused before any network call');

        $this->patchJson($this->recordUrl($id), ['expected_version' => $version, 'data' => ['ndc' => '60505-0134-00']])->assertOk();
        $this->assertNotEmpty($this->openFdaRequests);
        $this->assertSame([$baseline], array_values(array_unique($this->openFdaTransactionLevels)), 'openFDA is never called while a transaction holds the row lock');
    }

    public function test_an_upsert_resolves_the_ndc_outside_its_transaction(): void
    {
        $baseline = DB::transactionLevel();
        $this->fakeOpenFda();

        $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']))->assertCreated();

        $this->assertSame([$baseline], array_values(array_unique($this->openFdaTransactionLevels)));
    }

    public function test_an_update_with_an_explicit_null_ndc_removes_the_drug_and_needs_the_printed_name(): void
    {
        $created = $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']))->assertCreated()->json();
        $id = $created['data']['id'];
        $version = $this->getJson($this->recordUrl($id))->json('data.version');

        $this->patchJson($this->recordUrl($id), ['expected_version' => $version, 'data' => ['ndc' => null]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ndc');

        $this->patchJson($this->recordUrl($id), ['expected_version' => $version, 'data' => ['ndc' => null, 'drug_as_printed' => 'LIPITOR 10MG']])
            ->assertOk()
            ->assertJsonPath('data.ndc_status', 'manual')
            ->assertJsonPath('data.ndc_10', null)
            ->assertJsonPath('data.drug.brand_name', null)
            ->assertJsonPath('data.drug.substance_names', []);
        $this->assertNull(PhrMedicationFill::query()->findOrFail($id)->fda_snapshot);
    }

    public function test_an_update_cannot_blank_the_printed_drug_text_of_a_fill_without_a_resolved_drug(): void
    {
        $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '12345-6789-0', 'drug_as_printed' => 'MYSTERY CAPSULES']))->assertCreated();
        $fill = PhrMedicationFill::query()->firstOrFail();
        $version = $this->getJson($this->recordUrl($fill->id))->json('data.version');

        $this->patchJson($this->recordUrl($fill->id), ['expected_version' => $version, 'data' => ['drug_as_printed' => null]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('data.drug_as_printed');
        $this->putJson($this->url(), [...$this->fillPayload($this->document(), ['ndc' => '12345-6789-0', 'drug_as_printed' => null]), 'expected_version' => $version])
            ->assertUnprocessable();
        $this->assertSame('MYSTERY CAPSULES', $fill->fresh()->drug_as_printed);
    }

    public function test_choosing_a_medication_over_mcp_or_rest_tidies_the_one_it_leaves_and_retraction_withdraws_it(): void
    {
        $created = $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']))->assertCreated()->json();
        $id = $created['data']['id'];
        $autoId = $created['medication']['id'];
        $own = $this->medication(['name' => 'Chosen by hand', 'rxnorm_code' => null, 'dose' => null]);
        $version = $this->getJson($this->recordUrl($id))->json('data.version');

        $moved = $this->patchJson($this->recordUrl($id), ['expected_version' => $version, 'data' => ['medication_id' => $own->id]])
            ->assertOk()
            ->assertJsonPath('data.medication_id', $own->id)
            ->json();
        $this->assertSoftDeleted('phr_medications', ['id' => $autoId]);

        // A fill that created its medication and is then retracted takes the unreviewed entry with it.
        $second = $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '60505-0134-0', 'rx_number' => 'Z9']))->assertCreated()->json();
        $this->postJson($this->recordUrl($second['data']['id']).'/retract', ['expected_version' => $second['version']])->assertOk();
        $this->assertSoftDeleted('phr_medications', ['id' => $second['medication']['id']]);
        $this->assertNotSoftDeleted('phr_medications', ['id' => $own->id]);
        $this->assertNotNull($moved);
    }

    public function test_a_retracted_fill_keeps_a_medication_other_fills_still_use(): void
    {
        $one = $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77', 'rx_number' => 'A']))->assertCreated()->json();
        $this->putJson($this->url(), $this->fillPayload($this->document(), ['ndc' => '58151-0155-77', 'rx_number' => 'B']))->assertCreated();

        $this->postJson($this->recordUrl($one['data']['id']).'/retract', ['expected_version' => $one['version']])->assertOk();

        $this->assertNotSoftDeleted('phr_medications', ['id' => $one['medication']['id']]);
    }

    public function test_a_deleted_fills_identity_conflicts_instead_of_erroring(): void
    {
        $payload = $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']);
        $created = $this->putJson($this->url(), $payload)->assertCreated()->json();
        PhrMedicationFill::query()->findOrFail($created['data']['id'])->delete();

        $this->putJson($this->url(), $payload)->assertConflict();
        $this->assertSame(1, PhrMedicationFill::withTrashed()->count());
    }

    public function test_an_agent_correcting_an_unresolved_drug_name_reconciles_the_medication_link(): void
    {
        $other = $this->medication(['name' => 'Vitamin B12', 'dose' => null, 'dose_unit' => null, 'rxnorm_code' => null]);
        $payload = $this->fillPayload($this->document(), ['rx_number' => 'V1', 'drug_as_printed' => 'Vitamin D']);
        unset($payload['data']['ndc']);
        $created = $this->putJson($this->url(), $payload)->assertCreated()->json();
        $id = $created['data']['id'];
        $version = fn () => $this->getJson($this->recordUrl($id))->json('data.version');

        $this->patchJson($this->recordUrl($id), ['expected_version' => $version(), 'data' => ['drug_as_printed' => 'vitamin b12']])
            ->assertOk()
            ->assertJsonPath('data.medication_id', $other->id);
        $this->assertSoftDeleted('phr_medications', ['id' => $created['medication']['id']]);

        // The same correction through the idempotent upsert.
        $payload['data']['drug_as_printed'] = 'Fish oil';
        $payload['expected_version'] = $version();
        $moved = $this->putJson($this->url(), $payload)->assertOk()->assertJsonPath('outcome', 'updated')->json();
        $this->assertSame('Fish oil', $moved['medication']['name']);
        $this->assertNotSame($other->id, $moved['data']['medication_id']);
    }

    public function test_a_retry_is_not_called_unchanged_when_another_request_changed_the_ndc_after_it_was_read(): void
    {
        $payload = $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']);
        $created = $this->putJson($this->url(), $payload)->assertCreated()->json();
        $id = $created['data']['id'];

        // Another request moves the fill to a different NDC between this request reading the
        // row (where the requested NDC still matches) and locking it.
        $moved = false;
        PhrMedicationFill::retrieved(function (PhrMedicationFill $fill) use (&$moved, $id): void {
            if (! $moved && $fill->id === $id) {
                $moved = true;
                DB::table('phr_medication_fills')->where('id', $id)->update([
                    'ndc_as_captured' => '60505-0134-0', 'ndc_10' => '60505-0134-0', 'ndc_11' => '60505013400', 'brand_name' => 'Cyclosporine',
                ]);
            }
        });

        // The retry carries the version it last saw, which the other request has made stale.
        $this->putJson($this->url(), $payload)->assertConflict();

        $this->assertTrue($moved);
        $stored = PhrMedicationFill::query()->findOrFail($id);
        $this->assertSame('60505-0134-0', $stored->ndc_10, 'the other request\'s drug is not overwritten or reported as the requested one');
    }

    public function test_a_stale_snapshot_is_prepared_again_and_a_current_version_then_applies_the_requested_ndc(): void
    {
        $payload = $this->fillPayload($this->document(), ['ndc' => '58151-0155-77']);
        $created = $this->putJson($this->url(), $payload)->assertCreated()->json();
        $id = $created['data']['id'];
        $moved = false;
        PhrMedicationFill::retrieved(function (PhrMedicationFill $fill) use (&$moved, $id): void {
            if (! $moved && $fill->id === $id) {
                $moved = true;
                DB::table('phr_medication_fills')->where('id', $id)->update(['ndc_as_captured' => '60505-0134-0', 'ndc_10' => '60505-0134-0']);
            }
        });
        // The version the caller holds is the one after the other request's change.
        $after = new PhrMedicationFill;
        $after->setRawAttributes([...PhrMedicationFill::query()->findOrFail($id)->getAttributes(), 'ndc_as_captured' => '60505-0134-0', 'ndc_10' => '60505-0134-0']);
        $moved = false;

        $response = $this->putJson($this->url(), [...$payload, 'expected_version' => app(AgentClinicalRecordVersion::class)->for($after)]);

        $response->assertOk()->assertJsonPath('outcome', 'updated')->assertJsonPath('data.ndc_10', '58151-155-77');
    }

    // ---- helpers --------------------------------------------------------------------------

    /**
     * @param  list<string>  $scopes
     */
    private function actingWith(array $scopes): void
    {
        Passport::actingAs($this->actor, $scopes, 'api', $this->client);
    }

    private function url(): string
    {
        return "/api/v1/patients/{$this->patient->id}/medication-fills";
    }

    private function recordUrl(int $id): string
    {
        return $this->url().'/'.$id;
    }

    /**
     * A complete upsert body; `$data` overrides the fill's fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fillPayload(PhrDocument $document, array $data = []): array
    {
        return [
            'source_document_id' => $document->id,
            'expected_version' => null,
            'data' => [
                'filled_on' => '2026-10-28',
                'quantity' => 90,
                'quantity_unit' => 'tablet',
                'source_kind' => 'label_photo',
                'rx_number' => '1234567',
                'pharmacy_name' => 'Example Pharmacy',
                'paid_cents' => 1250,
                'retail_cents' => 31999,
                'prescriber_name' => 'Dr. A. Smith',
                ...$data,
            ],
        ];
    }

    private function document(?PhrPatient $patient = null): PhrDocument
    {
        $patient ??= $this->patient;

        return PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $this->actor->id,
            'uploaded_by_user_id' => $this->actor->id,
            'document_type' => 'other',
            'source' => 'manual_upload',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'byte_size' => 0,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function medication(array $attributes, ?PhrPatient $patient = null): PhrMedication
    {
        $patient ??= $this->patient;

        return PhrMedication::factory()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'review_status' => PhrReviewStatus::CONFIRMED,
            ...$attributes,
        ]);
    }

    private function makePatient(User $owner, string $name, ?string $birthDate): PhrPatient
    {
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => $name,
            'relationship' => 'self',
            'birth_date' => $birthDate,
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

    private function makeClient(string $name): Client
    {
        return Client::query()->create([
            'name' => $name,
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
    }
}

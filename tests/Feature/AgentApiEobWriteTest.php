<?php

namespace Tests\Feature;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Eob\EobClaim;
use App\Services\PHR\Import\EobPdfTextExtractor;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\Support\FakesOpenFda;
use Tests\TestCase;

/** EOB writes over the versioned REST API: import with the server's parsers, upsert, update and the fill link. */
final class AgentApiEobWriteTest extends TestCase
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
        Storage::fake(PhrDocument::STORAGE_DISK);
        $this->actor = User::factory()->create(['user_role' => 'user']);
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->actor->id, 'display_name' => 'Synthetic Eob Patient', 'relationship' => 'self']);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $this->actor->id, 'granted_at' => now(),
        ]);
        $this->client = $this->makeClient('Synthetic Eob Client');
        $this->actingWith([AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE]);
        $this->fakeOpenFda();
    }

    // ---- eobs.upsert ----------------------------------------------------------------------

    public function test_an_eob_is_written_pending_review_and_a_repeat_is_unchanged(): void
    {
        $document = $this->document();
        $created = $this->putJson($this->url(), $this->claim($document))
            ->assertCreated()
            ->assertJsonPath('resource_type', 'eob')
            ->assertJsonPath('outcome', 'created')
            ->assertJsonPath('match', null)
            ->assertJsonPath('data.review_status', PhrReviewStatus::PENDING)
            ->assertJsonPath('data.source_document_id', $document->id)
            ->assertJsonPath('data.claim_number', 'CLM-100')
            ->assertJsonPath('data.total_plan_payment', '80.00', 'totals are summed from the lines when not stated')
            ->assertJsonPath('data.lines_count', 2)
            ->assertJsonCount(2, 'lines')
            ->json();

        // What the write answers is exactly what a later read returns.
        $this->assertSame($this->getJson("{$this->url()}/{$created['data']['id']}")->assertOk()->json('data'), $created['data']);
        $eob = PhrEob::query()->sole();
        $this->assertSame('agent-client:'.$this->client->id, $eob->import_source);
        $this->assertSame(EobClaim::fingerprint(EobClaim::normalize($this->claim($document)['data'])), $eob->claim_fingerprint);
        $this->assertSame(2, PhrEobLine::query()->where('eob_id', $eob->id)->count());

        $this->putJson($this->url(), $this->claim($document))
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('match.by', 'claim_fingerprint')
            ->assertJsonPath('version', $created['version']);
        $this->assertSame(1, PhrEob::query()->count());
        $this->assertSame(2, PhrEobLine::query()->count());
    }

    public function test_the_same_claim_another_source_holds_is_not_written_again(): void
    {
        $document = $this->document();
        $claim = $this->claim($document);
        // What a console importer would have written for it.
        $normalized = EobClaim::normalize($claim['data']);
        $existing = PhrEob::query()->create([
            ...$normalized['header'],
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'import_source' => 'meritain_eob',
            'external_id' => 'eob:meritain:'.str_repeat('a', 64), 'claim_fingerprint' => EobClaim::fingerprint($normalized),
        ]);

        $this->putJson($this->url(), $claim)
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('match', ['by' => 'claim_fingerprint', 'import_source' => 'meritain_eob'])
            ->assertJsonPath('data.id', $existing->id);

        // Read slightly differently (a total the model got wrong), it is still the same claim by number, type and date.
        $reread = $claim;
        $reread['data']['total_charges'] = '999.00';
        $this->putJson($this->url(), $reread)
            ->assertOk()
            ->assertJsonPath('match.by', 'claim_number')
            ->assertJsonPath('data.id', $existing->id);
        $this->assertSame(1, PhrEob::query()->count());
    }

    public function test_correcting_a_claim_needs_its_version_and_keeps_its_lines(): void
    {
        $document = $this->document();
        $created = $this->putJson($this->url(), $this->claim($document))->assertCreated()->json();
        $lineIds = array_column($created['lines'], 'id');

        $corrected = $this->claim($document);
        $corrected['data']['lines'][1]['patient_responsibility'] = '25.00';
        $this->putJson($this->url(), $corrected)->assertConflict();
        $this->putJson($this->url(), [...$corrected, 'expected_version' => $created['version']])
            ->assertOk()
            ->assertJsonPath('outcome', 'updated')
            ->assertJsonPath('data.review_status', PhrReviewStatus::PENDING);
        $this->assertSame(1, PhrEob::query()->count());
        $this->assertEqualsCanonicalizing($lineIds, PhrEobLine::query()->pluck('id')->all(), 'lines are updated in place');
    }

    public function test_a_change_the_fingerprint_does_not_cover_is_a_correction_not_a_duplicate(): void
    {
        $document = $this->document();
        $created = $this->putJson($this->url(), $this->claim($document))->assertCreated()->json();

        $changed = $this->claim($document);
        $changed['data']['plan_name'] = 'Corrected Plan';
        $this->putJson($this->url(), $changed)->assertConflict();
        $this->putJson($this->url(), [...$changed, 'expected_version' => $created['version']])
            ->assertOk()
            ->assertJsonPath('outcome', 'updated')
            ->assertJsonPath('data.plan_name', 'Corrected Plan');
        $this->assertSame(1, PhrEob::query()->count());
    }

    public function test_line_numbers_cannot_collide_and_a_fill_without_a_date_is_reported(): void
    {
        $document = $this->document();
        $claim = $this->pharmacyClaim($document);
        // An explicit 1 and an omitted number: the omitted one must not also become 1.
        $claim['data']['lines'][0]['line_number'] = 1;
        unset($claim['data']['lines'][1]['line_number']);
        $claim['data']['lines'][1]['service_start'] = null;
        $claim['data']['lines'][1]['fill'] = ['drug_as_printed' => 'Synthetic drug', 'quantity' => 30];

        $created = $this->putJson($this->url(), $claim)->assertCreated()->json();
        $this->assertSame([1, 2], array_column($created['lines'], 'line_number'));
        $this->assertSame('error', $created['fills'][1]['outcome']);
        $this->assertArrayHasKey('filled_on', $created['fills'][1]['errors']);
        $this->assertSame(1, PhrEob::query()->count(), 'the claim is saved, the fill is reported');
    }

    public function test_fills_follow_the_lines_of_a_claim_another_source_numbered_differently(): void
    {
        $document = $this->document();
        $claim = $this->pharmacyClaim($document);
        $claim['data']['lines'][1]['fill'] = ['ndc' => '70518-1906-1', 'quantity' => 30, 'rx_number' => '7654321'];
        $claim['data']['lines'][1]['description'] = 'lisinopril tablet';
        // The claim a parser stored numbered its lines 1 and 2; this reading numbers them 10 and 20.
        $stored = EobClaim::normalize($claim['data']);
        $other = PhrEob::query()->create([
            ...$stored['header'], 'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'import_source' => 'meritain_eob',
            'external_id' => 'eob:x', 'claim_fingerprint' => EobClaim::fingerprint($stored),
        ]);
        foreach ($stored['lines'] as $line) {
            PhrEobLine::query()->create(['eob_id' => $other->id, 'patient_id' => $this->patient->id, ...array_diff_key($line, ['fill' => true])]);
        }
        $claim['data']['lines'][0]['line_number'] = 10;
        $claim['data']['lines'][1]['line_number'] = 20;

        $written = $this->putJson($this->url(), $claim)->assertOk()->assertJsonPath('match.import_source', 'meritain_eob')->json();
        $this->assertCount(2, $written['fills']);
        $this->assertSame(['created', 'created'], array_column($written['fills'], 'outcome'));
        $storedIds = PhrEobLine::query()->where('eob_id', $other->id)->orderBy('line_number')->pluck('id')->all();
        $this->assertSame($storedIds, array_column($written['fills'], 'line_id'), 'each fill is linked to the stored line it belongs to');

        // Lines that do not correspond are reported, never linked to a guess.
        $claim['data']['lines'][1]['procedure_code'] = '1111111111';
        $claim['data']['lines'][1]['fill']['rx_number'] = '9999999';
        $second = $this->putJson($this->url(), $claim)->assertOk()->json();
        $unmatched = collect($second['fills'])->firstWhere('line_number', 20);
        $this->assertSame('error', $unmatched['outcome']);
        $this->assertNull($unmatched['line_id']);
    }

    public function test_an_empty_fill_object_is_a_line_without_a_fill(): void
    {
        $claim = $this->pharmacyClaim($this->document());
        $claim['data']['lines'][0]['fill'] = [];
        $claim['data']['lines'][1]['fill'] = [];

        $this->putJson($this->url(), $claim)->assertCreated()->assertJsonPath('fills', []);
        $this->assertSame(0, PhrMedicationFill::query()->count());
    }

    public function test_an_identical_retry_is_unchanged_whatever_order_the_lines_were_sent_in(): void
    {
        $claim = $this->claim($this->document());
        $claim['data']['lines'][0]['line_number'] = 2;
        $claim['data']['lines'][1]['line_number'] = 1;
        $this->putJson($this->url(), $claim)->assertCreated();
        $this->putJson($this->url(), $claim)->assertOk()->assertJsonPath('outcome', 'unchanged');
    }

    public function test_correcting_a_claim_into_one_that_already_exists_is_a_conflict(): void
    {
        $document = $this->document();
        $first = $this->putJson($this->url(), $this->claim($document))->assertCreated()->json();
        $secondClaim = $this->claim($document);
        $secondClaim['data']['claim_number'] = 'CLM-200';
        $second = $this->putJson($this->url(), [...$secondClaim, 'external_id' => 'second'])->assertCreated()->json();

        // Fixing the second claim's number makes it exactly the first.
        $this->putJson($this->url(), [...$this->claim($document), 'external_id' => 'second', 'expected_version' => $second['version']])->assertConflict();
        $this->assertSame(2, PhrEob::query()->count());
        $this->assertNotSame($first['data']['id'], $second['data']['id']);
    }

    public function test_provenance_is_required_and_review_status_is_not_accepted(): void
    {
        $claim = $this->claim($this->document());
        unset($claim['source_document_id']);
        $this->putJson($this->url(), $claim)->assertUnprocessable();

        $this->putJson($this->url(), [...$this->claim($this->document()), 'review_status' => 'confirmed'])->assertUnprocessable();
        $foreign = $this->document(PhrPatient::query()->create(['owner_user_id' => User::factory()->create()->id, 'display_name' => 'Synthetic Other', 'relationship' => 'self']));
        $this->putJson($this->url(), $this->claim($foreign))->assertNotFound();
        $this->assertSame(0, PhrEob::query()->count());
    }

    public function test_a_write_only_caller_gets_a_receipt(): void
    {
        $this->actingWith([AgentApiScopes::CLINICAL_WRITE]);
        $this->putJson($this->url(), $this->claim($this->document()))
            ->assertCreated()
            ->assertJsonPath('receipt_only', true)
            ->assertJsonMissingPath('data.claim_number')
            ->assertJsonPath('data.review_status', PhrReviewStatus::PENDING);
    }

    // ---- eobs.update ----------------------------------------------------------------------

    public function test_an_eob_this_connection_wrote_can_be_corrected_with_its_version(): void
    {
        $created = $this->putJson($this->url(), $this->claim($this->document()))->assertCreated()->json();
        $id = $created['data']['id'];

        $this->patchJson("{$this->url()}/{$id}", ['expected_version' => str_repeat('0', 64), 'data' => ['provider_name' => 'Corrected Pharmacy']])->assertConflict();
        $updated = $this->patchJson("{$this->url()}/{$id}", ['expected_version' => $created['version'], 'data' => ['provider_name' => 'Corrected Pharmacy']])
            ->assertOk()
            ->assertJsonPath('outcome', 'updated')
            ->assertJsonPath('data.provider_name', 'Corrected Pharmacy')
            ->assertJsonPath('data.claim_number', 'CLM-100')
            ->json();
        $this->assertNotSame($created['version'], $updated['version']);
        $this->assertSame($this->getJson("{$this->url()}/{$id}")->assertOk()->json('data'), $updated['data']);
        $this->assertSame($updated['version'], $updated['data']['version']);

        // An identical patch is a no-op, and replacing the lines recomputes the totals.
        $this->patchJson("{$this->url()}/{$id}", ['expected_version' => $updated['version'], 'data' => ['provider_name' => 'Corrected Pharmacy']])
            ->assertOk()->assertJsonPath('outcome', 'unchanged');
        $replaced = $this->patchJson("{$this->url()}/{$id}", ['expected_version' => $updated['version'], 'data' => ['lines' => [
            ['procedure_code' => '5815101577', 'service_start' => '2026-10-28', 'plan_payment' => '10.00'],
        ]]])->assertOk()->assertJsonPath('data.lines_count', 1)->assertJsonPath('data.total_plan_payment', '10.00')->json();
        $this->assertSame(1, PhrEobLine::query()->count());

        // review_status stays server-owned, and fills are not part of a correction.
        $this->patchJson("{$this->url()}/{$id}", ['expected_version' => $replaced['version'], 'review_status' => 'confirmed', 'data' => []])->assertUnprocessable();
    }

    public function test_correcting_only_a_line_still_advances_the_version(): void
    {
        $created = $this->putJson($this->url(), $this->claim($this->document()))->assertCreated()->json();
        $id = $created['data']['id'];
        $lines = $this->claim($this->document())['data']['lines'];
        $lines[0]['description'] = 'Corrected description';

        $updated = $this->patchJson("{$this->url()}/{$id}", ['expected_version' => $created['version'], 'data' => ['lines' => $lines]])
            ->assertOk()->assertJsonPath('outcome', 'updated')->json();
        $this->assertNotSame($created['version'], $updated['version']);
        // The version a reviewer or a second writer held before the correction no longer applies.
        $this->patchJson("{$this->url()}/{$id}", ['expected_version' => $created['version'], 'data' => ['provider_name' => 'Other']])->assertConflict();
    }

    public function test_an_eob_another_source_imported_cannot_be_corrected(): void
    {
        $normalized = EobClaim::normalize($this->claim($this->document())['data']);
        $other = PhrEob::query()->create([
            ...$normalized['header'], 'patient_id' => $this->patient->id, 'user_id' => $this->actor->id,
            'import_source' => 'meritain_eob', 'external_id' => 'eob:x', 'claim_fingerprint' => EobClaim::fingerprint($normalized),
        ]);
        $version = $this->getJson("{$this->url()}/{$other->id}")->assertOk()->json('data.version');

        $this->patchJson("{$this->url()}/{$other->id}", ['expected_version' => $version, 'data' => ['provider_name' => 'X']])->assertConflict();
    }

    // ---- pharmacy lines and the fill link --------------------------------------------------

    public function test_pharmacy_lines_write_fills_linked_to_their_lines_and_a_repeat_adds_nothing(): void
    {
        $document = $this->document();
        $claim = $this->pharmacyClaim($document);
        $created = $this->putJson($this->url(), $claim)
            ->assertCreated()
            ->assertJsonPath('fills.0.outcome', 'created')
            ->assertJsonPath('fills.0.dedupe.status', 'none')
            ->assertJsonPath('fills.1.outcome', 'error')
            ->json();
        // The second line's fill was refused (no drug); the claim and the first fill stand.
        $this->assertArrayHasKey('errors', $created['fills'][1]);
        $fillId = $created['fills'][0]['fill_id'];
        $lineId = $created['fills'][0]['line_id'];

        $fill = PhrMedicationFill::query()->findOrFail($fillId);
        $this->assertSame('eob', $fill->source_kind);
        $this->assertSame(PhrReviewStatus::PENDING, $fill->review_status);
        $this->assertSame('2026-10-28', $fill->filled_on->toDateString(), 'the fill date defaults from the line');
        $this->assertSame('Example Pharmacy', $fill->pharmacy_name, 'the pharmacy defaults from the claim');
        $this->assertSame($document->id, $fill->source_document_id);

        // Reads: the fill, the EOB line and evidence.links all show the link.
        $this->getJson("/api/v1/patients/{$this->patient->id}/medication-fills/{$fillId}")->assertJsonPath('data.eob_line_ids', [$lineId]);
        $this->getJson("/api/v1/patients/{$this->patient->id}/eobs/{$created['data']['id']}/lines/{$lineId}")
            ->assertOk()->assertJsonPath('data.medication_fill_ids', [$fillId]);
        $this->getJson("/api/v1/patients/{$this->patient->id}/eobs/{$created['data']['id']}/lines")
            ->assertOk()->assertJsonPath('data.0.medication_fill_ids', [$fillId])->assertJsonPath('data.1.medication_fill_ids', []);
        $this->getJson($this->links('eob-line', $lineId))->assertOk()
            ->assertJsonPath('data', [['target_type' => 'eob', 'target_id' => $created['data']['id']], ['target_type' => 'medication-fill', 'target_id' => $fillId]]);
        $this->getJson($this->links('medication-fill', $fillId))->assertOk()
            ->assertJsonFragment(['target_type' => 'eob-line', 'target_id' => $lineId])
            ->assertJsonFragment(['target_type' => 'document', 'target_id' => $document->id]);
        $this->getJson($this->links('eob', $created['data']['id']))->assertOk()
            ->assertJsonFragment(['target_type' => 'medication-fill', 'target_id' => $fillId]);
        $this->actingWith([AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE, AgentApiScopes::DOCUMENTS_READ]);
        $this->getJson($this->links('document', $document->id))->assertOk()
            ->assertJsonFragment(['target_type' => 'medication-fill', 'target_id' => $fillId])
            ->assertJsonFragment(['target_type' => 'eob', 'target_id' => $created['data']['id']]);

        $this->putJson($this->url(), $claim)->assertOk()->assertJsonPath('outcome', 'unchanged')->assertJsonPath('fills.0.fill_id', $fillId)->assertJsonPath('fills.0.outcome', 'unchanged');
        $this->assertSame(1, PhrMedicationFill::query()->count());
        $this->assertSame(1, PhrEob::query()->count());
    }

    // ---- eobs.import ----------------------------------------------------------------------

    public function test_a_meritain_pdf_is_imported_idempotently_and_pending_review(): void
    {
        $this->app->instance(EobPdfTextExtractor::class, new class extends EobPdfTextExtractor
        {
            public function extract(string $path): string
            {
                return <<<'EOB'
EMPLOYEE BENEFIT PLAN                         Print Date:05-03-22
 PROCEDURE                                                                                                                               OTHER     PYMT          PATIENT
 / REVENUE       DATES OF SERVICE          TOTAL         PROVIDER INELIGIBLE NOTES APPLIED                      APPLIED       BEN.      CARRIER'S MADE BY        RESPON-
    CODE                                  CHARGES        DISCOUNT AMOUNT           TO DED.                     TO COPAY        %          PYMT     PLAN          SIBILITY
70486            04-25-22                     361.00         173.13                     a                                        100                    187.87        0.00
                          TOTALS              361.00         173.13                                                                                     187.87        0.00
                                                                                                                          Claim No:      F07CI49
Processed Under Medical                                                                                                  Participant:    SYNTHETIC PARTICIPANT
Plan For Services Provided By                              Check #                          Amount                            ID No:     0000000001-1
EXAMPLE PROVIDER                                           74371185                         $187.87                        Address:      1 EXAMPLE ST
                                                                                                                    Processed On:        05-03-22       By: SY3
Patient:      SYNTHETIC PATIENT
Group Name:        EXAMPLE GROUP
Group No:       00000.001
EOB;
            }
        });
        $document = $this->storedDocument('eob.pdf', 'application/pdf', "%PDF-1.4\nsynthetic");

        $first = $this->postJson("{$this->url()}/import", ['document_id' => $document->id])
            ->assertCreated()
            ->assertJsonPath('resource_type', 'eob_import')
            ->assertJsonPath('outcome', 'created')
            ->assertJsonPath('data.format', 'meritain')
            ->assertJsonPath('data.eobs.0.lines_count', 1)
            ->json();
        $eob = PhrEob::query()->sole();
        $this->assertSame($first['data']['eobs'][0]['id'], $eob->id);
        $this->assertSame('meritain_eob', $eob->import_source);
        $this->assertSame($document->id, $eob->source_document_id);
        $this->assertSame(PhrReviewStatus::PENDING, $eob->review_status);

        $this->postJson("{$this->url()}/import", ['document_id' => $document->id])
            ->assertOk()->assertJsonPath('outcome', 'unchanged')->assertJsonPath('data.eobs.0.id', $eob->id);
        $this->assertSame(1, PhrEob::query()->count());
        $this->assertSame(1, PhrEobLine::query()->count());

        // The same claim read again from different bytes (a reprint) is still the same claim.
        $reprint = $this->storedDocument('eob-reprint.pdf', 'application/pdf', "%PDF-1.4\nreprint");
        $this->postJson("{$this->url()}/import", ['document_id' => $reprint->id])->assertOk()->assertJsonPath('outcome', 'unchanged');
        $this->assertSame(1, PhrEob::query()->count());
    }

    public function test_a_delta_dental_csv_with_quoted_headings_is_recognised(): void
    {
        $header = ['Claim Number', 'Date of Service', 'Submission Date', 'Member Name', 'Dentist', 'Phone', 'Procedure', 'Procedure Code', 'Accepted Fee', 'Claim Deductible', 'Delta Dental Pays', 'You Pay'];
        $stream = fopen('php://memory', 'r+');
        fputcsv($stream, $header, ',', '"', '');
        fputcsv($stream, ['20300102000009', '2030-01-02', '2030-01-03', 'Synthetic Patient', 'EXAMPLE DENTIST', '(555) 010-0200', 'Periodic oral evaluation established patient', 'D0120', '100.00', '0.00', '80.00', '20.00'], ',', '"', '');
        rewind($stream);
        $document = $this->storedDocument('claims.txt', 'text/plain', (string) stream_get_contents($stream));

        $this->postJson("{$this->url()}/import", ['document_id' => $document->id])
            ->assertCreated()->assertJsonPath('data.format', 'delta_dental');
    }

    public function test_a_delta_dental_csv_is_imported_and_another_source_holding_it_is_unchanged(): void
    {
        $csv = <<<'CSV'
Claim Number,Date of Service,Submission Date,Member Name,Dentist,Phone,Procedure,Procedure Code,Accepted Fee,Claim Deductible,Delta Dental Pays,You Pay
20300102000001,2030-01-02,2030-01-03,Synthetic Patient,EXAMPLE DENTIST,(555) 010-0200,Periodic oral evaluation established patient,D0120,100.00,0.00,80.00,20.00
20300102000001,2030-01-02,2030-01-03,Synthetic Patient,EXAMPLE DENTIST,(555) 010-0200,Prophylaxis (cleaning) adult,D1110,75.00,5.00,70.00,0.00
CSV;
        $document = $this->storedDocument('claims.txt', 'text/plain', $csv);

        $this->postJson("{$this->url()}/import", ['document_id' => $document->id])
            ->assertCreated()
            ->assertJsonPath('data.format', 'delta_dental')
            ->assertJsonPath('data.eobs.0.lines_count', 2);
        $eob = PhrEob::query()->sole();
        $this->assertSame('delta_dental_eob', $eob->import_source);
        $this->assertSame(PhrReviewStatus::PENDING, $eob->review_status);
        $this->assertSame('150.00', $eob->total_carrier_payment);

        // An agent reading the same claim itself (same content) does not add a second copy.
        $this->putJson($this->url(), [
            'source_document_id' => $document->id,
            'data' => [
                'claim_type' => 'dental', 'claim_number' => '20300102000001', 'submission_date' => '2030-01-03', 'patient_name' => 'Synthetic Patient',
                'provider_name' => 'EXAMPLE DENTIST', 'provider_phone' => '(555) 010-0200',
                'lines' => [
                    ['procedure_code' => 'D0120', 'description' => 'Periodic oral evaluation established patient', 'service_start' => '2030-01-02', 'accepted_fee' => '100.00', 'deductible_applied' => '0.00', 'carrier_payment' => '80.00', 'plan_payment' => '80.00', 'patient_responsibility' => '20.00'],
                    ['procedure_code' => 'D1110', 'description' => 'Prophylaxis (cleaning) adult', 'service_start' => '2030-01-02', 'accepted_fee' => '75.00', 'deductible_applied' => '5.00', 'carrier_payment' => '70.00', 'plan_payment' => '70.00', 'patient_responsibility' => '0.00'],
                ],
            ],
        ])->assertOk()->assertJsonPath('outcome', 'unchanged')->assertJsonPath('data.id', $eob->id);
        $this->assertSame(1, PhrEob::query()->count());
    }

    public function test_an_unrecognised_document_says_so_and_writes_nothing(): void
    {
        $this->app->instance(EobPdfTextExtractor::class, new class extends EobPdfTextExtractor
        {
            public function extract(string $path): string
            {
                return "A letter that is not an explanation of benefits.\n";
            }
        });
        foreach ([['letter.pdf', 'application/pdf', "%PDF-1.4\nletter"], ['note.txt', 'text/plain', 'just words'], ['photo.png', 'image/png', "\x89PNG\r\n"]] as [$name, $type, $bytes]) {
            $document = $this->storedDocument($name, $type, $bytes);
            $this->postJson("{$this->url()}/import", ['document_id' => $document->id])
                ->assertOk()
                ->assertJsonPath('outcome', 'unrecognised_format')
                ->assertJsonPath('data.format', null)
                ->assertJsonPath('data.eobs', [])
                ->assertJsonPath('data.next_step', fn (string $step): bool => str_contains($step, 'eobs.upsert'));
        }
        $this->assertSame(0, PhrEob::query()->count());
        // A document with no stored file cannot be imported, and another patient's is not found.
        $this->postJson("{$this->url()}/import", ['document_id' => $this->document()->id])->assertUnprocessable();
        $foreign = $this->document(PhrPatient::query()->create(['owner_user_id' => User::factory()->create()->id, 'display_name' => 'Synthetic Other', 'relationship' => 'self']));
        $this->postJson("{$this->url()}/import", ['document_id' => $foreign->id])->assertNotFound();
    }

    public function test_the_eob_writes_need_clinical_write(): void
    {
        $this->actingWith([AgentApiScopes::CLINICAL_READ]);
        $document = $this->document();
        $this->putJson($this->url(), $this->claim($document))->assertForbidden();
        $this->postJson("{$this->url()}/import", ['document_id' => $document->id])->assertForbidden();
        $this->patchJson("{$this->url()}/1", ['expected_version' => str_repeat('0', 64), 'data' => []])->assertForbidden();
    }

    // ---- helpers --------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function claim(PhrDocument $document): array
    {
        return [
            'source_document_id' => $document->id,
            'data' => [
                'claim_type' => 'medical',
                'claim_number' => 'CLM-100',
                'carrier' => 'Synthetic Plan',
                'provider_name' => 'Example Provider',
                'processed_date' => '2026-11-02',
                'lines' => [
                    ['procedure_code' => '99213', 'service_start' => '2026-10-28', 'total_charges' => '100.00', 'plan_payment' => '50.00', 'patient_responsibility' => '20.00'],
                    ['procedure_code' => '80048', 'service_start' => '2026-10-28', 'total_charges' => '60.00', 'plan_payment' => '30.00', 'patient_responsibility' => '10.00'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function pharmacyClaim(PhrDocument $document): array
    {
        return [
            'source_document_id' => $document->id,
            'data' => [
                'claim_type' => 'pharmacy',
                'claim_number' => 'RX-200',
                'provider_name' => 'Example Pharmacy',
                'processed_date' => '2026-11-02',
                'lines' => [
                    [
                        'procedure_code' => '5815101577', 'description' => 'atorvastatin 10 mg tablet', 'service_start' => '2026-10-28',
                        'plan_payment' => '307.49', 'patient_responsibility' => '12.50',
                        'fill' => ['ndc' => '58151-0155-77', 'quantity' => 90, 'quantity_unit' => 'tablet', 'rx_number' => '1234567', 'paid_cents' => 1250],
                    ],
                    [
                        'procedure_code' => '9999999999', 'service_start' => '2026-10-28', 'plan_payment' => '5.00',
                        'fill' => ['quantity' => 30],
                    ],
                ],
            ],
        ];
    }

    private function links(string $type, int $id): string
    {
        return "/api/v1/patients/{$this->patient->id}/evidence-links?".http_build_query(['resource_type' => $type, 'resource_id' => $id]);
    }

    private function url(): string
    {
        return "/api/v1/patients/{$this->patient->id}/eobs";
    }

    /** @param list<string> $scopes */
    private function actingWith(array $scopes): void
    {
        Passport::actingAs($this->actor, $scopes, 'api', $this->client);
    }

    private function storedDocument(string $name, string $type, string $bytes): PhrDocument
    {
        $path = 'patients/'.$this->patient->id.'/documents/'.md5($name.$bytes).'/'.$name;
        Storage::disk(PhrDocument::STORAGE_DISK)->put($path, $bytes);

        return PhrDocument::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'uploaded_by_user_id' => $this->actor->id,
            'document_type' => 'insurance', 'source' => 'agent_upload', 'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => $path, 'original_filename' => $name, 'mime_type' => $type, 'byte_size' => strlen($bytes), 'file_hash' => hash('sha256', $bytes),
        ]);
    }

    private function document(?PhrPatient $patient = null): PhrDocument
    {
        return PhrDocument::query()->create([
            'patient_id' => ($patient ?? $this->patient)->id, 'user_id' => $this->actor->id, 'uploaded_by_user_id' => $this->actor->id,
            'document_type' => 'other', 'source' => 'manual_upload', 'storage_disk' => PhrDocument::STORAGE_DISK, 'byte_size' => 0,
        ]);
    }

    private function makeClient(string $name): Client
    {
        return Client::query()->create([
            'name' => $name, 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
    }
}

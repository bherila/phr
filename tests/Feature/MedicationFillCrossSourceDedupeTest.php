<?php

namespace Tests\Feature;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrMedicationFill;
use App\Models\PhrMedicationFillEvidence;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Fills\MedicationFillExternalId;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\Support\FakesOpenFda;
use Tests\TestCase;

/**
 * The same dispensing read from a label, a receipt, a statement and an EOB line is one
 * fill. Sources are different OAuth clients here, so the only thing joining them is the
 * fills write path's cross-source match, not a shared external_id.
 */
final class MedicationFillCrossSourceDedupeTest extends TestCase
{
    use ConfiguresPassportKeys;
    use FakesOpenFda;
    use RefreshDatabase;

    private User $actor;

    private PhrPatient $patient;

    /** @var array<string, Client> */
    private array $clients = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Cache::flush();
        $this->actor = User::factory()->create(['user_role' => 'user']);
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->actor->id, 'display_name' => 'Synthetic Dedupe Patient', 'relationship' => 'self']);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $this->actor->id, 'granted_at' => now(),
        ]);
        $this->fakeOpenFda();
    }

    public function test_a_label_a_receipt_and_an_eob_line_with_an_rx_number_are_one_fill(): void
    {
        $label = $this->document();
        $receipt = $this->document();
        $eob = $this->document();

        $first = $this->fill('label', $label, ['ndc' => '58151-0155-77', 'source_kind' => 'label_photo', 'paid_cents' => null, 'retail_cents' => null])
            ->assertCreated()
            ->assertJsonPath('dedupe.status', 'none')
            ->json();
        $fillId = $first['data']['id'];

        // The receipt (another client) shows the same Rx at the same pharmacy on the same day, and the price.
        $this->fill('receipt', $receipt, ['ndc' => '58151-0155-77', 'source_kind' => 'receipt_pdf', 'pharmacy_name' => 'EXAMPLE PHARMACY #4', 'paid_cents' => 1250, 'retail_cents' => 31999])
            ->assertOk()
            ->assertJsonPath('outcome', 'updated')
            ->assertJsonPath('dedupe.status', 'matched')
            ->assertJsonPath('dedupe.matched_by', 'rx_number')
            ->assertJsonPath('dedupe.matched_fill_id', $fillId)
            ->assertJsonPath('data.id', $fillId)
            ->assertJsonPath('data.paid_cents', 1250)
            ->assertJsonPath('data.evidence_document_ids', [$receipt->id]);

        // The EOB lines (a third client) adjudicate it, and the line is linked.
        $this->actingAsClient('insurer');
        $claim = $this->putJson($this->eobUrl(), $this->claim($eob, [['ndc' => '58151-0155-77', 'rx_number' => '1234567', 'quantity' => 90, 'pharmacy_name' => 'Example Pharmacy']]))
            ->assertCreated()
            ->assertJsonPath('fills.0.fill_id', $fillId)
            ->assertJsonPath('fills.0.dedupe.status', 'matched')
            ->json();

        $this->assertSame(1, PhrMedicationFill::query()->count(), 'three sources, one fill');
        $lineId = $claim['lines'][0]['id'];
        $this->assertSame([$lineId], $this->readFill($fillId)['eob_line_ids']);
        $this->assertEqualsCanonicalizing([$receipt->id, $eob->id], $this->readFill($fillId)['evidence_document_ids']);
        $this->assertSame($label->id, $this->readFill($fillId)['source_document_id'], 'the first source stays the provenance');
        $this->assertSame([], PhrMedicationFill::query()->findOrFail($fillId)->review_flags ?? []);

        // Reading the same EOB again changes nothing.
        $this->putJson($this->eobUrl(), $this->claim($eob, [['ndc' => '58151-0155-77', 'rx_number' => '1234567', 'quantity' => 90, 'pharmacy_name' => 'Example Pharmacy']]))
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('fills.0.fill_id', $fillId);
        $this->assertSame(1, PhrMedicationFill::query()->count());
        $this->assertSame(2, PhrMedicationFillEvidence::query()->where('source_kind', '!=', 'alias')->count(), 'one document and one EOB line, once each');
    }

    public function test_without_an_rx_number_the_fill_date_drug_pharmacy_and_quantity_match(): void
    {
        $label = $this->document();
        $receipt = $this->document();
        $base = ['ndc' => '58151-0155-77', 'rx_number' => null, 'quantity' => 30, 'pharmacy_name' => 'Example Pharmacy', 'paid_cents' => null];

        $fillId = $this->fill('label', $label, [...$base, 'source_kind' => 'label_photo'])->assertCreated()->json('data.id');
        $this->fill('receipt', $receipt, [...$base, 'source_kind' => 'receipt_pdf', 'paid_cents' => 900])
            ->assertOk()
            ->assertJsonPath('dedupe.status', 'matched')
            ->assertJsonPath('dedupe.matched_by', 'attributes')
            ->assertJsonPath('data.id', $fillId)
            ->assertJsonPath('data.paid_cents', 900);
        $this->assertSame(1, PhrMedicationFill::query()->count());
        $this->assertSame(PhrReviewStatus::PENDING, PhrMedicationFill::query()->sole()->review_status);
    }

    public function test_a_second_source_never_overwrites_what_the_fill_already_says(): void
    {
        $label = $this->document();
        $receipt = $this->document();

        $this->fill('label', $label, ['ndc' => '58151-0155-77', 'paid_cents' => 1250, 'source_kind' => 'label_photo'])->assertCreated();
        $this->fill('receipt', $receipt, ['ndc' => '58151-0155-77', 'paid_cents' => 9999, 'source_kind' => 'receipt_pdf'])
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('data.paid_cents', 1250);
        $this->assertSame(1, PhrMedicationFill::query()->count());
    }

    public function test_an_uncertain_match_is_flagged_for_review_and_not_silently_merged_or_duplicated(): void
    {
        $label = $this->document();
        $receipt = $this->document();
        $base = ['ndc' => '58151-0155-77', 'rx_number' => null, 'quantity' => 30];

        $existing = $this->fill('label', $label, [...$base, 'pharmacy_name' => null, 'source_kind' => 'label_photo'])->assertCreated()->json('data.id');
        // Same day and drug, but the pharmacy is unknown on one side: it may or may not be the same fill.
        $flagged = $this->fill('receipt', $receipt, [...$base, 'pharmacy_name' => 'Example Pharmacy', 'source_kind' => 'receipt_pdf'])
            ->assertCreated()
            ->assertJsonPath('dedupe.status', 'flagged')
            ->assertJsonPath('dedupe.matched_fill_id', $existing)
            ->assertJsonPath('data.review_status', PhrReviewStatus::PENDING)
            ->assertJsonPath('data.review_flags.0.code', 'possible_duplicate')
            ->assertJsonPath('data.review_flags.0.fill_id', $existing)
            ->json();
        $this->assertNotSame($existing, $flagged['data']['id']);
        $this->assertSame(2, PhrMedicationFill::query()->count());

        // The same request again is a retry of the flagged fill, not a third fill.
        $this->fill('receipt', $receipt, [...$base, 'pharmacy_name' => 'Example Pharmacy', 'source_kind' => 'receipt_pdf'])
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('data.id', $flagged['data']['id']);
        $this->assertSame(2, PhrMedicationFill::query()->count());
    }

    public function test_a_second_prescription_the_same_day_is_a_different_fill(): void
    {
        $label = $this->document();
        $other = $this->document();

        $this->fill('label', $label, ['ndc' => '58151-0155-77', 'rx_number' => '1111111', 'source_kind' => 'label_photo'])->assertCreated();
        // A different Rx number and a different drug: two fills, nothing flagged.
        $this->fill('label', $other, ['ndc' => '70518-1906-1', 'rx_number' => '2222222', 'source_kind' => 'label_photo'])
            ->assertCreated()
            ->assertJsonPath('dedupe.status', 'none')
            ->assertJsonPath('data.review_flags', []);
        $this->assertSame(2, PhrMedicationFill::query()->count());
    }

    public function test_a_partial_name_overlap_is_not_the_same_drug(): void
    {
        $base = ['ndc' => null, 'rx_number' => null, 'quantity' => 30, 'pharmacy_name' => 'Example Pharmacy', 'source_kind' => 'receipt_pdf'];
        $this->fill('receipt', $this->document(), [...$base, 'drug_as_printed' => 'Levothyroxine sodium 50 mcg tablet'])->assertCreated();
        // Both say "sodium" and "50 mcg", but they are different drugs: neither merged nor flagged.
        $this->fill('statement', $this->document(), [...$base, 'drug_as_printed' => 'Liothyronine sodium 50 mcg tablet', 'source_kind' => 'statement'])
            ->assertCreated()
            ->assertJsonPath('dedupe.status', 'none');
        // The same drug named more fully on the other source is still the same fill.
        $this->fill('label', $this->document(), [...$base, 'drug_as_printed' => 'Levothyroxine 50 mcg', 'source_kind' => 'label_photo'])
            ->assertOk()
            ->assertJsonPath('dedupe.status', 'matched');
        $this->assertSame(2, PhrMedicationFill::query()->count());
    }

    public function test_an_identity_merged_into_another_fill_stays_there_and_is_not_recreated_after_a_delete(): void
    {
        $label = $this->document();
        $receipt = $this->document();
        $fillId = $this->fill('label', $label, ['ndc' => '58151-0155-77', 'rx_number' => null, 'source_kind' => 'label_photo'])->assertCreated()->json('data.id');
        $payload = ['ndc' => '58151-0155-77', 'rx_number' => null, 'source_kind' => 'receipt_pdf'];
        $first = $this->fill('receipt', $receipt, $payload)->assertOk()->assertJsonPath('dedupe.status', 'matched');
        $externalId = (string) $this->resolveExternalId($receipt);

        // The receipt connection's own identity resolves to that fill, and a retry lands there again.
        $this->actingAsClient('receipt');
        $this->postJson("/api/v1/patients/{$this->patient->id}/medication-fills/resolve", ['external_ids' => [$externalId]])
            ->assertOk()->assertJsonPath("resolved.{$externalId}.id", $fillId);
        $this->fill('receipt', $receipt, $payload)->assertOk()->assertJsonPath('dedupe.matched_by', 'external_id')->assertJsonPath('data.id', $fillId);

        // A person deletes the fill: the retry must not bring it back.
        PhrMedicationFill::query()->findOrFail($fillId)->delete();
        $this->fill('receipt', $receipt, $payload)->assertConflict();
        $this->assertSame(0, PhrMedicationFill::query()->count());
        $this->assertNotNull($first->json('data.id'));
    }

    public function test_another_day_or_another_patient_is_never_a_match(): void
    {
        $label = $this->document();
        $this->fill('label', $label, ['ndc' => '58151-0155-77', 'rx_number' => null, 'source_kind' => 'label_photo'])->assertCreated();

        $this->fill('receipt', $this->document(), ['ndc' => '58151-0155-77', 'rx_number' => null, 'filled_on' => '2026-11-27', 'source_kind' => 'receipt_pdf'])
            ->assertCreated()->assertJsonPath('dedupe.status', 'none');
        $this->assertSame(2, PhrMedicationFill::query()->count());
    }

    public function test_an_eob_line_links_to_an_existing_fill_through_eob_line_id(): void
    {
        $label = $this->document();
        $eob = $this->document();
        $fillId = $this->fill('label', $label, ['ndc' => '58151-0155-77', 'source_kind' => 'label_photo'])->assertCreated()->json('data.id');

        $this->actingAsClient('insurer');
        $claim = $this->putJson($this->eobUrl(), $this->claim($eob, [[]]))->assertCreated()->json();
        $lineId = $claim['lines'][0]['id'];
        $this->assertSame([], $this->readFill($fillId)['eob_line_ids']);

        // The agent links the fill to the line after reading the EOB (e.g. after eobs.import).
        $this->actingAsClient('label');
        $this->fill('label', $label, ['ndc' => '58151-0155-77', 'source_kind' => 'label_photo', 'eob_line_id' => $lineId], 'label')
            ->assertOk()
            ->assertJsonPath('data.eob_line_ids', [$lineId]);
        $this->assertSame(1, PhrMedicationFill::query()->count());

        // A line of another patient's claim is refused.
        $stranger = PhrPatient::query()->create(['owner_user_id' => User::factory()->create()->id, 'display_name' => 'Synthetic Stranger', 'relationship' => 'self']);
        $foreignEob = PhrEob::query()->create(['patient_id' => $stranger->id, 'user_id' => $stranger->owner_user_id, 'import_source' => 'x', 'external_id' => 'x', 'claim_type' => 'pharmacy']);
        $foreign = PhrEobLine::query()->create(['eob_id' => $foreignEob->id, 'patient_id' => $stranger->id, 'line_number' => 1, 'procedure_code' => 'X']);
        $this->fill('label', $label, ['ndc' => '58151-0155-77', 'source_kind' => 'label_photo', 'eob_line_id' => $foreign->id], 'label')
            ->assertUnprocessable();
    }

    // ---- helpers --------------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    private function fill(string $client, PhrDocument $document, array $data, ?string $as = null): TestResponse
    {
        $this->actingAsClient($as ?? $client);

        return $this->putJson("/api/v1/patients/{$this->patient->id}/medication-fills", [
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
                ...array_filter($data, static fn (mixed $value, string $key): bool => $value !== null || in_array($key, ['paid_cents', 'rx_number', 'pharmacy_name', 'retail_cents'], true), ARRAY_FILTER_USE_BOTH),
            ],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $fills  one pharmacy line per entry; an empty entry is a line with no fill
     * @return array<string, mixed>
     */
    private function claim(PhrDocument $document, array $fills): array
    {
        return [
            'source_document_id' => $document->id,
            'data' => [
                'claim_type' => 'pharmacy',
                'claim_number' => 'RXCLAIM1',
                'carrier' => 'Synthetic Plan',
                'provider_name' => 'Example Pharmacy',
                'patient_name' => 'Synthetic Dedupe Patient',
                'processed_date' => '2026-11-02',
                'lines' => array_map(static fn (array $fill, int $index): array => array_filter([
                    'line_number' => $index + 1,
                    'procedure_code' => '5815101577',
                    'description' => 'atorvastatin 10 mg tablet',
                    'service_start' => '2026-10-28',
                    'total_charges' => '319.99',
                    'plan_payment' => '307.49',
                    'patient_responsibility' => '12.50',
                    'fill' => $fill === [] ? null : $fill,
                ], static fn (mixed $value): bool => $value !== null), $fills, array_keys($fills)),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function readFill(int $id): array
    {
        $this->actingAsClient('insurer');

        return $this->getJson("/api/v1/patients/{$this->patient->id}/medication-fills/{$id}")->assertOk()->json('data');
    }

    /** The external id the server derived for a document that carries no Rx number (what a retry re-sends). */
    private function resolveExternalId(PhrDocument $document): string
    {
        return MedicationFillExternalId::derive(['source_locator' => null], $document->id) ?? '';
    }

    private function eobUrl(): string
    {
        return "/api/v1/patients/{$this->patient->id}/eobs";
    }

    private function actingAsClient(string $name): void
    {
        $this->clients[$name] ??= Client::query()->create([
            'name' => "Synthetic {$name}", 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        Passport::actingAs($this->actor, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $this->clients[$name]);
    }

    private function document(): PhrDocument
    {
        return PhrDocument::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'uploaded_by_user_id' => $this->actor->id,
            'document_type' => 'other', 'source' => 'manual_upload', 'storage_disk' => PhrDocument::STORAGE_DISK, 'byte_size' => 0,
        ]);
    }
}

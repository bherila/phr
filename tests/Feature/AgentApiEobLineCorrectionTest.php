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
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

final class AgentApiEobLineCorrectionTest extends TestCase
{
    use ConfiguresPassportKeys;

    private User $actor;

    private PhrPatient $patient;

    private PhrDocument $document;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Cache::flush();
        Storage::fake(PhrDocument::STORAGE_DISK);
        $this->actor = User::factory()->create(['user_role' => 'user']);
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->actor->id, 'display_name' => 'Synthetic Correction Patient', 'relationship' => 'self']);
        PhrPatientUserAccess::query()->create(['patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $this->actor->id, 'granted_at' => now()]);
        $client = Client::query()->create(['name' => 'Synthetic Correction Client', 'secret' => null, 'provider' => 'users', 'redirect_uris' => ['https://correction.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false]);
        Passport::actingAs($this->actor, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $this->document = PhrDocument::query()->create(['patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'uploaded_by_user_id' => $this->actor->id, 'document_type' => 'other', 'source' => 'manual_upload', 'storage_disk' => PhrDocument::STORAGE_DISK, 'byte_size' => 0]);
    }

    public static function writePaths(): iterable
    {
        yield 'versioned update, positional' => ['update', false];
        yield 'versioned update, explicit' => ['update', true];
        yield 'correcting upsert, positional' => ['upsert', false];
        yield 'correcting upsert, explicit' => ['upsert', true];
    }

    #[DataProvider('writePaths')]
    public function test_reordered_services_keep_the_original_line_and_fill_evidence(string $path, bool $explicit): void
    {
        $lines = $this->lines($explicit);
        $created = $this->createClaim($lines);
        $original = $created['lines'][0]['id'];
        $other = $created['lines'][1]['id'];
        [$fill, $evidence] = $this->link($original);
        $evidenceBefore = $evidence->refresh()->getAttributes();
        $reordered = array_reverse($lines);
        if ($explicit) {
            $reordered[0]['line_number'] = 1;
            $reordered[1]['line_number'] = 2;
        }
        $updated = $this->correct($path, $created, $reordered)->assertOk()->assertJsonPath('outcome', 'updated')->json();
        self::assertSame([$other, $original], array_column($updated['lines'], 'id'));
        self::assertSame($evidenceBefore, $evidence->refresh()->getAttributes(), 'Evidence retains its original service row and provenance.');
        $this->getJson($this->base().'/medication-fills/'.$fill->id)->assertOk()->assertJsonPath('data.eob_line_ids', [$original]);
        $this->getJson($this->base().'/eobs/'.$created['data']['id'].'/lines/'.$original)
            ->assertOk()->assertJsonPath('data.procedure_code', 'SYNTH-A')->assertJsonPath('data.medication_fill_ids', [$fill->id]);
        $this->getJson($this->base().'/eobs/'.$created['data']['id'])->assertOk()->assertJsonPath('data.lines_count', 2);
        $this->correct($path, $updated, $reordered)->assertOk()->assertJsonPath('outcome', 'unchanged');
        self::assertSame(2, PhrEobLine::query()->count());
        self::assertSame(1, PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)->count());
    }

    public static function refusedCorrections(): iterable
    {
        foreach (['update', 'upsert'] as $path) {
            yield $path.' removes linked service' => [$path, 'remove'];
            yield $path.' replaces linked procedure' => [$path, 'procedure'];
            yield $path.' ambiguous repeated services' => [$path, 'ambiguous'];
            yield $path.' repeated amount crossover' => [$path, 'crossover'];
            yield $path.' repeated amount correction' => [$path, 'repeated_amount'];
        }
    }

    #[DataProvider('refusedCorrections')]
    public function test_ambiguous_or_missing_linked_services_refuse_atomically(string $path, string $case): void
    {
        $lines = $this->lines(true);
        if (in_array($case, ['ambiguous', 'crossover', 'repeated_amount'], true)) {
            $lines[1]['procedure_code'] = $lines[0]['procedure_code'];
        }
        $created = $this->createClaim($lines);
        $this->link($created['lines'][0]['id']);
        $before = $this->persisted();
        if ($case === 'remove') {
            $lines = [$lines[1]];
        } elseif ($case === 'procedure') {
            $lines[0]['procedure_code'] = 'SYNTH-C';
        } elseif ($case === 'crossover') {
            $lines[0]['plan_payment'] = '20.00';
            $lines[1]['plan_payment'] = '30.00';
        } elseif ($case === 'repeated_amount') {
            $lines[0]['plan_payment'] = '30.00';
        } else {
            $lines[0]['plan_payment'] = '30.00';
            $lines[1]['plan_payment'] = '40.00';
        }
        $this->correct($path, $created, $lines)->assertConflict();
        self::assertSame($before, $this->persisted(), 'The header, all line rows and evidence survive a refused correction unchanged.');
    }

    public static function amountCorrections(): iterable
    {
        yield 'update unique' => ['update', false];
        yield 'upsert unique' => ['upsert', false];
    }

    #[DataProvider('amountCorrections')]
    public function test_amount_only_changes_keep_unambiguous_evidence(string $path, bool $repeated): void
    {
        $lines = $this->lines(true);
        if ($repeated) {
            $lines[1]['procedure_code'] = $lines[0]['procedure_code'];
        }
        $created = $this->createClaim($lines);
        [$fill] = $this->link($created['lines'][0]['id']);
        $lines[0]['plan_payment'] = '30.00';
        $updated = $this->correct($path, $created, $lines)->assertOk()->assertJsonPath('outcome', 'updated')->json();
        self::assertSame(array_column($created['lines'], 'id'), array_column($updated['lines'], 'id'));
        $this->getJson($this->base().'/medication-fills/'.$fill->id)->assertOk()->assertJsonPath('data.eob_line_ids', [$created['lines'][0]['id']]);
        self::assertSame('30.00', PhrEobLine::query()->findOrFail($created['lines'][0]['id'])->plan_payment);
    }

    public function test_another_patients_claim_cannot_be_corrected_or_its_evidence_changed(): void
    {
        $created = $this->createClaim($this->lines(true));
        $this->link($created['lines'][0]['id']);
        $before = $this->persisted();
        $other = PhrPatient::query()->create(['owner_user_id' => User::factory()->create()->id, 'display_name' => 'Synthetic Other', 'relationship' => 'self']);
        $this->patchJson('/api/v1/patients/'.$other->id.'/eobs/'.$created['data']['id'], ['expected_version' => $created['version'], 'data' => ['lines' => array_reverse($this->lines(true))]])->assertNotFound();
        self::assertSame($before, $this->persisted());
    }

    public function test_repeated_codes_on_distinct_dates_keep_their_service_identity(): void
    {
        $lines = $this->lines(false);
        $lines[1]['procedure_code'] = $lines[0]['procedure_code'];
        $lines[1]['service_start'] = '2026-10-29';
        $created = $this->createClaim($lines);
        [$fill] = $this->link($created['lines'][0]['id']);
        $updated = $this->correct('update', $created, array_reverse($lines))->assertOk()->json();
        self::assertSame(array_reverse(array_column($created['lines'], 'id')), array_column($updated['lines'], 'id'));
        $this->getJson($this->base().'/medication-fills/'.$fill->id)->assertOk()->assertJsonPath('data.eob_line_ids', [$created['lines'][0]['id']]);
    }

    public function test_indistinguishable_services_cannot_be_guessed_after_all_numbers_change(): void
    {
        $lines = $this->lines(true);
        $lines[1] = [...$lines[0], 'line_number' => 2];
        $created = $this->createClaim($lines);
        $this->link($created['lines'][0]['id']);
        $before = $this->persisted();
        $lines[0]['line_number'] = 10;
        $lines[1]['line_number'] = 20;
        $this->correct('update', $created, $lines)->assertConflict();
        self::assertSame($before, $this->persisted());
    }

    public function test_a_header_correction_preserves_identical_repeated_linked_lines(): void
    {
        $lines = $this->lines(true);
        $lines[1] = [...$lines[0], 'line_number' => 2];
        $created = $this->createClaim($lines);
        $this->link($created['lines'][0]['id']);
        $before = PhrEobLine::query()->orderBy('id')->get()->map->getAttributes()->all();
        $this->patchJson($this->base().'/eobs/'.$created['data']['id'], ['expected_version' => $created['version'], 'data' => ['provider_name' => 'Synthetic Corrected Pharmacy']])->assertOk();
        self::assertSame($before, PhrEobLine::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_an_unlinked_service_can_be_removed_without_losing_linked_evidence(): void
    {
        $lines = $this->lines(true);
        $created = $this->createClaim($lines);
        [$fill] = $this->link($created['lines'][0]['id']);
        $this->correct('update', $created, [$lines[0]])->assertOk()->assertJsonCount(1, 'lines');
        self::assertSame([$created['lines'][0]['id']], PhrEobLine::query()->pluck('id')->all());
        $this->getJson($this->base().'/medication-fills/'.$fill->id)->assertOk()->assertJsonPath('data.eob_line_ids', [$created['lines'][0]['id']]);
    }

    public function test_a_later_write_failure_rolls_back_staged_numbers_and_header_changes(): void
    {
        $lines = $this->lines(false);
        $created = $this->createClaim($lines);
        $this->link($created['lines'][0]['id']);
        $before = $this->persisted();
        $dispatcher = PhrEobLine::getEventDispatcher();
        PhrEobLine::setEventDispatcher(clone $dispatcher);
        $this->withoutExceptionHandling();
        try {
            PhrEobLine::updating(static function (PhrEobLine $line) use ($created): void {
                if ($line->id === $created['lines'][0]['id']) {
                    throw new \RuntimeException('Synthetic failure after the first moved line.');
                }
            });
            try {
                $this->correct('update', $created, array_reverse($lines));
                self::fail('The synthetic write failure must escape.');
            } catch (\RuntimeException $failure) {
                self::assertSame('Synthetic failure after the first moved line.', $failure->getMessage());
            }
            self::assertSame($before, $this->persisted());
        } finally {
            PhrEobLine::setEventDispatcher($dispatcher);
        }
    }

    public function test_obsolete_automatic_evidence_is_removed_before_replacing_a_service(): void
    {
        $lines = $this->lines(true);
        $created = $this->createClaim($lines);
        $fill = $this->fill();
        $old = PhrMedicationFillEvidence::query()->firstOrCreate([
            'fill_id' => $fill->id, 'eob_line_id' => $created['lines'][0]['id'],
            'source_kind' => PhrMedicationFillEvidence::KIND_AUTO_EOB_LINK,
        ], ['patient_id' => $this->patient->id, 'source_locator' => 'synthetic inferred service', 'evidence_key' => substr(hash('sha256', 'synthetic-auto'), 0, 40)]);
        $lines[0]['procedure_code'] = 'SYNTH-C';
        $this->correct('update', $created, $lines)->assertOk();
        self::assertFalse(PhrMedicationFillEvidence::query()->whereKey($old->id)->exists(), 'The original inference was invalidated, rather than silently retargeted.');
        self::assertSame('SYNTH-C', PhrEobLine::query()->findOrFail($created['lines'][0]['id'])->procedure_code);
    }

    /** @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function createClaim(array $lines): array
    {
        return $this->putJson($this->base().'/eobs', ['source_document_id' => $this->document->id, 'external_id' => 'synthetic-correction', 'data' => $this->claim($lines)])->assertCreated()->json();
    }

    /** @param array<string, mixed> $previous
     * @param  list<array<string, mixed>>  $lines
     */
    private function correct(string $path, array $previous, array $lines): TestResponse
    {
        return $path === 'update'
            ? $this->patchJson($this->base().'/eobs/'.$previous['data']['id'], ['expected_version' => $previous['version'], 'data' => ['lines' => $lines]])
            : $this->putJson($this->base().'/eobs', ['source_document_id' => $this->document->id, 'external_id' => 'synthetic-correction', 'expected_version' => $previous['version'], 'data' => $this->claim($lines)]);
    }

    /** @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function claim(array $lines): array
    {
        return ['claim_type' => 'pharmacy', 'claim_number' => 'SYNTH-CORRECTION', 'provider_name' => 'Synthetic Pharmacy', 'processed_date' => '2026-11-02', 'lines' => $lines];
    }

    /** @return list<array<string, mixed>> */
    private function lines(bool $explicit): array
    {
        return array_map(static fn (string $code, int $index): array => [
            ...($explicit ? ['line_number' => $index + 1] : []), 'procedure_code' => $code,
            'description' => 'Synthetic service', 'service_start' => '2026-10-28', 'plan_payment' => ($index + 1).'0.00',
        ], ['SYNTH-A', 'SYNTH-B'], [0, 1]);
    }

    /** @return array{PhrMedicationFill, PhrMedicationFillEvidence} */
    private function link(int $lineId): array
    {
        $fill = $this->fill();
        $evidence = PhrMedicationFillEvidence::query()->create(['patient_id' => $this->patient->id, 'fill_id' => $fill->id, 'document_id' => $this->document->id, 'eob_line_id' => $lineId, 'source_kind' => 'eob', 'source_locator' => 'synthetic original service', 'evidence_key' => substr(hash('sha256', 'synthetic-evidence'), 0, 40)]);

        return [$fill, $evidence];
    }

    private function fill(): PhrMedicationFill
    {
        return PhrMedicationFill::query()->create(['patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'filled_on' => '2026-10-28', 'quantity' => '1', 'ndc_status' => PhrMedicationFill::NDC_MANUAL, 'drug_as_printed' => 'Synthetic drug', 'review_status' => PhrReviewStatus::PENDING, 'source_kind' => 'manual']);
    }

    /** @return array<string, mixed> */
    private function persisted(): array
    {
        return ['eobs' => PhrEob::query()->orderBy('id')->get()->map->getAttributes()->all(), 'lines' => PhrEobLine::query()->orderBy('id')->get()->map->getAttributes()->all(), 'evidence' => PhrMedicationFillEvidence::query()->orderBy('id')->get()->map->getAttributes()->all()];
    }

    private function base(): string
    {
        return '/api/v1/patients/'.$this->patient->id;
    }
}

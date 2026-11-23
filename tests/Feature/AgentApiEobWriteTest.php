<?php

namespace Tests\Feature;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrMedicationFill;
use App\Models\PhrNativeRestoreAttempt;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Eob\EobClaim;
use App\Services\PHR\Eob\EobCorrectionHistory;
use App\Services\PHR\Import\EobPdfTextExtractor;
use App\Services\PHR\NativeBackup\NativeRestoreException;
use App\Services\PHR\NativeBackup\PhrNativeArchiveBuilder;
use App\Services\PHR\NativeBackup\PhrNativeBackupCatalog;
use App\Services\PHR\NativeBackup\PhrNativeEobCorrectionCompatibility;
use App\Services\PHR\NativeBackup\PhrNativeIdentityRepository;
use App\Services\PHR\NativeBackup\PhrNativeRecordCodec;
use App\Services\PHR\NativeBackup\PhrNativeRestoreArchiveReader;
use App\Services\PHR\NativeBackup\PhrNativeRestoreService;
use App\Services\PHR\NativeBackup\PhrNativeTableCompatibility;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\Support\FakesOpenFda;
use Tests\TestCase;
use ZipArchive;

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

    public function test_an_imported_eob_can_be_corrected_with_attribution_and_prior_values(): void
    {
        $created = $this->putJson($this->url(), $this->claim($this->document()))->assertCreated()->json();
        $eob = PhrEob::query()->findOrFail($created['data']['id']);
        $eob->update([
            'import_source' => 'delta_dental_eob', 'review_status' => PhrReviewStatus::CONFIRMED,
            'parsed_data' => ['source_csv' => 'claims.csv'],
        ]);
        $version = $this->getJson("{$this->url()}/{$eob->id}")->assertOk()->json('data.version');
        $lines = $this->claim($this->document())['data']['lines'];
        $lines[0]['total_charges'] = '55.00';
        $patch = ['expected_version' => $version, 'data' => ['lines' => $lines]];
        $this->actingWith([AgentApiScopes::CLINICAL_READ]);
        $this->patchJson("{$this->url()}/{$eob->id}", $patch)->assertForbidden();
        $this->actingWith([AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE]);
        $otherPatient = PhrPatient::query()->create(['owner_user_id' => $this->actor->id, 'display_name' => 'Other patient']);
        $this->patchJson("/api/v1/patients/{$otherPatient->id}/eobs/{$eob->id}", $patch)->assertNotFound();
        $this->patchJson("{$this->url()}/{$eob->id}", [...$patch, 'expected_version' => $created['version']])->assertConflict();
        $this->assertArrayNotHasKey('corrections', $eob->refresh()->parsed_data);

        $updated = $this->patchJson("{$this->url()}/{$eob->id}", $patch)->assertOk()
            ->assertJsonPath('data.total_charges', '115.00')
            ->assertJsonPath('data.review_status', PhrReviewStatus::PENDING)->json();
        $this->assertNotSame($version, $updated['version']);
        $eob->refresh();
        $this->assertSame('delta_dental_eob', $eob->import_source);
        $this->assertSame('claims.csv', $eob->parsed_data['source_csv']);
        $history = app(EobCorrectionHistory::class)->forEob($eob);
        $this->assertCount(1, $history);
        $this->assertSame(['kind' => 'native_actor', 'native_id' => app(PhrNativeIdentityRepository::class)->existingNativeId($eob->patient_id, 'users', $this->actor->id)], $history[0]['actor']);
        $this->assertSame($this->client->id, $history[0]['oauth_client']['reference']);
        $this->assertNotEmpty($history[0]['corrected_at']);
        $this->assertSame($version, $history[0]['previous_version']);
        $this->assertSame('160.00', $history[0]['before']['total_charges']);
        $this->assertSame('100.00', $history[0]['before']['lines'][0]['total_charges']);
        $this->assertSame('55.00', $history[0]['after']['lines'][0]['total_charges']);
        $this->assertSame('115.00', $history[0]['after']['total_charges']);
        $this->patchJson("{$this->url()}/{$eob->id}", $patch)->assertConflict();
        $this->patchJson("{$this->url()}/{$eob->id}", [...$patch, 'expected_version' => $updated['version']])
            ->assertOk()->assertJsonPath('outcome', 'unchanged');
        $this->assertCount(1, app(EobCorrectionHistory::class)->forEob($eob->refresh()));
        $this->patchJson("{$this->url()}/{$eob->id}", ['expected_version' => $updated['version'], 'data' => ['provider_name' => 'Corrected Provider']])
            ->assertOk()->assertJsonPath('outcome', 'updated');
        $this->assertCount(2, app(EobCorrectionHistory::class)->forEob($eob->refresh()));
        $this->assertSame($history[0], app(EobCorrectionHistory::class)->forEob($eob)[0]);
    }

    public function test_five_large_corrections_round_trip_bounded_history_and_document_identities_at_current_limits(): void
    {
        Queue::fake();
        Storage::fake('phr_exports');
        Storage::fake('phr_dicom');
        $first = $this->document();
        $second = $this->document();
        $second->update(['title' => 'Second synthetic EOB source']);
        $claim = $this->claim($first);
        $claim['data']['lines'] = array_map(static fn (int $n): array => ['procedure_code' => '99213',
            'description' => 'Synthetic service '.$n, 'total_charges' => '10.00'], range(1, 500));
        $created = $this->putJson($this->url(), $claim)->assertCreated()->json();
        $version = $created['version'];
        $eob = PhrEob::query()->findOrFail($created['data']['id']);
        $archive = null;
        for ($number = 1; $number <= 5; $number++) {
            $updated = $this->patchJson("{$this->url()}/{$eob->id}", ['expected_version' => $version,
                'source_document_id' => $number % 2 ? $second->id : $first->id,
                'data' => ['provider_name' => 'Synthetic corrected provider '.$number]])->assertOk()->assertJsonPath('outcome', 'updated')->json();
            $version = $updated['version'];
            if ($archive !== null) {
                unlink($archive->path);
            }
            $archive = app(PhrNativeArchiveBuilder::class)->build($this->patient);
            self::assertSame($version, app(AgentClinicalRecordVersion::class)->for($eob->refresh()));
            $zip = new ZipArchive;
            self::assertTrue($zip->open($archive->path));
            $records = 0;
            $bytes = 0;
            foreach ($archive->manifest['tables'] as $entry) {
                $stream = (string) $zip->getFromName($entry['path']);
                $bytes += strlen($stream);
                foreach (array_filter(explode("\n", $stream)) as $line) {
                    self::assertLessThanOrEqual(config('phr.native_restore_max_record_bytes'), strlen($line) + 1);
                    $records++;
                }
            }
            self::assertLessThanOrEqual(config('phr.native_max_records'), $records);
            self::assertLessThanOrEqual(config('phr.native_max_record_stream_bytes'), $bytes);
            $zip->close();
        }
        self::assertNotNull($archive);
        $history = app(EobCorrectionHistory::class)->forEob($eob);
        self::assertCount(5, $history);
        self::assertCount(500, $history[4]['before']['lines']);
        self::assertCount(500, $history[4]['after']['lines']);
        self::assertArrayNotHasKey('corrections', $eob->parsed_data);
        $restorer = $this->createUser();
        $oldPatientId = $this->patient->id;
        DB::table('phr_patients')->where('id', $oldPatientId)->delete();
        $attempt = $this->historyPreview($archive->path, $restorer->id);
        self::assertSame('preview_ready', $attempt->status, (string) $attempt->failure_category);
        app(PhrNativeRestoreService::class)->apply($attempt);
        self::assertSame('completed', $attempt->refresh()->status, (string) $attempt->failure_category);
        $restored = PhrEob::query()->where('patient_id', $attempt->target_patient_root_id)->sole();
        self::assertNotSame($eob->id, $restored->id);
        $documents = PhrDocument::query()->where('patient_id', $restored->patient_id)->get()->keyBy('title');
        $docIds = [$first->id => $documents[$first->title]->id, $second->id => $documents[$second->title]->id];
        foreach ($history as &$entry) {
            foreach (['before', 'after'] as $side) {
                $entry[$side]['source_document_id'] = $docIds[$entry[$side]['source_document_id']];
            }
        }
        unset($entry);
        self::assertNotSame($first->id, $docIds[$first->id]);
        self::assertNotSame($second->id, $docIds[$second->id]);
        self::assertSame($history, app(EobCorrectionHistory::class)->forEob($restored));
        self::assertSame($restorer->id, app(PhrNativeIdentityRepository::class)->existingRecordId($restored->patient_id,
            'users', $history[0]['actor']['native_id']));
        $again = $this->historyPreview($archive->path, $restorer->id);
        self::assertSame(0, array_sum(array_column($again->plan_counts_json['tables'], 'create')));
        self::assertSame(0, array_sum(array_column($again->plan_counts_json['tables'], 'block')));
        $rebackup = app(PhrNativeArchiveBuilder::class)->build(PhrPatient::query()->findOrFail($restored->patient_id));
        foreach (PhrNativeTableCompatibility::LATER_GENERATIONS['eob_corrections'] as $table) {
            self::assertSame($archive->manifest['tables'][$table], $rebackup->manifest['tables'][$table]);
        }
        unlink($archive->path);
        unlink($rebackup->path);
    }

    public function test_history_backfill_preserves_version_and_real_corrections_prevent_aba(): void
    {
        $created = $this->putJson($this->url(), $this->claim($this->document()))->assertCreated()->json();
        $eob = PhrEob::query()->findOrFail($created['data']['id']);
        $snapshot = ['provider_name' => 'Legacy synthetic provider', 'source_document_id' => $eob->source_document_id,
            'lines' => [['line_number' => 1, 'procedure_code' => '99213', 'total_charges' => '10.00']]];
        $legacy = [['actor_user_id' => $this->actor->id, 'oauth_client_id' => $this->client->id,
            'corrected_at' => now()->toIso8601String(), 'previous_version' => 'legacy-opaque-version',
            'before' => $snapshot, 'after' => [...$snapshot, 'provider_name' => 'Example Provider']]];
        $eob->parsed_data = ['written_by' => 'agent_api', 'corrections' => $legacy];
        $eob->save();
        $versions = app(AgentClinicalRecordVersion::class);
        $before = $versions->for($eob);
        $timestamp = $eob->updated_at;
        app(EobCorrectionHistory::class)->normalizePatient($eob->patient_id);
        self::assertSame($before, $versions->for($eob->refresh()));
        self::assertEquals($timestamp, $eob->updated_at);
        $history = app(EobCorrectionHistory::class)->forEob($eob);
        self::assertSame('Legacy synthetic provider', $history[0]['before']['provider_name']);
        self::assertSame($this->actor->id, app(PhrNativeIdentityRepository::class)->existingRecordId($eob->patient_id, 'users', $history[0]['actor']['native_id']));
        app(EobCorrectionHistory::class)->normalizePatient($eob->patient_id);
        self::assertSame($history, app(EobCorrectionHistory::class)->forEob($eob));
        $this->freezeTime();
        $first = $versions->for($eob);
        $second = $this->patchJson("{$this->url()}/{$eob->id}", ['expected_version' => $first,
            'data' => ['provider_name' => 'Different synthetic provider']])->assertOk()->json('version');
        $third = $this->patchJson("{$this->url()}/{$eob->id}", ['expected_version' => $second,
            'data' => ['provider_name' => 'Example Provider']])->assertOk()->json('version');
        self::assertNotSame($first, $third);
        $this->patchJson("{$this->url()}/{$eob->id}", ['expected_version' => $first,
            'data' => ['provider_name' => 'Stale replacement']])->assertConflict();
        self::assertCount(3, app(EobCorrectionHistory::class)->forEob($eob));
    }

    public function test_old_v1_history_uses_unresolved_source_references_and_charges_expanded_rows(): void
    {
        Queue::fake();
        Storage::fake('phr_exports');
        Storage::fake('phr_dicom');
        $document = $this->document();
        $created = $this->putJson($this->url(), $this->claim($document))->assertCreated()->json();
        $eob = PhrEob::query()->findOrFail($created['data']['id']);
        $snapshot = ['provider_name' => 'Old synthetic provider', 'source_document_id' => $document->id,
            'lines' => [['line_number' => 1, 'procedure_code' => '99213', 'total_charges' => '10.00']]];
        $legacy = [['actor_user_id' => $this->actor->id, 'oauth_client_id' => $this->client->id,
            'corrected_at' => now()->toIso8601String(), 'previous_version' => 'old-source-version',
            'before' => $snapshot, 'after' => [...$snapshot, 'provider_name' => 'Example Provider']]];
        $archive = app(PhrNativeArchiveBuilder::class)->build($this->patient);
        $zip = new ZipArchive;
        self::assertTrue($zip->open($archive->path));
        $manifest = $archive->manifest;
        foreach ([...PhrNativeTableCompatibility::LATER_GENERATIONS['eob_corrections'], ...PhrNativeTableCompatibility::LATER_GENERATIONS['encounter_links']] as $table) {
            $zip->deleteName($manifest['tables'][$table]['path']);
            unset($manifest['tables'][$table]);
        }
        $path = $manifest['tables']['phr_eobs']['path'];
        $record = json_decode(trim((string) $zip->getFromName($path)), true, flags: JSON_THROW_ON_ERROR);
        $record['attributes']['parsed_data'] = json_encode(['written_by' => 'agent_api', 'corrections' => $legacy], JSON_THROW_ON_ERROR);
        unset($record['attributes']['correction_revision']);
        $record = (new PhrNativeRecordCodec)->record($record['nativeId'], $record['attributes'], $record['relationships']);
        $bytes = PhrNativeRecordCodec::canonicalJson($record)."\n";
        $zip->addFromString($path, $bytes);
        $manifest['tables']['phr_eobs']['sha256'] = hash('sha256', $bytes);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $zip->close();
        $maximum = config('phr.native_max_records');
        config(['phr.native_max_records' => array_sum(array_column($manifest['tables'], 'count')) + 1]);
        $copy = tempnam(sys_get_temp_dir(), 'synthetic-eob-legacy-');
        copy($archive->path, $copy);
        $identityCount = DB::table('phr_native_record_identities')->count();
        try {
            app(PhrNativeRestoreArchiveReader::class)->openLocal($copy);
            self::fail('Expanded correction rows must be admitted before any restore writes.');
        } catch (NativeRestoreException $exception) {
            self::assertSame('record_count_limit', $exception->failureCategory);
        } finally {
            config(['phr.native_max_records' => $maximum]);
        }
        self::assertSame($identityCount, DB::table('phr_native_record_identities')->count());
        $copy = tempnam(sys_get_temp_dir(), 'synthetic-eob-admission-');
        copy($archive->path, $copy);
        $admitted = app(PhrNativeRestoreArchiveReader::class)->openLocal($copy);
        $virtual = iterator_to_array($admitted->records('phr_eob_corrections'), false)[0];
        $reservedBytes = PhrNativeEobCorrectionCompatibility::resolvedBytes($virtual, 7);
        $exactVirtualBytes = 0;
        foreach (array_keys(PhrNativeBackupCatalog::included()) as $table) {
            foreach ($admitted->records($table) as $row) {
                $exactVirtualBytes += strlen(PhrNativeRecordCodec::canonicalJson($row)."\n");
            }
        }
        unset($admitted);
        // The same pre-upgrade patient may still hold the byte-equivalent JSON.
        // Preview must compare that representation without changing any rows.
        DB::table('phr_eobs')->where('id', $eob->id)->update(['parsed_data' => $record['attributes']['parsed_data']]);
        $original = $eob->refresh()->getAttributes();
        $sourcePreview = $this->historyPreview($archive->path, $this->actor->id);
        self::assertSame('preview_ready', $sourcePreview->status, (string) $sourcePreview->failure_category);
        self::assertSame(0, array_sum(array_column($sourcePreview->plan_counts_json['tables'], 'block')));
        self::assertSame($original, $eob->refresh()->getAttributes());
        self::assertSame(0, DB::table('phr_eob_corrections')->count());
        $sourceAgain = $this->historyPreview($archive->path, $this->actor->id);
        self::assertSame($sourcePreview->plan_digest, $sourceAgain->plan_digest);
        $edited = $legacy;
        $edited[0]['before']['provider_name'] = 'Concurrent source history edit';
        DB::table('phr_eobs')->where('id', $eob->id)->update(['parsed_data' => json_encode(['written_by' => 'agent_api', 'corrections' => $edited], JSON_THROW_ON_ERROR)]);
        try {
            app(PhrNativeRestoreService::class)->apply($sourcePreview);
            self::fail('A source history edit must invalidate the approved conversion.');
        } catch (NativeRestoreException $exception) {
            self::assertSame('preview_changed', $exception->failureCategory);
        }
        self::assertSame(0, DB::table('phr_eob_corrections')->count());
        self::assertSame($edited, $eob->refresh()->parsed_data['corrections']);
        DB::table('phr_eobs')->where('id', $eob->id)->update(['parsed_data' => $original['parsed_data']]);
        $sourcePreview = $this->historyPreview($archive->path, $this->actor->id);
        app(PhrNativeRestoreService::class)->apply($sourcePreview);
        self::assertSame('completed', $sourcePreview->refresh()->status, (string) $sourcePreview->failure_category);
        self::assertSame(1, DB::table('phr_eob_corrections')->count());
        self::assertSame(2, DB::table('phr_eob_correction_lines')->count());
        $sourceAfter = $this->historyPreview($archive->path, $this->actor->id);
        self::assertSame(0, array_sum(array_column($sourceAfter->plan_counts_json['tables'], 'create')));
        self::assertSame(0, array_sum(array_column($sourceAfter->plan_counts_json['tables'], 'block')));
        $converted = DB::table('phr_eob_corrections')->sole();
        self::assertSame($document->id, $converted->before_document_id, 'Known local document identity is preserved.');
        $convertedBackup = app(PhrNativeArchiveBuilder::class)->build($this->patient);
        $newGeneration = $this->historyPreview($convertedBackup->path, $this->actor->id);
        self::assertSame(0, array_sum(array_column($newGeneration->plan_counts_json['tables'], 'block')));
        $zip = new ZipArchive;
        self::assertTrue($zip->open($convertedBackup->path));
        self::assertSame($reservedBytes, strlen((string) $zip->getFromName('records/phr_eob_corrections.ndjson')));
        $zip->close();
        $otherDocument = $this->document();
        $otherNativeId = app(PhrNativeIdentityRepository::class)->forRecord($eob->patient_id, 'phr_documents', $otherDocument->id);
        DB::table('phr_eob_corrections')->where('id', $converted->id)->update(['before_document_id' => $otherDocument->id]);
        $wrongDocument = $this->historyPreview($archive->path, $this->actor->id);
        self::assertContains('record_conflict', $wrongDocument->plan_counts_json['blockers']);
        $wrongNewGeneration = $this->historyPreview($convertedBackup->path, $this->actor->id);
        self::assertContains('record_conflict', $wrongNewGeneration->plan_counts_json['blockers']);
        unlink($convertedBackup->path);
        DB::table('phr_eob_corrections')->where('id', $converted->id)->update(['before_document_id' => $converted->before_document_id]);
        $badBridge = json_decode($converted->legacy_provenance, true, flags: JSON_THROW_ON_ERROR);
        $badBridge['resolved']['before_document_native_id'] = $otherNativeId;
        DB::table('phr_eob_corrections')->where('id', $converted->id)->update(['legacy_provenance' => json_encode($badBridge, JSON_THROW_ON_ERROR)]);
        $wrongBridge = $this->historyPreview($archive->path, $this->actor->id);
        self::assertContains('record_conflict', $wrongBridge->plan_counts_json['blockers']);
        DB::table('phr_eob_corrections')->where('id', $converted->id)->update(['legacy_provenance' => $converted->legacy_provenance]);
        app(PhrNativeRestoreService::class)->apply($sourceAfter);
        self::assertSame(1, DB::table('phr_eob_corrections')->count());
        self::assertSame(2, DB::table('phr_eob_correction_lines')->count());
        $restorer = $this->createUser();
        DB::table('phr_patients')->where('id', $this->patient->id)->delete();
        // Occupy the old source document ID under another patient. Embedded
        // integers in an old v1 archive cannot establish this row's identity.
        $other = PhrPatient::query()->create(['owner_user_id' => $restorer->id, 'display_name' => 'Unrelated synthetic patient']);
        DB::table('phr_documents')->insert(['id' => $document->id, 'patient_id' => $other->id, 'user_id' => $restorer->id,
            'title' => 'Unrelated synthetic document', 'document_type' => 'other', 'source' => 'manual_upload', 'byte_size' => 0]);
        $ordinaryByteLimit = config('phr.native_max_record_stream_bytes');
        config(['phr.native_max_record_stream_bytes' => $exactVirtualBytes]);
        $attempt = $this->historyPreview($archive->path, $restorer->id);
        config(['phr.native_max_record_stream_bytes' => $ordinaryByteLimit]);
        self::assertSame('preview_ready', $attempt->status, (string) $attempt->failure_category);
        app(PhrNativeRestoreService::class)->apply($attempt);
        self::assertSame('completed', $attempt->refresh()->status, (string) $attempt->failure_category);
        $restored = PhrEob::query()->where('patient_id', $attempt->target_patient_root_id)->sole();
        $history = app(EobCorrectionHistory::class)->forEob($restored);
        self::assertCount(1, $history);
        self::assertSame('unresolved_source_actor', $history[0]['actor']['kind']);
        self::assertArrayNotHasKey('actor_user_id', $history[0]);
        self::assertNull(app(PhrNativeIdentityRepository::class)->existingRecordId($restored->patient_id, 'users', $history[0]['actor']['reference']));
        self::assertNull($history[0]['before']['source_document_id']);
        self::assertSame('unresolved_source_document', $history[0]['before']['source_document_provenance']['kind']);
        self::assertSame($snapshot['lines'], $history[0]['before']['lines']);
        self::assertNotSame($document->id, $restored->source_document_id);
        $again = $this->historyPreview($archive->path, $restorer->id);
        self::assertSame(0, array_sum(array_column($again->plan_counts_json['tables'], 'create')));
        self::assertSame(0, array_sum(array_column($again->plan_counts_json['tables'], 'block')));
        $rebackup = app(PhrNativeArchiveBuilder::class)->build(PhrPatient::query()->findOrFail($restored->patient_id));
        self::assertSame($history, app(EobCorrectionHistory::class)->forEob($restored->refresh()));
        unlink($archive->path);
        unlink($rebackup->path);
    }

    public function test_oversized_local_legacy_json_is_split_before_native_byte_preflight(): void
    {
        $claim = $this->claim($this->document());
        $claim['data']['lines'] = array_map(static fn (int $number): array => ['procedure_code' => '99213',
            'description' => str_repeat('S', 180), 'total_charges' => '10.00'], range(1, 500));
        $created = $this->putJson($this->url(), $claim)->assertCreated()->json();
        $eob = PhrEob::query()->findOrFail($created['data']['id']);
        $normalized = EobClaim::normalize($claim['data']);
        $snapshot = [...$normalized['header'], 'lines' => $normalized['lines'], 'source_document_id' => $eob->source_document_id];
        $legacy = [];
        for ($number = 1; $number <= 5; $number++) {
            $legacy[] = ['actor_user_id' => $this->actor->id, 'oauth_client_id' => $this->client->id,
                'corrected_at' => now()->toIso8601String(), 'previous_version' => 'legacy-version-'.$number,
                'before' => [...$snapshot, 'provider_name' => 'Before '.$number],
                'after' => [...$snapshot, 'provider_name' => 'After '.$number]];
        }
        $eob->parsed_data = ['corrections' => $legacy];
        $eob->save();
        self::assertGreaterThan(config('phr.native_restore_max_record_bytes'), strlen($eob->getRawOriginal('parsed_data')));
        $version = app(AgentClinicalRecordVersion::class)->for($eob);
        $archive = app(PhrNativeArchiveBuilder::class)->build($this->patient);
        self::assertSame(5, $archive->counts['phr_eob_corrections']);
        self::assertSame(5000, $archive->counts['phr_eob_correction_lines']);
        self::assertSame($version, app(AgentClinicalRecordVersion::class)->for($eob->refresh()));
        self::assertSame([], $eob->parsed_data);
        $history = app(EobCorrectionHistory::class)->forEob($eob);
        self::assertCount(5, $history);
        self::assertSame($legacy[4]['before']['lines'], $history[4]['before']['lines']);
        self::assertSame($legacy[4]['after']['lines'], $history[4]['after']['lines']);
        unlink($archive->path);
    }

    public function test_pre_fix_restored_json_never_resolves_embedded_ids_against_local_rows(): void
    {
        $created = $this->putJson($this->url(), $this->claim($this->document()))->assertCreated()->json();
        $eob = PhrEob::query()->findOrFail($created['data']['id']);
        app(PhrNativeIdentityRepository::class)->forRecord($eob->patient_id, 'phr_eobs', $eob->id);
        DB::table('phr_native_record_identities')->where('record_table', 'phr_eobs')->where('record_id', $eob->id)->update(['restored_at' => now()]);
        $snapshot = ['lines' => [], 'source_document_id' => $eob->source_document_id];
        $eob->parsed_data = ['corrections' => [['actor_user_id' => $this->actor->id, 'oauth_client_id' => $this->client->id,
            'corrected_at' => now()->toIso8601String(), 'previous_version' => 'source-version', 'before' => $snapshot, 'after' => $snapshot]]];
        $eob->save();
        app(EobCorrectionHistory::class)->normalizePatient($eob->patient_id);
        $entry = app(EobCorrectionHistory::class)->forEob($eob)[0];
        self::assertSame('unresolved_source_actor', $entry['actor']['kind']);
        self::assertNull($entry['before']['source_document_id']);
        self::assertNull($entry['after']['source_document_id']);
        self::assertSame('unresolved_source_document', $entry['before']['source_document_provenance']['kind']);
    }

    public function test_unrecognized_legacy_history_rolls_back_instead_of_discarding_entries(): void
    {
        $created = $this->putJson($this->url(), $this->claim($this->document()))->assertCreated()->json();
        $eob = PhrEob::query()->findOrFail($created['data']['id']);
        $valid = ['actor_user_id' => $this->actor->id, 'oauth_client_id' => $this->client->id,
            'corrected_at' => now()->toIso8601String(), 'previous_version' => 'source-version', 'before' => ['lines' => []], 'after' => ['lines' => []]];
        $eob->parsed_data = ['corrections' => [$valid, ['unrecognized_clinical_history' => 'Keep this intact']]];
        $eob->save();
        $original = $eob->getRawOriginal('parsed_data');
        $identities = DB::table('phr_native_record_identities')->count();
        $service = app(EobCorrectionHistory::class);
        try {
            $service->normalizePatient($eob->patient_id);
            self::fail('Unknown history cannot be silently discarded.');
        } catch (\LogicException) {
            self::assertSame($original, $eob->refresh()->getRawOriginal('parsed_data'));
            self::assertSame(0, DB::table('phr_eob_corrections')->count());
            self::assertSame($identities, DB::table('phr_native_record_identities')->count());
        }
        // Reuse the same service after an explicit repair: identities assigned
        // in the failed transaction must not survive in a cached mapping.
        $eob->parsed_data = ['corrections' => [$valid]];
        $eob->save();
        $service->normalizePatient($eob->patient_id);
        $history = $service->forEob($eob);
        self::assertSame(app(PhrNativeIdentityRepository::class)->existingNativeId($eob->patient_id, 'phr_patients', $eob->patient_id),
            $history[0]['oauth_client']['source_patient_native_id']);
    }

    public function test_imported_higher_revision_is_preserved_advanced_and_never_overflows(): void
    {
        $created = $this->putJson($this->url(), $this->claim($this->document()))->assertCreated()->json();
        $eob = PhrEob::query()->findOrFail($created['data']['id']);
        $snapshot = ['lines' => [], 'provider_name' => 'Synthetic earlier provider'];
        $eob->parsed_data = ['corrections' => [['actor_user_id' => $this->actor->id, 'oauth_client_id' => $this->client->id,
            'corrected_at' => now()->toIso8601String(), 'previous_version' => 'source-version', 'before' => $snapshot, 'after' => $snapshot]]];
        $eob->correction_revision = 40;
        $eob->save();
        $version = app(AgentClinicalRecordVersion::class)->for($eob);
        app(EobCorrectionHistory::class)->normalizePatient($eob->patient_id);
        self::assertSame(40, $eob->refresh()->correction_revision);
        self::assertSame($version, app(AgentClinicalRecordVersion::class)->for($eob));
        $this->patchJson("{$this->url()}/{$eob->id}", ['expected_version' => $version,
            'data' => ['provider_name' => 'Synthetic next provider']])->assertOk()->assertJsonPath('outcome', 'updated');
        self::assertSame(41, $eob->refresh()->correction_revision);
        self::assertSame([1, 2], DB::table('phr_eob_corrections')->where('eob_id', $eob->id)->orderBy('ordinal')->pluck('ordinal')->all());
        $eob->correction_revision = 2_147_483_647;
        $eob->save();
        $original = $eob->getAttributes();
        $this->patchJson("{$this->url()}/{$eob->id}", ['expected_version' => app(AgentClinicalRecordVersion::class)->for($eob),
            'data' => ['provider_name' => 'Synthetic overflow correction']])->assertConflict();
        self::assertSame($original, $eob->refresh()->getAttributes());
        self::assertCount(2, app(EobCorrectionHistory::class)->forEob($eob));
    }

    private function historyPreview(string $path, int $actorId): PhrNativeRestoreAttempt
    {
        $service = app(PhrNativeRestoreService::class);
        $attempt = $service->createPreview(new UploadedFile($path, 'synthetic-eob-history.zip', 'application/zip', null, true), $actorId, false);

        return $service->preview($attempt);
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
            ->assertUnprocessable()->assertJsonValidationErrors('document_id');
    }

    public function test_a_delta_dental_csv_without_independent_totals_is_flagged_and_writes_nothing(): void
    {
        $csv = <<<'CSV'
Claim Number,Date of Service,Submission Date,Member Name,Dentist,Phone,Procedure,Procedure Code,Accepted Fee,Claim Deductible,Delta Dental Pays,You Pay
20300102000001,2030-01-02,2030-01-03,Synthetic Patient,EXAMPLE DENTIST,(555) 010-0200,Evaluation,D0120,178.00,0.00,178.00,0.00
20300102000001,2030-01-02,2030-01-03,Synthetic Patient,EXAMPLE DENTIST,(555) 010-0200,Cleaning,D1110,46.00,0.00,46.00,0.00
20300102000001,2030-01-02,2030-01-03,Synthetic Patient,EXAMPLE DENTIST,(555) 010-0200,Imaging,D0274,77.00,0.00,77.00,0.00
CSV;
        $document = $this->storedDocument('claims.csv', 'text/csv', $csv);
        $response = $this->postJson("{$this->url()}/import", ['document_id' => $document->id])
            ->assertUnprocessable()->assertJsonValidationErrors('document_id');
        $this->assertStringContainsString('independent EOB PDF', $response->json('errors.document_id.0'));
        $this->assertSame(0, PhrEob::query()->count());
        $this->assertSame(0, PhrEobLine::query()->count());
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

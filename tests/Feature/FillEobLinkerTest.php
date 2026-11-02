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
use App\Services\PHR\Fills\FillEobLinker;
use App\Services\PHR\Fills\FillEobLinkPlanner;
use App\Services\PHR\Fills\MedicationFillEvidenceService;
use App\Services\PHR\Import\EobPdfTextExtractor;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use RuntimeException;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\Support\FakesOpenFda;
use Tests\TestCase;

/**
 * Pharmacy claims that name no drug, linked to fills from the date and the charge alone.
 * Every value here is synthetic. Each test holds one combination the linker could get wrong.
 */
final class FillEobLinkerTest extends TestCase
{
    use ConfiguresPassportKeys;
    use FakesOpenFda;
    use RefreshDatabase;

    private User $actor;

    private PhrPatient $patient;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Cache::flush();
        Storage::fake(PhrDocument::STORAGE_DISK);
        $this->fakeOpenFda();
        $this->actor = User::factory()->create(['user_role' => 'user']);
        $this->patient = $this->newPatient();
    }

    // ---- The rules ------------------------------------------------------------------------

    public function test_the_sole_fill_and_sole_claim_of_a_day_link_on_every_line_marked_automatic_with_the_rule(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $claim = $this->claim('C-1', '2026-01-05', '100.00');

        $rows = PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)->orderBy('eob_line_id')->get();
        $this->assertSame($claim->lines()->orderBy('id')->pluck('id')->all(), $rows->pluck('eob_line_id')->all(), 'every line of the claim');
        $this->assertSame([PhrMedicationFillEvidence::KIND_AUTO_EOB_LINK], $rows->pluck('source_kind')->unique()->all());
        $this->assertSame([FillEobLinkPlanner::RULE_SOLE_PAIR], $rows->pluck('source_locator')->unique()->all());
    }

    public function test_links_read_back_through_the_api_in_both_directions_and_say_they_are_automatic(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $claim = $this->claim('C-1', '2026-01-05', '100.00');
        $lineIds = $claim->lines()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $this->actAsClient();

        $read = $this->getJson("/api/v1/patients/{$this->patient->id}/medication-fills/{$fill->id}")->assertOk()->json('data');
        $this->assertEqualsCanonicalizing($lineIds, $read['eob_line_ids']);
        $this->assertSame(['eob_line_id' => $lineIds[0], 'automatic' => true, 'rule' => FillEobLinkPlanner::RULE_SOLE_PAIR], collect($read['eob_links'])->sortBy('eob_line_id')->first());

        $this->getJson("/api/v1/patients/{$this->patient->id}/eobs/{$claim->id}/lines/{$lineIds[0]}")->assertOk()->assertJsonPath('data.medication_fill_ids', [$fill->id]);
    }

    public function test_a_known_charge_picks_the_claim_among_several_on_the_same_day(): void
    {
        $this->learn('alpha', '100.00', ['2026-01-01']);
        $this->learn('beta', '200.00', ['2026-01-02']);

        $alpha = $this->fill('2026-02-01', 'alpha');
        $beta = $this->fill('2026-02-01', 'beta');
        $this->claim('C-A', '2026-02-01', '100.00');
        $this->claim('C-B', '2026-02-01', '200.00');

        $this->assertSame(['C-A', FillEobLinkPlanner::RULE_KNOWN_CHARGE], $this->linkOf($alpha));
        $this->assertSame(['C-B', FillEobLinkPlanner::RULE_KNOWN_CHARGE], $this->linkOf($beta));
    }

    public function test_a_drug_may_have_drifted_to_several_known_charges_and_only_one_on_the_day_is_needed(): void
    {
        $this->learn('alpha', '100.00', ['2026-01-01']);
        $this->learn('alpha', '110.00', ['2026-01-02']);

        // Only the drifted charge is on offer, beside a claim that is nobody's.
        $drifted = $this->fill('2026-02-01', 'alpha');
        $this->fill('2026-02-01', 'gamma');
        $this->claim('C-B', '2026-02-01', '110.00');
        $this->claim('C-X', '2026-02-01', '999.00');
        $this->assertSame(['C-B', FillEobLinkPlanner::RULE_KNOWN_CHARGE], $this->linkOf($drifted));

        // Both known charges on one day: nothing says which is this fill's.
        $torn = $this->fill('2026-03-01', 'alpha');
        $this->claim('C-C', '2026-03-01', '100.00');
        $this->claim('C-D', '2026-03-01', '110.00');
        $this->assertNull($this->linkOf($torn));
        $this->assertTrue($this->flagged($torn));
    }

    public function test_a_learned_charge_is_used_on_days_decided_before_it_was_learned(): void
    {
        // The day needing the charge arrives first, and elimination on it needs a second round.
        $alpha = $this->fill('2026-01-01', 'alpha');
        $beta = $this->fill('2026-01-01', 'beta');
        $this->claim('C-A', '2026-01-01', '100.00');
        $this->claim('C-B', '2026-01-01', '110.00');
        $this->assertNull($this->linkOf($alpha));

        $this->learn('alpha', '100.00', ['2026-01-10']);
        $this->learn('alpha', '110.00', ['2026-01-11']);
        $this->assertNull($this->linkOf($alpha), 'alpha knows both charges and cannot choose');
        $this->learn('beta', '110.00', ['2026-01-12']);

        $this->assertSame(['C-B', FillEobLinkPlanner::RULE_KNOWN_CHARGE], $this->linkOf($beta));
        $this->assertSame(['C-A', FillEobLinkPlanner::RULE_KNOWN_CHARGE], $this->linkOf($alpha), 'the claim beta took leaves alpha one');
    }

    public function test_two_fills_of_one_drug_on_one_day_are_never_guessed_between(): void
    {
        $this->learn('alpha', '100.00', ['2026-01-01']);
        $first = $this->fill('2026-02-01', 'alpha');
        $second = $this->fill('2026-02-01', 'alpha');
        $this->claim('C-A', '2026-02-01', '100.00');
        $this->claim('C-B', '2026-02-01', '100.00');

        $this->assertNull($this->linkOf($first));
        $this->assertNull($this->linkOf($second));
        $this->assertSame([FillEobLinkPlanner::REASON_SAME_DRUG_TWICE], $this->flagReasons($first));
    }

    public function test_two_drugs_wanting_one_claim_get_neither(): void
    {
        $this->learn('alpha', '100.00', ['2026-01-01']);
        $this->learn('beta', '100.00', ['2026-01-02']);
        $alpha = $this->fill('2026-02-01', 'alpha');
        $beta = $this->fill('2026-02-01', 'beta');
        $this->claim('C-A', '2026-02-01', '100.00');

        $this->assertNull($this->linkOf($alpha), 'whichever fill was looked at first must not win');
        $this->assertNull($this->linkOf($beta));
        $this->assertTrue($this->flagged($alpha));
        $this->assertTrue($this->flagged($beta));
    }

    public function test_the_last_pair_of_a_day_links_but_teaches_nothing(): void
    {
        $this->learn('alpha', '100.00', ['2026-01-01']);
        $alpha = $this->fill('2026-02-01', 'alpha');
        $newcomer = $this->fill('2026-02-01', 'gamma');
        $this->claim('C-A', '2026-02-01', '100.00');
        $this->claim('C-B', '2026-02-01', '300.00');

        $this->assertSame(['C-A', FillEobLinkPlanner::RULE_KNOWN_CHARGE], $this->linkOf($alpha));
        $this->assertSame(['C-B', FillEobLinkPlanner::RULE_LAST_PAIR], $this->linkOf($newcomer));

        // Had the guess taught gamma that charge, the known-charge rule would fire here.
        $other = $this->fill('2026-03-01', 'gamma');
        $this->fill('2026-03-01', 'delta');
        $this->claim('C-C', '2026-03-01', '300.00');
        $this->claim('C-D', '2026-03-01', '301.00');
        $this->assertNull($this->linkOf($other));
    }

    // ---- The guard ------------------------------------------------------------------------

    public function test_a_sole_pair_whose_charge_belongs_to_another_drug_is_held_for_a_person(): void
    {
        $this->learn('alpha', '100.00', ['2026-01-01', '2026-01-02']);

        // Alpha's real fill was never recorded on this day; gamma was filled and is not on any claim.
        $gamma = $this->fill('2026-02-01', 'gamma');
        $this->claim('C-X', '2026-02-01', '100.00');

        $this->assertNull($this->linkOf($gamma));
        $this->assertSame([FillEobLinkPlanner::REASON_CHARGE_CONTRADICTED], $this->flagReasons($gamma));
    }

    public function test_a_charge_merely_new_for_a_drug_is_drift_and_not_a_contradiction(): void
    {
        $this->learn('alpha', '100.00', ['2026-01-01', '2026-01-02']);
        $alpha = $this->fill('2026-02-01', 'alpha');
        $this->claim('C-X', '2026-02-01', '105.00');

        $this->assertSame(['C-X', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($alpha));
    }

    public function test_two_drugs_that_happen_to_share_a_charge_once_each_both_link(): void
    {
        $alpha = $this->fill('2026-01-01', 'alpha');
        $beta = $this->fill('2026-01-02', 'beta');
        $this->claim('C-A', '2026-01-01', '100.00');
        $this->claim('C-B', '2026-01-02', '100.00');

        $this->assertSame(['C-A', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($alpha));
        $this->assertSame(['C-B', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($beta));
    }

    public function test_several_drugs_that_share_a_charge_once_each_are_no_evidence_against_one_another(): void
    {
        $fills = [];
        foreach (['alpha', 'beta', 'gamma', 'delta'] as $index => $drug) {
            $day = sprintf('2026-01-%02d', $index + 1);
            $fills[] = $this->fill($day, $drug);
            $this->claim("C-{$index}", $day, '4.00');
        }

        foreach ($fills as $index => $fill) {
            $this->assertSame(["C-{$index}", FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($fill));
        }
    }

    public function test_a_drug_with_its_own_history_of_a_charge_keeps_it_however_many_others_share_it(): void
    {
        $this->learn('alpha', '100.00', ['2026-01-01', '2026-01-02']);
        $this->learn('beta', '100.00', ['2026-01-03', '2026-01-04']);

        $this->assertSame(['C-beta-2026-01-03', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf(PhrMedicationFill::query()->whereDate('filled_on', '2026-01-03')->sole()));
    }

    public function test_the_last_pair_is_guarded_too(): void
    {
        $this->learn('alpha', '100.00', ['2026-01-01', '2026-01-02']);
        $this->learn('beta', '200.00', ['2026-01-03']);

        $beta = $this->fill('2026-02-01', 'beta');
        $gamma = $this->fill('2026-02-01', 'gamma');
        $this->claim('C-B', '2026-02-01', '200.00');
        $this->claim('C-A', '2026-02-01', '100.00');

        $this->assertSame(['C-B', FillEobLinkPlanner::RULE_KNOWN_CHARGE], $this->linkOf($beta));
        $this->assertNull($this->linkOf($gamma), 'the claim left over is recognisably alpha\'s');
        $this->assertSame([FillEobLinkPlanner::REASON_CHARGE_CONTRADICTED], $this->flagReasons($gamma));
    }

    public function test_a_same_day_claim_known_for_another_drug_is_not_taken_when_this_drug_has_its_own(): void
    {
        $this->learn('alpha', '100.00', ['2026-01-01']);
        $this->learn('beta', '200.00', ['2026-01-02', '2026-01-03']);

        // Beta's fill was not recorded; its claim sits beside alpha's.
        $alpha = $this->fill('2026-02-01', 'alpha');
        $this->claim('C-B', '2026-02-01', '200.00');
        $this->claim('C-A', '2026-02-01', '100.00');

        $this->assertSame(['C-A', FillEobLinkPlanner::RULE_KNOWN_CHARGE], $this->linkOf($alpha));
    }

    // ---- Reversals ------------------------------------------------------------------------

    public function test_a_reversal_cancels_its_claim_so_nothing_links_to_either(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $this->claim('C-1', '2026-01-05', '100.00', ['processed_date' => '2026-01-06']);
        $this->claim('C-R', '2026-01-05', '-100.00', ['processed_date' => '2026-01-07']);

        $this->assertNull($this->linkOf($fill));
        $this->assertFalse($this->flagged($fill), 'no live claim is left to offer');
    }

    public function test_a_reversal_arriving_after_the_link_removes_it_and_a_reprocessed_claim_then_links(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $this->claim('C-1', '2026-01-05', '100.00', ['processed_date' => '2026-01-06']);
        $this->assertSame(['C-1', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($fill));

        $this->claim('C-R', '2026-01-05', '-100.00', ['processed_date' => '2026-01-07']);
        $this->assertNull($this->linkOf($fill));
        $this->assertSame(0, PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)->count(), 'the link and its lines are gone');

        $this->claim('C-2', '2026-01-05', '100.00', ['processed_date' => '2026-01-08']);
        $this->assertSame(['C-2', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($fill));
    }

    public function test_a_reprocessed_claim_arriving_before_the_reversal_ends_on_the_same_link(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $this->claim('C-2', '2026-01-05', '100.00', ['processed_date' => '2026-01-08']);
        $this->claim('C-1', '2026-01-05', '100.00', ['processed_date' => '2026-01-06']);
        $this->assertNull($this->linkOf($fill), 'two live claims until the reversal says which is gone');

        $this->claim('C-R', '2026-01-05', '-100.00', ['processed_date' => '2026-01-07']);
        $this->assertSame(['C-2', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($fill));
    }

    public function test_a_reversal_of_another_charge_or_day_cancels_nothing_and_each_reversal_one_claim(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $this->claim('C-1', '2026-01-05', '100.00');
        $this->claim('C-other-charge', '2026-01-05', '-90.00');
        $this->claim('C-other-day', '2026-01-06', '-100.00');
        $this->assertSame(['C-1', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($fill));

        $twice = $this->fill('2026-02-05', 'alpha');
        $this->claim('C-2', '2026-02-05', '100.00', ['processed_date' => '2026-02-06']);
        $this->claim('C-3', '2026-02-05', '100.00', ['processed_date' => '2026-02-07']);
        $this->claim('C-R', '2026-02-05', '-100.00', ['processed_date' => '2026-02-08']);
        $this->assertSame(['C-3', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($twice));
    }

    // ---- What a claim must be -------------------------------------------------------------

    public function test_only_pharmacy_claims_are_candidates_and_others_never_block_or_receive_a_link(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $medical = $this->claim('M-1', '2026-01-05', '100.00', ['claim_type' => 'medical']);
        $dental = $this->claim('D-1', '2026-01-05', '100.00', ['claim_type' => 'dental']);
        $this->assertNull($this->linkOf($fill));
        $this->assertFalse($this->flagged($fill), 'a medical or dental claim is no candidate');

        $this->claim('C-1', '2026-01-05', '100.00');
        $this->assertSame(['C-1', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($fill));
        $this->assertSame(0, PhrMedicationFillEvidence::query()->whereIn('eob_line_id', [...$medical->lines()->pluck('id'), ...$dental->lines()->pluck('id')])->count());
    }

    public function test_writing_other_kinds_of_claim_does_not_even_run_the_linker(): void
    {
        $counting = $this->countingLinker();
        $this->claim('M-1', '2026-01-05', '100.00', ['claim_type' => 'medical']);
        $this->claim('D-1', '2026-01-05', '100.00', ['claim_type' => 'dental']);

        $this->assertSame(0, $counting->runs);

        $this->claim('C-1', '2026-01-05', '100.00');
        $this->assertGreaterThan(0, $counting->runs);
    }

    public function test_a_claim_corrected_to_another_type_or_rejected_loses_its_automatic_link(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $claim = $this->claim('C-1', '2026-01-05', '100.00');
        $this->assertNotNull($this->linkOf($fill));

        $claim->update(['review_status' => PhrReviewStatus::REJECTED]);
        $this->assertNull($this->linkOf($fill));
        $claim->update(['review_status' => PhrReviewStatus::PENDING]);
        $this->assertNotNull($this->linkOf($fill));
        $claim->update(['claim_type' => 'medical']);
        $this->assertNull($this->linkOf($fill));
    }

    public function test_a_claim_with_no_stated_charge_blocks_a_sole_pair_and_goes_to_a_person_and_a_zero_charge_is_no_claim(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $unknown = $this->claim('C-1', '2026-01-05', null);
        $this->assertNull($this->linkOf($fill));
        $this->assertTrue($this->flagged($fill));
        $unknown->delete();

        $zero = $this->fill('2026-02-05', 'alpha');
        $this->claim('C-2', '2026-02-05', '0.00');
        $this->assertNull($this->linkOf($zero));
        $this->assertFalse($this->flagged($zero));
    }

    public function test_the_lines_sum_stands_in_for_a_header_with_no_total(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $claim = $this->claim('C-1', '2026-01-05', null, [], ['60.00', '40.00']);
        $this->assertSame(['C-1', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($fill));
        $this->assertNull($claim->fresh()->total_charges);
    }

    public function test_a_claim_spanning_several_dates_is_not_matched_on_any_of_them(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $claim = $this->claim('C-1', '2026-01-05', '100.00');
        // The first line still says the fill's date.
        $claim->lines()->orderByDesc('line_number')->first()->update(['service_start' => '2026-01-06']);

        $this->assertNull($this->linkOf($fill));
        $this->assertFalse($this->flagged($fill));
    }

    public function test_nothing_links_across_days(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $this->claim('C-1', '2026-01-06', '100.00');

        $this->assertNull($this->linkOf($fill));
        $this->assertFalse($this->flagged($fill));
    }

    // ---- People and clients ---------------------------------------------------------------

    public function test_a_link_a_person_or_client_made_survives_every_rerun_and_is_not_marked_automatic(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $human = $this->claim('C-H', '2026-01-05', '100.00');
        $line = $human->lines()->orderBy('id')->first();
        app(MedicationFillEvidenceService::class)->record($this->patient, $fill, null, $line->id, 'eob', 'claim C-H line 1');
        $before = PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)->sole()->getAttributes();

        // Even with the reversal of the claim it points at: the person's word stands.
        $this->claim('C-R', '2026-01-05', '-100.00');
        $this->rerun();

        $this->assertSame($before, PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)->sole()->getAttributes());
    }

    public function test_a_persons_link_replaces_automatic_links_on_that_fill_and_to_that_claim(): void
    {
        $alpha = $this->fill('2026-01-05', 'alpha');
        $mine = $this->claim('C-1', '2026-01-05', '100.00');
        $this->assertSame(['C-1', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($alpha));

        $other = $this->claim('C-2', '2026-01-05', '200.00');
        $beta = $this->fill('2026-01-05', 'beta');
        $this->rerun();

        // A client says alpha is on the second claim.
        app(MedicationFillEvidenceService::class)->record($this->patient, $alpha, null, $other->lines()->orderBy('id')->first()->id, 'eob', null);

        $this->assertSame(0, PhrMedicationFillEvidence::query()->where('fill_id', $alpha->id)->where('source_kind', PhrMedicationFillEvidence::KIND_AUTO_EOB_LINK)->count());
        $this->assertSame(['C-1', FillEobLinkPlanner::RULE_LAST_PAIR], $this->linkOf($beta), 'beta takes what is left, never the claim the client named');
        $this->assertNotNull($mine);
    }

    public function test_a_persons_link_teaches_the_charge(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $this->fill('2026-01-05', 'beta');
        $claim = $this->claim('C-1', '2026-01-05', '100.00');
        $this->claim('C-2', '2026-01-05', '200.00');
        $this->assertNull($this->linkOf($fill));
        app(MedicationFillEvidenceService::class)->record($this->patient, $fill, null, $claim->lines()->orderBy('id')->first()->id, 'eob', null);

        $alpha = $this->fill('2026-02-05', 'alpha');
        $gamma = $this->fill('2026-02-05', 'gamma');
        $this->claim('C-3', '2026-02-05', '100.00');
        $this->claim('C-4', '2026-02-05', '555.00');

        $this->assertSame(['C-3', FillEobLinkPlanner::RULE_KNOWN_CHARGE], $this->linkOf($alpha));
        $this->assertSame(['C-4', FillEobLinkPlanner::RULE_LAST_PAIR], $this->linkOf($gamma));
    }

    public function test_a_fill_a_client_linked_to_a_non_pharmacy_line_is_settled_all_the_same(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $medical = $this->claim('M-1', '2026-01-05', '100.00', ['claim_type' => 'medical']);
        app(MedicationFillEvidenceService::class)->record($this->patient, $fill, null, $medical->lines()->orderBy('id')->first()->id, 'eob', null);
        $this->claim('C-1', '2026-01-05', '100.00');

        $this->assertNull($this->linkOf($fill));
        $this->assertFalse($this->flagged($fill));
    }

    // ---- The fill is not touched ----------------------------------------------------------

    public function test_a_confirmed_fill_keeps_its_review_status_and_version_through_linking_flagging_and_unlinking(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha', ['review_status' => PhrReviewStatus::CONFIRMED]);
        $versions = app(AgentClinicalRecordVersion::class);
        $version = $versions->for($fill->fresh());
        $updatedAt = (string) DB::table('phr_medication_fills')->where('id', $fill->id)->value('updated_at');
        $assertSameFill = function () use ($fill, $versions, $version, &$updatedAt): void {
            $this->assertSame(PhrReviewStatus::CONFIRMED, $fill->fresh()->review_status);
            $this->assertSame($version, $versions->for($fill->fresh()));
            $moved = (string) DB::table('phr_medication_fills')->where('id', $fill->id)->value('updated_at');
            $this->assertGreaterThan($updatedAt, $moved, 'a client syncing on updated_at sees the change');
            $updatedAt = $moved;
        };

        $this->travel(1)->hours();
        $this->claim('C-1', '2026-01-05', '100.00');
        $this->assertNotNull($this->linkOf($fill));
        $assertSameFill();

        $this->travel(1)->hours();
        $this->claim('C-2', '2026-01-05', '200.00');
        $this->assertTrue($this->flagged($fill));
        $assertSameFill();

        $this->travel(1)->hours();
        $this->claim('C-R', '2026-01-05', '-200.00');
        $this->assertNotNull($this->linkOf($fill));
        $assertSameFill();

        $this->travel(1)->hours();
        $this->claim('C-R2', '2026-01-05', '-100.00');
        $this->assertNull($this->linkOf($fill));
        $assertSameFill();
    }

    public function test_a_rerun_with_nothing_to_change_leaves_updated_at_alone(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $this->claim('C-1', '2026-01-05', '100.00');
        $updatedAt = (string) DB::table('phr_medication_fills')->where('id', $fill->id)->value('updated_at');

        $this->travel(1)->hours();
        $this->rerun();

        $this->assertSame($updatedAt, (string) DB::table('phr_medication_fills')->where('id', $fill->id)->value('updated_at'));
    }

    public function test_a_link_only_change_reaches_the_changes_feed_and_a_write_on_the_old_version_still_works(): void
    {
        $this->actAsClient();
        $written = $this->putJson("/api/v1/patients/{$this->patient->id}/medication-fills", [
            'external_id' => 'fill:synthetic:feed', 'source_document_id' => $this->document()->id, 'expected_version' => null,
            'data' => ['drug_as_printed' => 'Synthetic Drug', 'filled_on' => '2026-01-05', 'quantity' => 30, 'source_kind' => 'receipt_pdf'],
        ])->assertCreated()->json();
        $this->travel(1)->hours();
        $after = now()->toIso8601String();
        $this->travel(1)->hours();

        $changes = fn (): array => collect($this->getJson("/api/v1/patients/{$this->patient->id}/changes?".http_build_query(['updated_after' => $after, 'resource_type' => ['medication-fills']]))->assertOk()->json('data'))
            ->pluck('id')->all();
        $this->assertSame([], $changes());

        $this->claim('C-1', '2026-01-05', '100.00');

        $this->assertContains($written['data']['id'], $changes());
        $this->assertSame($written['version'], $this->getJson("/api/v1/patients/{$this->patient->id}/medication-fills/{$written['data']['id']}")->assertOk()->json('data.version'));
    }

    // ---- Correcting a link ----------------------------------------------------------------

    public function test_a_person_rejects_an_automatic_link_and_can_undo_it(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha', ['review_status' => PhrReviewStatus::CONFIRMED]);
        $this->claim('C-1', '2026-01-05', '100.00');
        $version = app(AgentClinicalRecordVersion::class)->for($fill->fresh());
        $url = "/api/phr/patients/{$this->patient->id}/medication-fills/{$fill->id}/eob-link";
        $this->actingAs($this->actor);

        $this->getJson($url)->assertOk()->assertJsonPath('dismissed', false)
            ->assertJsonPath('claims.0.claim_number', 'C-1')->assertJsonPath('claims.0.automatic', true)
            ->assertJsonPath('claims.0.rule', FillEobLinkPlanner::RULE_SOLE_PAIR)->assertJsonCount(1, 'claims');

        $this->postJson($url, ['action' => 'reject'])->assertOk()->assertJsonPath('outcome', 'rejected');
        $this->assertNull($this->linkOf($fill));
        $this->assertFalse($this->flagged($fill), 'a person has answered, so it is not asked again');
        $this->getJson($url)->assertOk()->assertJsonPath('dismissed', true)->assertJsonCount(0, 'claims');
        $this->rerun();
        $this->assertNull($this->linkOf($fill), 'the answer outlasts a recompute');
        $this->postJson($url, ['action' => 'reject'])->assertOk()->assertJsonPath('outcome', 'unchanged');
        $this->assertSame($version, app(AgentClinicalRecordVersion::class)->for($fill->fresh()));
        $this->assertSame(PhrReviewStatus::CONFIRMED, $fill->fresh()->review_status);

        $this->postJson($url, ['action' => 'reset'])->assertOk()->assertJsonPath('outcome', 'restored');
        $this->assertNotNull($this->linkOf($fill));
        $this->postJson($url, ['action' => 'reset'])->assertOk()->assertJsonPath('outcome', 'unchanged');
    }

    public function test_rejecting_a_link_frees_the_claim_for_the_other_fill_that_day(): void
    {
        $this->learn('alpha', '100.00', ['2026-01-01']);
        $wrong = $this->fill('2026-02-01', 'alpha');
        $other = $this->fill('2026-02-01', 'beta');
        $this->claim('C-A', '2026-02-01', '100.00');
        $this->assertNotNull($this->linkOf($wrong));
        $this->assertNull($this->linkOf($other));
        $this->actingAs($this->actor);

        $this->postJson("/api/phr/patients/{$this->patient->id}/medication-fills/{$wrong->id}/eob-link", ['action' => 'reject'])->assertOk();

        $this->assertNull($this->linkOf($wrong));
        $this->assertNotNull($this->linkOf($other), 'the claim went to the only fill left');
    }

    public function test_a_rejection_naming_a_claim_the_fill_has_moved_off_is_refused(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $old = $this->claim('C-OLD', '2026-01-05', '100.00');
        $moved = $this->fill('2026-02-05', 'beta');
        $current = $this->claim('C-NEW', '2026-02-05', '100.00');
        $this->assertNotNull($this->linkOf($moved));
        $this->actingAs($this->actor);
        $url = "/api/phr/patients/{$this->patient->id}/medication-fills/{$moved->id}/eob-link";

        $this->postJson($url, ['action' => 'reject', 'eob_id' => $old->id])->assertConflict();
        $this->assertNotNull($this->linkOf($moved), 'the link on screen was not the one it has');
        $this->assertNotNull($this->linkOf($fill));

        $this->postJson($url, ['action' => 'reject', 'eob_id' => $current->id])->assertOk()->assertJsonPath('outcome', 'rejected');
        $this->assertNull($this->linkOf($moved));
    }

    public function test_only_an_automatic_link_can_be_rejected(): void
    {
        $bare = $this->fill('2026-03-01', 'alpha');
        $this->actingAs($this->actor);
        $url = "/api/phr/patients/{$this->patient->id}/medication-fills/{$bare->id}/eob-link";

        $this->postJson($url, ['action' => 'reject'])->assertConflict();
        $this->assertSame(0, PhrMedicationFillEvidence::query()->where('fill_id', $bare->id)->count());
    }

    public function test_someone_who_can_only_read_sees_the_links_but_cannot_change_them(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $this->claim('C-1', '2026-01-05', '100.00');
        $viewer = User::factory()->create(['user_role' => 'user']);
        DB::table('phr_patient_user_access')->insert(['patient_id' => $this->patient->id, 'user_id' => $viewer->id, 'access_level' => 'viewer', 'created_at' => now(), 'updated_at' => now()]);
        $stranger = User::factory()->create(['user_role' => 'user']);
        $url = "/api/phr/patients/{$this->patient->id}/medication-fills/{$fill->id}/eob-link";

        $this->actingAs($viewer)->getJson($url)->assertOk()->assertJsonCount(1, 'claims');
        $this->actingAs($viewer)->postJson($url, ['action' => 'reject'])->assertForbidden();
        $this->actingAs($stranger)->getJson($url)->assertNotFound();
        $this->assertNotNull($this->linkOf($fill));
    }

    public function test_a_read_cannot_reach_another_patients_fill_through_this_patient(): void
    {
        $theirs = $this->fill('2026-01-05', 'alpha', [], $this->newPatient());
        $this->actingAs($this->actor);

        $this->getJson("/api/phr/patients/{$this->patient->id}/medication-fills/{$theirs->id}/eob-link")->assertNotFound();
    }

    public function test_an_agent_relays_a_persons_answers(): void
    {
        $flagged = $this->fill('2026-04-01', 'alpha');
        $this->claim('C-1', '2026-04-01', '100.00');
        $this->claim('C-2', '2026-04-01', '200.00');
        $this->assertTrue($this->flagged($flagged));
        $this->actAsClient();
        $url = "/api/v1/patients/{$this->patient->id}/medication-fills/{$flagged->id}/eob-link";

        $this->postJson($url, ['action' => 'link'])->assertUnprocessable();
        $this->postJson($url, ['action' => 'none'])->assertOk()
            ->assertJsonPath('resource_type', 'medication_fill_eob_link_answer')
            ->assertJsonPath('outcome', 'dismissed')
            ->assertJsonPath('data', ['fill_id' => $flagged->id, 'action' => 'none']);
        $this->assertFalse($this->flagged($flagged));
        $this->postJson($url, ['action' => 'reset'])->assertOk()->assertJsonPath('outcome', 'restored');
        $this->assertTrue($this->flagged($flagged));

        $claim = PhrEob::query()->where('patient_id', $this->patient->id)->where('claim_number', 'C-2')->sole();
        $this->postJson($url, ['action' => 'link', 'eob_id' => $claim->id])->assertOk()->assertJsonPath('outcome', 'linked');
        $this->assertFalse($this->flagged($flagged));
    }

    public function test_a_retried_upsert_carrying_the_old_version_still_works_after_a_link(): void
    {
        $this->actAsClient();
        $document = $this->document();
        $written = $this->putJson("/api/v1/patients/{$this->patient->id}/medication-fills", [
            'external_id' => 'fill:synthetic:1', 'source_document_id' => $document->id, 'expected_version' => null,
            'data' => ['drug_as_printed' => 'Synthetic Drug', 'filled_on' => '2026-01-05', 'quantity' => 30, 'source_kind' => 'receipt_pdf'],
        ])->assertCreated()->json();
        $this->claim('C-1', '2026-01-05', '100.00');
        $fill = PhrMedicationFill::query()->findOrFail($written['data']['id']);
        $this->assertNotNull($this->linkOf($fill));

        $this->assertSame($written['version'], app(AgentClinicalRecordVersion::class)->for($fill->fresh()), 'linking did not change the version a client holds');
    }

    public function test_the_mcp_tool_relays_a_persons_answers_like_the_rest_route(): void
    {
        $flagged = $this->fill('2026-04-01', 'alpha');
        $this->claim('C-1', '2026-04-01', '100.00');
        $claim = $this->claim('C-2', '2026-04-01', '200.00');
        $bare = $this->fill('2026-05-01', 'alpha');
        $this->actAsClient([AgentApiScopes::MCP_USE]);
        $session = (string) $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1.0.0'],
        ]], ['Mcp-Protocol-Version' => '2025-06-18'])->assertOk()->headers->get('Mcp-Session-Id');
        $answer = fn (int $fillId, array $arguments): array => $this->callMcpTool($session, 'medication_fills.answer_eob_link', ['patient_id' => $this->patient->id, 'fill_id' => $fillId, ...$arguments]);

        foreach ([[['action' => 'none'], 'dismissed'], [['action' => 'reset'], 'restored'], [['action' => 'link', 'eob_id' => $claim->id], 'linked']] as [$arguments, $outcome]) {
            $result = $answer($flagged->id, $arguments);
            $this->assertNotTrue($result['isError'] ?? false, json_encode($result));
            $this->assertSame('medication_fill_eob_link_answer', $result['structuredContent']['resource_type']);
            $this->assertSame($outcome, $result['structuredContent']['outcome']);
        }
        $this->assertFalse($this->flagged($flagged));

        $this->assertTrue($answer($bare->id, ['action' => 'reject'])['isError'] ?? false, 'a refusal reaches the agent as an error');
    }

    // ---- Order and idempotence ------------------------------------------------------------

    /**
     * A scenario with everything order could change: a reversal and its reprocessing, a learned
     * chain across days, a contested claim, drift, twins and the guard.
     *
     * @return list<array<string, mixed>>
     */
    private function scenario(): array
    {
        return [
            ['fill', 'a1', '2026-01-01', 'alpha'], ['claim', 'c1', '2026-01-01', '100.00', '2026-01-02'],
            ['fill', 'a2', '2026-01-02', 'alpha'], ['claim', 'c2', '2026-01-02', '100.00', '2026-01-03'],
            ['fill', 'b1', '2026-01-03', 'beta'], ['claim', 'c3', '2026-01-03', '200.00', '2026-01-04'],
            // A reversal between two claims of one charge: the earlier one is the one reversed.
            ['fill', 'a3', '2026-01-04', 'alpha'], ['claim', 'c4', '2026-01-04', '100.00', '2026-01-05'],
            ['claim', 'c5', '2026-01-04', '-100.00', '2026-01-06'], ['claim', 'c6', '2026-01-04', '100.00', '2026-01-07'],
            // Known charges pick the claims, then elimination.
            ['fill', 'a4', '2026-02-01', 'alpha'], ['fill', 'b2', '2026-02-01', 'beta'], ['fill', 'g1', '2026-02-01', 'gamma'],
            ['claim', 'c7', '2026-02-01', '100.00', '2026-02-02'], ['claim', 'c8', '2026-02-01', '200.00', '2026-02-02'], ['claim', 'c9', '2026-02-01', '300.00', '2026-02-02'],
            // Two drugs want one claim: a first come would be first served.
            ['fill', 'd1', '2026-02-02', 'delta'], ['claim', 'c10', '2026-02-02', '400.00', '2026-02-03'],
            ['fill', 'e1', '2026-02-03', 'epsilon'], ['claim', 'c11', '2026-02-03', '400.00', '2026-02-04'],
            ['fill', 'd2', '2026-02-04', 'delta'], ['fill', 'e2', '2026-02-04', 'epsilon'], ['claim', 'c12', '2026-02-04', '400.00', '2026-02-05'],
            // Twins of one drug.
            ['fill', 'a5', '2026-02-05', 'alpha'], ['fill', 'a6', '2026-02-05', 'alpha'],
            ['claim', 'c13', '2026-02-05', '100.00', '2026-02-06'], ['claim', 'c14', '2026-02-05', '100.00', '2026-02-07'],
            // The guard: a fill alone with a claim recognisably another drug's.
            ['fill', 'z1', '2026-03-01', 'zeta'], ['claim', 'c15', '2026-03-01', '100.00', '2026-03-02'],
            // Drift: a charge new to the drug is still that fill's when alone.
            ['fill', 'a7', '2026-03-02', 'alpha'], ['claim', 'c16', '2026-03-02', '105.00', '2026-03-03'],
            // A fill with no claim, and a claim with no fill.
            ['fill', 'n1', '2026-03-03', 'alpha'], ['claim', 'c17', '2026-03-04', '100.00', '2026-03-05'],
        ];
    }

    public function test_the_same_data_gives_the_same_links_in_any_order_of_arrival(): void
    {
        $scenario = $this->scenario();
        $orders = [
            'as written' => $scenario,
            'reversed' => array_reverse($scenario),
            'fills first' => [...array_filter($scenario, static fn (array $event): bool => $event[0] === 'fill'), ...array_filter($scenario, static fn (array $event): bool => $event[0] === 'claim')],
            'claims first' => [...array_filter($scenario, static fn (array $event): bool => $event[0] === 'claim'), ...array_filter($scenario, static fn (array $event): bool => $event[0] === 'fill')],
        ];
        foreach ([11, 23, 37, 41, 59, 67] as $seed) {
            mt_srand($seed);
            $shuffled = $scenario;
            for ($i = count($shuffled) - 1; $i > 0; $i--) {
                $j = mt_rand(0, $i);
                [$shuffled[$i], $shuffled[$j]] = [$shuffled[$j], $shuffled[$i]];
            }
            $orders["shuffled {$seed}"] = $shuffled;
        }

        $expected = null;
        foreach ($orders as $name => $order) {
            $patient = $this->newPatient();
            foreach ($order as $event) {
                $this->load($patient, $event);
            }
            $outcome = $this->outcome($patient);
            $expected ??= $outcome;
            $this->assertSame($expected, $outcome, "arriving {$name}");
        }

        // And the answer is the intended one, not merely a stable one.
        $this->assertSame([
            'a1' => ['c1', 'sole_pair'], 'a2' => ['c2', 'sole_pair'], 'a3' => ['c6', 'sole_pair'], 'a4' => ['c7', 'known_charge'], 'a7' => ['c16', 'sole_pair'],
            'b1' => ['c3', 'sole_pair'], 'b2' => ['c8', 'known_charge'], 'd1' => ['c10', 'sole_pair'], 'e1' => ['c11', 'sole_pair'], 'g1' => ['c9', 'last_pair'],
        ], $expected['links']);
        $this->assertSame(['a5', 'a6', 'd2', 'e2', 'z1'], $expected['flagged']);
    }

    public function test_rerunning_changes_nothing_at_all(): void
    {
        foreach ($this->scenario() as $event) {
            $this->load($this->patient, $event);
        }
        $rows = static fn (): array => PhrMedicationFillEvidence::query()->orderBy('id')->get()->map->getAttributes()->all();
        $before = $rows();
        $this->assertNotEmpty($before);

        $this->travel(1)->hours();
        DB::enableQueryLog();
        $counts = app(FillEobLinker::class)->recompute((int) $this->patient->id);
        $writes = array_filter(DB::getQueryLog(), static fn (array $query): bool => preg_match('/^\s*(insert|update|delete)/i', $query['query']) === 1);
        DB::disableQueryLog();

        $this->assertSame([], array_values($writes), 'no statement writes anything');
        $this->assertSame($before, $rows());
        foreach (['links_added', 'links_removed', 'links_relabelled', 'flags_added', 'flags_removed', 'flags_relabelled'] as $name) {
            $this->assertSame(0, $counts[$name], $name);
        }
    }

    public function test_a_later_fact_relabels_a_link_in_place_instead_of_adding_another(): void
    {
        $this->learn('alpha', '100.00', ['2026-01-01']);
        $alpha = $this->fill('2026-02-01', 'alpha');
        $beta = $this->fill('2026-02-01', 'beta');
        $this->claim('C-A', '2026-02-01', '100.00');
        $this->claim('C-B', '2026-02-01', '200.00');
        $this->assertSame(['C-B', FillEobLinkPlanner::RULE_LAST_PAIR], $this->linkOf($beta));
        $ids = PhrMedicationFillEvidence::query()->where('fill_id', $beta->id)->orderBy('id')->pluck('id')->all();

        $this->learn('beta', '200.00', ['2026-01-02']);

        $this->assertSame(['C-B', FillEobLinkPlanner::RULE_KNOWN_CHARGE], $this->linkOf($beta));
        $this->assertSame($ids, PhrMedicationFillEvidence::query()->where('fill_id', $beta->id)->orderBy('id')->pluck('id')->all());
        $this->assertSame(['C-A', FillEobLinkPlanner::RULE_KNOWN_CHARGE], $this->linkOf($alpha));
    }

    // ---- Patients -------------------------------------------------------------------------

    public function test_one_patients_claims_and_charges_never_reach_another(): void
    {
        $other = $this->newPatient();

        // The other patient's claim is on this fill's day, and would be its sole pair if it counted.
        $mine = $this->fill('2026-01-05', 'alpha');
        $this->load($other, ['claim', 'theirs', '2026-01-05', '100.00', '2026-01-06']);
        $this->assertNull($this->linkOf($mine));
        $this->assertFalse($this->flagged($mine));

        // Nor does it spoil this patient's own sole pair.
        $this->claim('C-1', '2026-01-05', '100.00');
        $this->assertSame(['C-1', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($mine));

        // Nor does what the other patient's fills teach reach this patient's guard or rule 2.
        foreach (['2026-02-01', '2026-02-02'] as $day) {
            $this->load($other, ['fill', "o-{$day}", $day, 'beta']);
            $this->load($other, ['claim', "oc-{$day}", $day, '700.00', $day]);
        }
        $alone = $this->fill('2026-02-03', 'gamma');
        $this->claim('C-2', '2026-02-03', '700.00');
        $this->assertSame(['C-2', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($alone));
        $this->assertSame(0, PhrMedicationFillEvidence::query()->where('patient_id', $other->id)->whereIn('fill_id', [$mine->id, $alone->id])->count());
    }

    // ---- Failure --------------------------------------------------------------------------

    public function test_a_linker_failure_does_not_fail_the_write_that_triggered_it_and_logs_no_clinical_value(): void
    {
        $this->app->instance(FillEobLinker::class, new class(new FillEobLinkPlanner) extends FillEobLinker
        {
            public function recompute(int $patientId): array
            {
                throw new RuntimeException('Synthetic Drug failed at 123.45');
            }
        });
        $logged = [];
        Log::listen(static function ($message) use (&$logged): void {
            $logged[] = $message->message.json_encode($message->context);
        });
        $this->actAsClient();
        $document = $this->document();

        $this->putJson("/api/v1/patients/{$this->patient->id}/eobs", [
            'source_document_id' => $document->id,
            'data' => ['claim_type' => 'pharmacy', 'claim_number' => 'C-1', 'processed_date' => '2026-01-06', 'lines' => [
                ['procedure_code' => 'PLN', 'code_type' => 'pharmacy_plan', 'service_start' => '2026-01-05', 'total_charges' => '100.00'],
            ]],
        ])->assertCreated();
        $this->putJson("/api/v1/patients/{$this->patient->id}/medication-fills", [
            'external_id' => 'fill:synthetic:1', 'source_document_id' => $document->id, 'expected_version' => null,
            'data' => ['drug_as_printed' => 'Synthetic Drug', 'filled_on' => '2026-01-05', 'quantity' => 30, 'source_kind' => 'receipt_pdf'],
        ])->assertCreated();

        $this->assertSame(1, PhrEob::query()->where('patient_id', $this->patient->id)->count());
        $this->assertSame(1, PhrMedicationFill::query()->where('patient_id', $this->patient->id)->count());
        $this->assertNotEmpty($logged);
        $this->assertStringNotContainsString('123.45', implode("\n", $logged));
        $this->assertStringNotContainsString('Synthetic Drug', implode("\n", $logged));
    }

    public function test_a_failed_run_changes_nothing_and_the_next_write_catches_up(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $real = new FillEobLinkPlanner;
        $this->app->instance(FillEobLinker::class, new class($real) extends FillEobLinker
        {
            public function recompute(int $patientId): array
            {
                throw new RuntimeException('down');
            }
        });
        $this->claim('C-1', '2026-01-05', '100.00');
        $this->assertNull($this->linkOf($fill));

        $this->app->forgetInstance(FillEobLinker::class);
        $this->fill('2026-02-05', 'beta');
        $this->assertSame(['C-1', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($fill));
    }

    public function test_a_rolled_back_write_does_not_stop_the_next_one_from_running(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        try {
            DB::transaction(function (): void {
                $this->fill('2026-02-05', 'beta');
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
            // A rollback discards the after-commit callback. A later write must still register one.
        }
        $this->claim('C-1', '2026-01-05', '100.00');

        $this->assertSame(['C-1', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($fill));
    }

    public function test_one_transaction_writing_many_rows_runs_once(): void
    {
        $counting = $this->countingLinker();
        DB::transaction(function (): void {
            $this->fill('2026-01-05', 'alpha');
            $this->claim('C-1', '2026-01-05', '100.00');
            $this->claim('C-2', '2026-01-06', '100.00');
        });

        $this->assertSame(1, $counting->runs);
    }

    // ---- Each way in ----------------------------------------------------------------------

    public function test_every_way_of_writing_a_fill_or_a_claim_brings_the_links_up_to_date(): void
    {
        $url = "/api/phr/patients/{$this->patient->id}/medication-fills";
        $this->actingAs($this->actor);
        $claim = $this->claim('C-1', '2026-01-05', '100.00');

        // A person enters a fill.
        $fillId = $this->postJson($url, ['drug_as_printed' => 'Synthetic Drug', 'filled_on' => '2026-01-05', 'quantity' => 30])->assertCreated()->json('medication_fill.id');
        $fill = PhrMedicationFill::query()->findOrFail($fillId);
        $this->assertNotNull($this->linkOf($fill), 'web create');

        // A person moves it to another day.
        $this->patchJson("{$url}/{$fillId}", ['filled_on' => '2026-01-06'])->assertOk();
        $this->assertNull($this->linkOf($fill), 'web update');
        $this->patchJson("{$url}/{$fillId}", ['filled_on' => '2026-01-05'])->assertOk();
        $this->assertNotNull($this->linkOf($fill), 'web update back');

        // A person rejects it, then approves it after all.
        $this->patchJson("{$url}/{$fillId}/review", ['review_status' => PhrReviewStatus::REJECTED])->assertOk();
        $this->assertNull($this->linkOf($fill), 'web reject');
        $this->patchJson("{$url}/{$fillId}/review", ['review_status' => PhrReviewStatus::CONFIRMED])->assertOk();
        $this->assertNotNull($this->linkOf($fill), 'web approve');

        // A person rejects the claim, then keeps it.
        $this->patchJson("/api/phr/patients/{$this->patient->id}/eobs/{$claim->id}/review", ['review_status' => PhrReviewStatus::REJECTED])->assertOk();
        $this->assertNull($this->linkOf($fill), 'claim rejected');
        $this->patchJson("/api/phr/patients/{$this->patient->id}/eobs/{$claim->id}/review", ['review_status' => PhrReviewStatus::CONFIRMED])->assertOk();
        $this->assertNotNull($this->linkOf($fill), 'claim kept');

        // A person deletes the fill: its evidence row is not left vouching for anything.
        $this->deleteJson("{$url}/{$fillId}")->assertNoContent();
        $this->assertSame(0, PhrMedicationFillEvidence::query()->where('fill_id', $fillId)->where('source_kind', PhrMedicationFillEvidence::KIND_AUTO_EOB_LINK)->count(), 'web delete');

        // A client writes a fill.
        $this->actAsClient();
        $document = $this->document();
        $written = $this->putJson("/api/v1/patients/{$this->patient->id}/medication-fills", [
            'external_id' => 'fill:synthetic:2', 'source_document_id' => $document->id, 'expected_version' => null,
            'data' => ['drug_as_printed' => 'Synthetic Other', 'filled_on' => '2026-01-05', 'quantity' => 30, 'source_kind' => 'receipt_pdf'],
        ])->assertCreated()->json();
        $agentFill = PhrMedicationFill::query()->findOrFail($written['data']['id']);
        $this->assertNotNull($this->linkOf($agentFill), 'agent upsert');
        $this->assertNotEmpty($written['data']['eob_line_ids'], 'the write\'s own response shows the link');

        // A client retracts it.
        $this->postJson("/api/v1/patients/{$this->patient->id}/medication-fills/{$agentFill->id}/retract", ['expected_version' => $written['version']])->assertOk();
        $this->assertSame(0, PhrMedicationFillEvidence::query()->where('fill_id', $agentFill->id)->where('source_kind', PhrMedicationFillEvidence::KIND_AUTO_EOB_LINK)->count(), 'agent retract');
    }

    public function test_a_client_upserting_and_correcting_a_claim_brings_the_links_up_to_date(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $this->actAsClient();
        $document = $this->document();
        $written = $this->putJson("/api/v1/patients/{$this->patient->id}/eobs", [
            'source_document_id' => $document->id,
            'data' => ['claim_type' => 'pharmacy', 'claim_number' => 'C-1', 'processed_date' => '2026-01-06', 'lines' => [
                ['procedure_code' => 'PLN', 'code_type' => 'pharmacy_plan', 'service_start' => '2026-01-05', 'total_charges' => '60.00'],
                ['procedure_code' => 'PAT', 'code_type' => 'pharmacy_plan', 'service_start' => '2026-01-05', 'total_charges' => '40.00'],
            ]],
        ])->assertCreated()->json();
        $this->assertSame(['C-1', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($fill), 'agent upsert of a claim');
        $this->assertSame(2, PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)->count());

        // A correction replacing the lines: the dropped line goes, and the row with it.
        $this->patchJson("/api/v1/patients/{$this->patient->id}/eobs/{$written['data']['id']}", [
            'expected_version' => $written['version'],
            'data' => ['lines' => [
                ['line_number' => 1, 'procedure_code' => 'PLN', 'code_type' => 'pharmacy_plan', 'service_start' => '2026-01-05', 'total_charges' => '100.00'],
            ]],
        ])->assertOk();
        $this->assertSame(1, PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)->count());
        $this->assertSame(0, PhrMedicationFillEvidence::query()->whereNull('eob_line_id')->count(), 'no row left pointing at nothing');

        // A correction moving the claim to another date.
        $version = app(AgentClinicalRecordVersion::class)->for(PhrEob::query()->findOrFail($written['data']['id']));
        $this->patchJson("/api/v1/patients/{$this->patient->id}/eobs/{$written['data']['id']}", [
            'expected_version' => $version,
            'data' => ['lines' => [
                ['line_number' => 1, 'procedure_code' => 'PLN', 'code_type' => 'pharmacy_plan', 'service_start' => '2026-01-09', 'total_charges' => '100.00'],
            ]],
        ])->assertOk();
        $this->assertNull($this->linkOf($fill));
    }

    public function test_a_claim_imported_by_the_server_parser_gives_the_linker_its_date_and_signed_charge(): void
    {
        $text = static fn (string $number, string $amount, string $processed): string => <<<EOB
EMPLOYEE BENEFIT PLAN                         Print Date:{$processed}
 PROCEDURE                                                                                                                               OTHER     PYMT          PATIENT
 / REVENUE       DATES OF SERVICE          TOTAL         PROVIDER INELIGIBLE NOTES APPLIED                      APPLIED       BEN.      CARRIER'S MADE BY        RESPON-
    CODE                                  CHARGES        DISCOUNT AMOUNT           TO DED.                     TO COPAY        %          PYMT     PLAN          SIBILITY
94S              01-05-26                     {$amount}                                                                                                {$amount}        0.00
94V              01-05-26                       0.00                                                                                                       0.00        0.00
                          TOTALS              {$amount}                                                                                                {$amount}        0.00
                                                                                                                          Claim No:      {$number}
Processed Under Pharmacy                                                                                                 Participant:    SYNTHETIC PARTICIPANT
Plan For Services Provided By                                                                                                  ID No:     0000000001-1
CAREMARK                                                                                                             Processed On:        {$processed}       By: SY3
Patient:      SYNTHETIC PATIENT
Group Name:        EXAMPLE GROUP
Group No:       00000.001
EOB;
        $fill = $this->fill('2026-01-05', 'alpha');
        $this->actAsClient();
        // One instance for the whole test: the route's controller keeps the service it was built with.
        $extractor = new class extends EobPdfTextExtractor
        {
            public string $body = '';

            public function extract(string $path): string
            {
                return $this->body;
            }
        };
        $this->app->instance(EobPdfTextExtractor::class, $extractor);
        foreach ([['RX100', '100.00', '01-06-26'], ['RX101', '-100.00', '01-07-26'], ['RX102', '100.00', '01-08-26']] as $index => [$number, $amount, $processed]) {
            $extractor->body = $text($number, $amount, $processed);
            $bytes = "%PDF-1.4\nsynthetic {$number}";
            $path = "patients/{$this->patient->id}/documents/eob-{$number}.pdf";
            Storage::disk(PhrDocument::STORAGE_DISK)->put($path, $bytes);
            $document = PhrDocument::query()->create([
                'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'uploaded_by_user_id' => $this->actor->id,
                'document_type' => 'insurance', 'source' => 'agent_upload', 'storage_disk' => PhrDocument::STORAGE_DISK,
                'storage_path' => $path, 'original_filename' => 'eob.pdf', 'mime_type' => 'application/pdf', 'byte_size' => strlen($bytes), 'file_hash' => hash('sha256', $bytes),
            ]);
            $imported = $this->postJson("/api/v1/patients/{$this->patient->id}/eobs/import", ['document_id' => $document->id]);
            $this->assertSame('created', $imported->json('outcome'), (string) $imported->getContent());
            $this->assertSame([['RX100'], null, ['RX102']][$index], $this->linkOf($fill) === null ? null : [$this->linkOf($fill)[0]]);
        }
        $this->assertSame(2, PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)->count(), 'both plan-code lines of the reprocessed claim');
    }

    public function test_merging_a_flagged_duplicate_leaves_the_linker_to_decide_for_the_fill_that_remains(): void
    {
        $this->actingAs($this->actor);
        $existing = $this->fill('2026-01-05', 'alpha');
        $flagged = $this->fill('2026-01-05', 'beta');
        $this->claim('C-1', '2026-01-05', '100.00');
        $this->assertTrue($this->flagged($flagged), 'two fills and one claim');
        app(MedicationFillEvidenceService::class)->flagPossibleDuplicate($this->patient, $flagged, $existing);

        $this->postJson("/api/phr/patients/{$this->patient->id}/medication-fills/{$flagged->id}/resolve-duplicate", [
            'action' => 'same', 'version' => app(AgentClinicalRecordVersion::class)->for($flagged->fresh()), 'candidate_fill_id' => $existing->id,
        ])->assertOk()->assertJsonPath('outcome', 'merged');

        $this->assertSame(['C-1', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($existing));
        $this->assertFalse($this->flagged($flagged), 'the retracted fill keeps no flag');
        $this->assertSame(0, PhrMedicationFillEvidence::query()->where('fill_id', $flagged->id)->whereIn('source_kind', PhrMedicationFillEvidence::LINKER_KINDS)->count());
    }

    public function test_merging_a_fill_does_not_carry_its_automatic_link_as_if_it_were_a_source(): void
    {
        $this->actingAs($this->actor);
        $existing = $this->fill('2026-01-05', 'alpha');
        $flagged = $this->fill('2026-01-06', 'alpha');
        $this->claim('C-1', '2026-01-06', '100.00');
        $this->assertNotNull($this->linkOf($flagged));
        app(MedicationFillEvidenceService::class)->flagPossibleDuplicate($this->patient, $flagged, $existing);

        $this->postJson("/api/phr/patients/{$this->patient->id}/medication-fills/{$flagged->id}/resolve-duplicate", [
            'action' => 'same', 'version' => app(AgentClinicalRecordVersion::class)->for($flagged->fresh()), 'candidate_fill_id' => $existing->id,
        ])->assertOk()->assertJsonPath('moved.eob_lines', 0);

        // The fill that remains was filled on another day, and links are never made across days.
        $this->assertNull($this->linkOf($existing));
    }

    // ---- The Review queue -----------------------------------------------------------------

    public function test_a_pending_leftover_shows_its_flag_and_candidate_claims_in_the_queue_and_in_its_own_flags(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $this->fill('2026-01-05', 'beta');
        $this->claim('C-1', '2026-01-05', '100.00', ['processed_date' => '2026-01-06', 'provider_name' => 'Example Pharmacy Plan']);
        $this->claim('C-2', '2026-01-05', '200.00', ['processed_date' => '2026-01-07']);

        $item = $this->queueItem($fill);
        $this->assertSame(PhrMedicationFillEvidence::KIND_EOB_LINK_OPEN, $item['flags'][0]['code']);
        $this->assertSame('pending_review', $item['review_status']);
        $this->assertSame(['C-1', 'C-2'], array_column($item['eob_candidates'], 'claim_number'), 'in a stable order');
        $this->assertSame(['eob_id', 'claim_number', 'service_date', 'processed_date', 'total_charges', 'provider_name'], array_keys($item['eob_candidates'][0]));
        $this->assertSame('2026-01-05', $item['eob_candidates'][0]['service_date']);
        $this->assertSame('100.00', $item['eob_candidates'][0]['total_charges']);

        $this->actingAs($this->actor);
        $resource = $this->getJson("/api/phr/patients/{$this->patient->id}/medication-fills/{$fill->id}")->assertOk()->json('medication_fill');
        $this->assertSame([PhrMedicationFillEvidence::KIND_EOB_LINK_OPEN], array_column($resource['review_flags'], 'code'));
        $this->assertSame([], $resource['eob_line_ids'], 'a flag does not read as a link');
    }

    public function test_a_confirmed_leftover_is_listed_too_without_reopening_review_and_counts_toward_the_total(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha', ['review_status' => PhrReviewStatus::CONFIRMED]);
        $this->fill('2026-01-05', 'beta', ['review_status' => PhrReviewStatus::CONFIRMED]);
        $this->claim('C-1', '2026-01-05', '100.00', ['review_status' => PhrReviewStatus::CONFIRMED]);
        $this->claim('C-2', '2026-01-05', '200.00', ['review_status' => PhrReviewStatus::CONFIRMED]);

        $this->actingAs($this->actor);
        $queue = $this->getJson("/api/phr/patients/{$this->patient->id}/review")->assertOk()->json();
        $group = collect($queue['groups'])->firstWhere('key', 'records:claims-to-match');
        $this->assertNotNull($group);
        $item = collect($group['items'])->firstWhere('id', $fill->id);
        $this->assertSame(PhrReviewStatus::CONFIRMED, $item['review_status']);
        $this->assertSame(['C-1', 'C-2'], array_column($item['eob_candidates'], 'claim_number'));
        $this->assertSame(2, $queue['summary']['claims_to_match']);
        $this->assertSame(2, $queue['summary']['total']);
        $this->assertSame(0, $queue['summary']['sections']['medication-fills']['pending'], 'still not pending');
        $this->assertSame(PhrReviewStatus::CONFIRMED, $fill->fresh()->review_status);
    }

    public function test_a_fill_with_no_same_day_claim_gets_no_flag_and_stays_out_of_the_queue(): void
    {
        $cash = $this->fill('2026-01-05', 'alpha', ['review_status' => PhrReviewStatus::CONFIRMED]);
        $this->claim('C-1', '2026-01-06', '100.00');

        $this->assertFalse($this->flagged($cash));
        $this->actingAs($this->actor);
        $queue = $this->getJson("/api/phr/patients/{$this->patient->id}/review")->assertOk()->json();
        $this->assertNull(collect($queue['groups'])->firstWhere('key', 'records:claims-to-match'));
        $this->assertSame(0, $queue['summary']['claims_to_match']);
    }

    public function test_a_flag_goes_away_when_the_fill_is_linked_or_its_claim_goes_away(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $beta = $this->fill('2026-01-05', 'beta');
        $first = $this->claim('C-1', '2026-01-05', '100.00');
        $this->claim('C-2', '2026-01-05', '200.00');
        $this->assertTrue($this->flagged($fill));

        app(MedicationFillEvidenceService::class)->record($this->patient, $beta, null, $first->lines()->orderBy('id')->first()->id, 'eob', null);
        $this->assertFalse($this->flagged($fill), 'one fill and one claim are left');
        $this->assertSame(['C-2', FillEobLinkPlanner::RULE_LAST_PAIR], $this->linkOf($fill));

        // A fill rejected with its flag open loses the flag.
        $lone = $this->fill('2026-02-05', 'alpha');
        $this->fill('2026-02-05', 'gamma');
        $this->claim('C-3', '2026-02-05', '300.00');
        $this->claim('C-4', '2026-02-05', '400.00');
        $this->assertTrue($this->flagged($lone));
        $lone->update(['review_status' => PhrReviewStatus::REJECTED]);
        $this->assertFalse($this->flagged($lone));
    }

    public function test_the_queue_reads_candidates_with_a_fixed_number_of_queries(): void
    {
        $count = function (): int {
            $this->actingAs($this->actor);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->getJson("/api/phr/patients/{$this->patient->id}/review")->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $this->fill('2026-01-05', 'alpha');
        $this->fill('2026-01-05', 'beta');
        $this->claim('C-1', '2026-01-05', '100.00');
        $this->claim('C-2', '2026-01-05', '200.00');
        $few = $count();

        foreach (range(1, 9) as $day) {
            $date = sprintf('2026-03-%02d', $day);
            $this->fill($date, 'alpha');
            $this->fill($date, 'beta');
            $this->claim("C-a-{$day}", $date, '100.00');
            $this->claim("C-b-{$day}", $date, '200.00');
        }

        $this->assertSame($few, $count());
    }

    // ---- A person's answer ----------------------------------------------------------------

    public function test_a_person_choosing_a_claim_links_all_its_lines_as_their_own_and_nothing_else_changes(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha', ['review_status' => PhrReviewStatus::CONFIRMED]);
        $beta = $this->fill('2026-01-05', 'beta');
        $chosen = $this->claim('C-1', '2026-01-05', '100.00');
        $this->claim('C-2', '2026-01-05', '200.00');
        $version = app(AgentClinicalRecordVersion::class)->for($fill->fresh());

        $this->actingAs($this->actor);
        $url = "/api/phr/patients/{$this->patient->id}/medication-fills/{$fill->id}/eob-link";
        $response = $this->postJson($url, ['action' => 'link', 'eob_id' => $chosen->id])->assertOk()->assertJsonPath('outcome', 'linked')->json('medication_fill');

        $this->assertCount(2, $response['eob_line_ids']);
        $this->assertSame([false, false], array_column($response['eob_links'], 'automatic'));
        $this->assertSame($version, app(AgentClinicalRecordVersion::class)->for($fill->fresh()));
        $this->assertSame(PhrReviewStatus::CONFIRMED, $fill->fresh()->review_status);
        $this->assertSame(['C-2', FillEobLinkPlanner::RULE_LAST_PAIR], $this->linkOf($beta), 'the rest is decided around the answer');
        $this->rerun();
        $this->assertCount(2, PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)->where('source_kind', 'eob')->get());

        // The same answer again is unchanged.
        $this->postJson($url, ['action' => 'link', 'eob_id' => $chosen->id])->assertOk()->assertJsonPath('outcome', 'unchanged');
    }

    public function test_a_person_saying_none_of_these_clears_the_flag_for_good_and_stops_automatic_links(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $this->fill('2026-01-05', 'beta');
        $this->claim('C-1', '2026-01-05', '100.00');
        $this->claim('C-2', '2026-01-05', '200.00');

        $this->actingAs($this->actor);
        $url = "/api/phr/patients/{$this->patient->id}/medication-fills/{$fill->id}/eob-link";
        $this->postJson($url, ['action' => 'none'])->assertOk()->assertJsonPath('outcome', 'dismissed');
        $this->assertFalse($this->flagged($fill));

        // A learned charge that would now point straight at it changes nothing.
        $this->learn('alpha', '100.00', ['2026-02-01']);
        $this->rerun();
        $this->assertNull($this->linkOf($fill));
        $this->assertFalse($this->flagged($fill));
        $this->postJson($url, ['action' => 'none'])->assertOk()->assertJsonPath('outcome', 'unchanged');
    }

    public function test_a_stale_or_foreign_answer_is_refused_and_writes_nothing(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $this->fill('2026-01-05', 'beta');
        $this->claim('C-1', '2026-01-05', '100.00');
        $this->claim('C-2', '2026-01-05', '200.00');
        $reversed = $this->claim('C-3', '2026-01-05', '300.00', ['processed_date' => '2026-01-06']);
        $this->claim('C-3R', '2026-01-05', '-300.00', ['processed_date' => '2026-01-07']);
        $otherDay = $this->claim('C-4', '2026-01-09', '100.00');
        $medical = $this->claim('M-1', '2026-01-05', '100.00', ['claim_type' => 'medical']);
        $otherPatient = $this->newPatient();
        $theirs = $this->load($otherPatient, ['claim', 'theirs', '2026-01-05', '100.00', '2026-01-06']);
        $theirFill = $this->load($otherPatient, ['fill', 'tf', '2026-01-05', 'alpha']);
        $before = PhrMedicationFillEvidence::query()->count();

        $this->actingAs($this->actor);
        $url = "/api/phr/patients/{$this->patient->id}/medication-fills/{$fill->id}/eob-link";
        $this->postJson($url, ['action' => 'link', 'eob_id' => $reversed->id])->assertConflict();
        $this->postJson($url, ['action' => 'link', 'eob_id' => $otherDay->id])->assertConflict();
        $this->postJson($url, ['action' => 'link', 'eob_id' => $medical->id])->assertNotFound();
        $this->postJson($url, ['action' => 'link', 'eob_id' => $theirs->id])->assertNotFound();
        $this->postJson($url, ['action' => 'link'])->assertUnprocessable();
        $this->postJson($url, ['action' => 'other'])->assertUnprocessable();
        $this->postJson("/api/phr/patients/{$this->patient->id}/medication-fills/{$theirFill->id}/eob-link", ['action' => 'none'])->assertNotFound();
        $stranger = User::factory()->create(['user_role' => 'user']);
        $this->actingAs($stranger)->postJson($url, ['action' => 'none'])->assertNotFound();

        $this->assertSame($before, PhrMedicationFillEvidence::query()->count());
    }

    public function test_none_of_these_is_refused_when_there_was_nothing_to_answer(): void
    {
        $fill = $this->fill('2026-01-05', 'alpha');
        $this->actingAs($this->actor);
        $this->postJson("/api/phr/patients/{$this->patient->id}/medication-fills/{$fill->id}/eob-link", ['action' => 'none'])->assertConflict();
    }

    // ---- The backfill ---------------------------------------------------------------------

    public function test_the_backfill_dry_run_counts_what_it_would_do_writes_nothing_and_prints_no_clinical_value(): void
    {
        $fill = $this->seedWithoutLinks();

        $exit = Artisan::call('phr:fills:link-eobs', ['--patient' => $this->patient->id, '--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertSame(0, PhrMedicationFillEvidence::query()->count());
        $this->assertStringContainsString('[dry run]', $output);
        $this->assertStringContainsString('linked sole pair: 1', $output);
        $this->assertStringContainsString('links added: 2', $output);
        foreach (['alpha', 'C-1', '100', 'Synthetic', 'Example'] as $clinical) {
            $this->assertStringNotContainsString($clinical, $output);
        }
        $this->assertNull($this->linkOf($fill));
    }

    public function test_the_backfill_links_one_patient_and_a_second_run_changes_nothing(): void
    {
        $fill = $this->seedWithoutLinks();
        $other = $this->newPatient();
        PhrMedicationFill::withoutEvents(fn () => $this->load($other, ['fill', 'o', '2026-01-05', 'alpha']));
        PhrEob::withoutEvents(fn () => PhrEobLine::withoutEvents(fn () => $this->load($other, ['claim', 'oc', '2026-01-05', '100.00', '2026-01-06'])));

        $this->assertSame(0, Artisan::call('phr:fills:link-eobs', ['--patient' => $this->patient->id]));
        $this->assertSame(['C-1', FillEobLinkPlanner::RULE_SOLE_PAIR], $this->linkOf($fill));
        $this->assertSame(0, PhrMedicationFillEvidence::query()->where('patient_id', $other->id)->count(), 'only that patient');

        $this->assertSame(0, Artisan::call('phr:fills:link-eobs', ['--patient' => $this->patient->id]));
        $this->assertStringContainsString('links added: 0', Artisan::output());

        $this->assertSame(0, Artisan::call('phr:fills:link-eobs'));
        $this->assertGreaterThan(0, PhrMedicationFillEvidence::query()->where('patient_id', $other->id)->count(), 'every patient without the option');
    }

    public function test_the_backfill_refuses_an_unknown_patient(): void
    {
        $this->assertSame(1, Artisan::call('phr:fills:link-eobs', ['--patient' => 999999]));
    }

    // ---- Helpers --------------------------------------------------------------------------

    private function newPatient(): PhrPatient
    {
        $patient = PhrPatient::query()->create(['owner_user_id' => $this->actor->id, 'display_name' => 'Synthetic Linker Patient', 'relationship' => 'self']);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id, 'user_id' => $this->actor->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $this->actor->id, 'granted_at' => now(),
        ]);

        return $patient;
    }

    /** @param  array<string, mixed>  $attributes */
    private function fill(string $day, string $drug, array $attributes = [], ?PhrPatient $patient = null): PhrMedicationFill
    {
        return PhrMedicationFill::factory()->create([
            'patient_id' => ($patient ?? $this->patient)->id,
            'review_status' => PhrReviewStatus::PENDING,
            'filled_on' => $day,
            'ndc_status' => PhrMedicationFill::NDC_MANUAL,
            'ndc_as_captured' => null, 'ndc_10' => null, 'ndc_11' => null,
            'drug_as_printed' => "Synthetic {$drug}",
            'external_id' => 'fill-'.(++$this->sequence),
            'import_source' => 'synthetic',
            ...$attributes,
        ]);
    }

    /**
     * Two lines as the claims this is for have: the plan's share and the patient's.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array{0?: string|null, 1?: string|null}  $lineCharges
     */
    private function claim(string $number, string $day, ?string $charge, array $attributes = [], array $lineCharges = [], ?PhrPatient $patient = null): PhrEob
    {
        $owner = $patient ?? $this->patient;

        return DB::transaction(function () use ($number, $day, $charge, $attributes, $lineCharges, $owner): PhrEob {
            $eob = PhrEob::query()->create([
                'patient_id' => $owner->id, 'user_id' => $owner->owner_user_id,
                'import_source' => 'synthetic', 'external_id' => 'eob-'.(++$this->sequence),
                'claim_number' => $number, 'claim_type' => 'pharmacy', 'review_status' => PhrReviewStatus::PENDING,
                'processed_date' => $day, 'total_charges' => $charge,
                ...$attributes,
            ]);
            foreach ([1 => 'PLN', 2 => 'PAT'] as $lineNumber => $code) {
                PhrEobLine::query()->create([
                    'eob_id' => $eob->id, 'patient_id' => $owner->id, 'line_number' => $lineNumber, 'procedure_code' => $code,
                    'code_type' => 'pharmacy_plan', 'service_start' => $day, 'service_end' => $day,
                    'total_charges' => $lineCharges[$lineNumber - 1] ?? null,
                ]);
            }

            return $eob;
        });
    }

    /** Sole pairs that teach a drug a charge, on days nothing else uses. */
    private function learn(string $drug, string $charge, array $days): void
    {
        foreach ($days as $day) {
            $this->fill($day, $drug);
            $this->claim("C-{$drug}-{$day}", $day, $charge);
        }
    }

    /** @param  array<int, mixed>  $event */
    private function load(PhrPatient $patient, array $event): PhrMedicationFill|PhrEob
    {
        if ($event[0] === 'fill') {
            return $this->fill($event[2], $event[3], ['external_id' => $event[1]], $patient);
        }

        return $this->claim($event[1], $event[2], $event[3], ['processed_date' => $event[4]], [], $patient);
    }

    /**
     * What a patient ended up with, in terms that do not mention row ids.
     *
     * @return array{links: array<string, array{0: string, 1: string}>, flagged: list<string>}
     */
    private function outcome(PhrPatient $patient): array
    {
        $links = [];
        foreach (PhrMedicationFill::query()->where('patient_id', $patient->id)->orderBy('external_id')->get() as $fill) {
            if (($link = $this->linkOf($fill)) !== null) {
                $links[(string) $fill->external_id] = $link;
            }
        }
        ksort($links);
        $flagged = PhrMedicationFillEvidence::query()->where('phr_medication_fill_evidence.patient_id', $patient->id)
            ->where('phr_medication_fill_evidence.source_kind', PhrMedicationFillEvidence::KIND_EOB_LINK_OPEN)
            ->join('phr_medication_fills', 'phr_medication_fills.id', '=', 'phr_medication_fill_evidence.fill_id')
            ->orderBy('phr_medication_fills.external_id')->pluck('phr_medication_fills.external_id')->all();

        return ['links' => $links, 'flagged' => $flagged];
    }

    /** @return array{0: string, 1: string}|null the claim number and rule of a fill's automatic link */
    private function linkOf(PhrMedicationFill $fill): ?array
    {
        $rows = PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)->where('source_kind', PhrMedicationFillEvidence::KIND_AUTO_EOB_LINK)->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $claimIds = PhrEobLine::query()->whereIn('id', $rows->pluck('eob_line_id'))->pluck('eob_id')->unique();
        $this->assertCount(1, $claimIds, 'a fill links to one claim');
        $this->assertCount(1, $rows->pluck('source_locator')->unique());
        $this->assertSame(PhrEobLine::query()->where('eob_id', $claimIds->first())->count(), $rows->count(), 'all lines of its claim');

        return [(string) PhrEob::query()->whereKey($claimIds->first())->value('claim_number'), (string) $rows->first()->source_locator];
    }

    private function flagged(PhrMedicationFill $fill): bool
    {
        return $this->flagReasons($fill) !== [];
    }

    /** @return list<string> */
    private function flagReasons(PhrMedicationFill $fill): array
    {
        return PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)->where('source_kind', PhrMedicationFillEvidence::KIND_EOB_LINK_OPEN)->pluck('source_locator')->all();
    }

    /** @return array<string, mixed> */
    private function queueItem(PhrMedicationFill $fill): array
    {
        $this->actingAs($this->actor);
        $queue = $this->getJson("/api/phr/patients/{$this->patient->id}/review")->assertOk()->json();

        return collect($queue['groups'])->pluck('items')->flatten(1)->first(static fn (array $item): bool => $item['id'] === $fill->id && $item['section'] === 'medication-fills');
    }

    private function rerun(): void
    {
        app(FillEobLinker::class)->recompute((int) $this->patient->id);
    }

    /** One fill and one claim the triggers never saw, as data written before the linker existed. */
    private function seedWithoutLinks(): PhrMedicationFill
    {
        $fill = PhrMedicationFill::withoutEvents(fn () => $this->fill('2026-01-05', 'alpha'));
        PhrEob::withoutEvents(fn () => PhrEobLine::withoutEvents(fn () => $this->claim('C-1', '2026-01-05', '100.00', ['provider_name' => 'Example Pharmacy Plan'])));
        $this->assertSame(0, PhrMedicationFillEvidence::query()->count());

        return $fill;
    }

    private function countingLinker(): object
    {
        $linker = new class(new FillEobLinkPlanner) extends FillEobLinker
        {
            public int $runs = 0;

            public function recompute(int $patientId): array
            {
                $this->runs++;

                return parent::recompute($patientId);
            }
        };
        $this->app->instance(FillEobLinker::class, $linker);

        return $linker;
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function callMcpTool(string $session, string $name, array $arguments): array
    {
        return $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]], [
            'Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session,
        ])->assertOk()->json('result');
    }

    /** @param list<string> $extraScopes */
    private function actAsClient(array $extraScopes = []): void
    {
        $client = Client::query()->create([
            'name' => 'Synthetic Linker Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        Passport::actingAs($this->actor, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE, ...$extraScopes], 'api', $client);
    }

    private function document(): PhrDocument
    {
        return PhrDocument::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'uploaded_by_user_id' => $this->actor->id,
            'document_type' => 'other', 'source' => 'manual_upload', 'storage_disk' => PhrDocument::STORAGE_DISK, 'byte_size' => 0,
        ]);
    }
}

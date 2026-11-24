<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\PhrPatientVital;
use App\Models\PhrProcedure;
use App\Models\User;
use App\Services\Mcp\AgentMcpToolsets;
use App\Services\PHR\Encounters\PhrEncounterLinks;
use App\Services\PHR\Encounters\PhrLinkSuggestions;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * Suggesting the records dated inside a stay and confirming them in bulk (#450): the window
 * and its margin, a stay still under way, what is left out or listed apart, ordering and
 * paging, the confirm, and the agent's REST and MCP forms with their scopes. Every value is a
 * synthetic stay of Pat Example at Example General Hospital; no real data.
 */
final class PhrLinkSuggestionsTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    private User $owner;

    private PhrPatient $patient;

    private string $session;

    private int $nextId = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        $this->travelTo('2026-03-20 12:00:00');
        [$this->owner, $this->patient] = $this->patientWithOwner('Pat Example');
    }

    public function test_a_finished_stay_suggests_the_labs_inside_its_days_and_a_margin_widens_each_side(): void
    {
        $stay = $this->finishedStay();
        $before = $this->lab('Synthetic lab before', '2026-03-01 23:30:00');
        $during = $this->lab('Synthetic lab during', '2026-03-03 06:00:00');
        $after = $this->lab('Synthetic lab after', '2026-03-07 00:15:00');
        $this->lab('Synthetic lab two days out', '2026-03-08 00:00:00');
        $this->lab('Synthetic undated lab', null);
        $this->actingAs($this->owner);

        $exact = $this->suggestions($stay)->assertOk();
        $this->assertSame(['from' => '2026-03-02', 'to' => '2026-03-06', 'open' => false, 'margin_days' => 0], $exact->json('window'));
        $this->assertSame([$during->id], $this->ids($exact, 'lab-result'));
        $this->assertSame(1, $exact->json('groups.lab-result.suggested.total'));

        $wide = $this->suggestions($stay, ['margin_days' => 1])->assertOk();
        $this->assertSame(['from' => '2026-03-01', 'to' => '2026-03-07', 'open' => false, 'margin_days' => 1], $wide->json('window'));
        $this->assertSame([$before->id, $during->id, $after->id], $this->ids($wide, 'lab-result'), 'by date; the day after the window is not in it');
        $this->assertSame([
            'id' => $during->id, 'type' => 'lab-result', 'name' => 'Synthetic lab during', 'date' => '2026-03-03 06:00:00',
            'detail' => '1.8 mg/dL', 'review_status' => PhrReviewStatus::PENDING, 'linked_visit' => null,
        ], $wide->json('groups.lab-result.suggested.records.1'));

        $this->suggestions($stay, ['margin_days' => 15])->assertUnprocessable()->assertJsonValidationErrors('margin_days');
        $this->suggestions($stay, ['margin_days' => -1])->assertUnprocessable()->assertJsonValidationErrors('margin_days');
    }

    public function test_each_type_is_read_on_its_own_date_and_a_day_granular_date_matches_its_day(): void
    {
        $stay = $this->finishedStay();
        $vital = $this->vital('Heart rate', null, ['vital_date' => '2026-03-06']);
        $this->vital('Synthetic vital after', '2026-03-07 08:00:00');
        $procedure = PhrProcedure::query()->create(['patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'name' => 'Synthetic chest radiograph', 'status' => 'completed', 'performed_on' => '2026-03-06', 'review_status' => PhrReviewStatus::PENDING]);
        $authored = $this->document('Daily Progress Note', ['authored_at' => '2026-03-04 07:00:00', 'observed_at' => '2026-02-01 00:00:00']);
        $dated = $this->document('Synthetic discharge summary', ['observed_at' => '2026-03-06 00:00:00']);
        $this->document('Synthetic note authored later', ['authored_at' => '2026-03-07 00:00:00', 'observed_at' => '2026-03-03 00:00:00']);
        $this->actingAs($this->owner);

        $response = $this->suggestions($stay)->assertOk();
        $this->assertSame([$vital->id], $this->ids($response, 'vital'));
        $this->assertSame([$procedure->id], $this->ids($response, 'procedure'), 'a performed date on the last day matches');
        $this->assertSame([$authored->id, $dated->id], $this->ids($response, 'document'), 'a document by its authoring time, else its date');
        $this->assertSame(['lab-result', 'vital', 'medication', 'procedure', 'document'], array_keys($response->json('groups')));
        $this->assertSame(['suggested', 'on_other_visits'], array_keys($response->json('groups.vital')));

        $only = $this->suggestions($stay, ['types' => ['document', 'vital']])->assertOk();
        $this->assertSame(['vital', 'document'], array_keys($only->json('groups')), 'the types asked for, in their fixed order');
        $this->suggestions($stay, ['types' => ['eob']])->assertUnprocessable()->assertJsonValidationErrors('types.0');
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string, 2: list<string>, 3: list<string>}> */
    public static function windows(): iterable
    {
        yield 'a stay under way runs to now' => [
            ['visit_date' => '2026-03-02', 'visit_started_at' => '2026-03-02 14:20:00', 'encounter_state' => 'in-progress'],
            '2026-03-21',
            ['2026-03-02 15:00:00', '2026-03-20 11:00:00', '2026-03-20 23:00:00'],
            ['2026-03-01 23:00:00', '2026-03-22 06:00:00'],
        ];
        yield 'a finished visit with only a start is its one day' => [
            ['visit_date' => '2026-03-02', 'visit_started_at' => '2026-03-02 14:20:00', 'encounter_state' => 'finished', 'encounter_class' => 'AMB'],
            '2026-03-02',
            ['2026-03-02 15:00:00'],
            ['2026-03-03 06:00:00', '2026-03-20 11:00:00'],
        ];
        yield 'a plain office visit is its day' => [
            ['visit_date' => '2026-03-02', 'visit_started_at' => null, 'encounter_state' => null, 'encounter_class' => null],
            '2026-03-02',
            ['2026-03-02 00:00:00', '2026-03-02 23:59:59'],
            ['2026-03-01 23:59:59', '2026-03-03 00:00:00'],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $inside
     * @param  list<string>  $outside
     */
    #[DataProvider('windows')]
    public function test_the_window_ends_where_the_visit_does(array $fields, string $to, array $inside, array $outside): void
    {
        $visit = $this->stay($fields);
        $expected = [];
        foreach ($inside as $index => $at) {
            $expected[] = $this->lab('Synthetic lab inside '.$index, $at)->id;
        }
        foreach ($outside as $index => $at) {
            $this->lab('Synthetic lab outside '.$index, $at);
        }
        $this->actingAs($this->owner);

        $response = $this->suggestions($visit)->assertOk();
        $this->assertSame($to, $response->json('window.to'));
        $this->assertSame(($fields['encounter_state'] ?? null) === 'in-progress', $response->json('window.open'));
        $this->assertSame($expected, $this->ids($response, 'lab-result'));
    }

    public function test_an_open_end_grows_with_time_and_a_margin_never_reaches_past_today(): void
    {
        $stay = $this->stay(['visit_date' => '2026-03-02', 'visit_started_at' => '2026-03-02 14:20:00', 'encounter_state' => 'in-progress']);
        $tomorrow = $this->lab('Synthetic lab tomorrow', '2026-03-23 06:00:00');
        $this->actingAs($this->owner);

        $this->assertSame([], $this->ids($this->suggestions($stay, ['margin_days' => 3])->assertOk(), 'lab-result'));
        $this->assertSame('2026-03-21', $this->suggestions($stay, ['margin_days' => 3])->json('window.to'));
        $this->assertSame('2026-02-27', $this->suggestions($stay, ['margin_days' => 3])->json('window.from'));

        $this->travelTo('2026-03-23 09:00:00');
        $this->assertSame([$tomorrow->id], $this->ids($this->suggestions($stay)->assertOk(), 'lab-result'), 'the window grows as the stay goes on');

        $window = PhrLinkSuggestions::window($stay, 0, CarbonImmutable::parse('2026-03-05 09:59:00'));
        $this->assertSame('2026-03-05', $window['to'] ?? null, 'before 10:00 UTC it is still the same day at UTC+14');
        $window = PhrLinkSuggestions::window($stay, 0, CarbonImmutable::parse('2026-03-05 10:00:00'));
        $this->assertSame('2026-03-06', $window['to'] ?? null);
        $this->assertNull(PhrLinkSuggestions::window(new PhrOfficeVisit, 0, now()), 'a visit with no date suggests nothing');
    }

    public function test_records_on_this_stay_are_left_out_and_those_on_another_are_listed_apart(): void
    {
        $stay = $this->finishedStay();
        $other = $this->stay(['visit_date' => '2026-03-04', 'visit_started_at' => null, 'visit_type' => 'Synthetic consult', 'encounter_class' => 'AMB', 'encounter_state' => 'finished']);
        $withdrawn = $this->stay(['visit_date' => '2026-03-04', 'visit_started_at' => null, 'retracted_at' => now()]);
        $free = $this->lab('Creatinine', '2026-03-03 06:00:00');
        $onThis = $this->lab('Sodium', '2026-03-03 06:00:00');
        $onOther = $this->lab('Glucose', '2026-03-04 06:00:00');
        $onWithdrawn = $this->lab('Potassium', '2026-03-05 06:00:00');
        $this->lab('Rejected synthetic lab', '2026-03-03 06:00:00', ['review_status' => PhrReviewStatus::REJECTED]);
        $this->lab('Retracted synthetic lab', '2026-03-03 06:00:00', ['retracted_at' => now()]);
        $this->lab('Deleted synthetic lab', '2026-03-03 06:00:00')->delete();
        [, $stranger] = $this->patientWithOwner('Sam Example');
        PhrLabResult::query()->create(['patient_id' => $stranger->id, 'user_id' => $stranger->owner_user_id, 'test_name' => 'BMP', 'analyte' => 'Stranger lab', 'value' => '1', 'collection_datetime' => '2026-03-03 06:00:00']);
        $links = app(PhrEncounterLinks::class);
        $links->link((int) $this->patient->id, (int) $stay->id, 'lab-result', (int) $onThis->id);
        $links->link((int) $this->patient->id, (int) $other->id, 'lab-result', (int) $onOther->id);
        DB::table('phr_office_visit_lab_results')->insert(['patient_id' => $this->patient->id, 'office_visit_id' => $withdrawn->id, 'lab_result_id' => $onWithdrawn->id]);
        $this->actingAs($this->owner);

        $response = $this->suggestions($stay)->assertOk();
        $this->assertSame([$free->id, $onWithdrawn->id], $this->ids($response, 'lab-result'), 'a link to a withdrawn visit holds nothing');
        $this->assertSame([$onOther->id], $this->ids($response, 'lab-result', PhrLinkSuggestions::ON_OTHER_VISITS));
        $this->assertSame(['id' => $other->id, 'date' => '2026-03-04', 'visit_type' => 'Synthetic consult'], $response->json('groups.lab-result.on_other_visits.records.0.linked_visit'));
        $this->assertSame(1, $response->json('groups.lab-result.on_other_visits.total'));
        $this->assertNull($response->json('groups.lab-result.suggested.records.0.linked_visit'));
    }

    public function test_only_a_stays_own_orders_are_suggested_never_a_home_medication(): void
    {
        $stay = $this->finishedStay();
        $inpatient = $this->medication('ceftriaxone 1 g IV', 'inpatient', ['started_at' => '2026-03-02 16:00:00', 'started_on' => '2026-03-02']);
        $discharge = $this->medication('amoxicillin 500 mg', 'discharge', ['started_on' => '2026-03-06']);
        $this->medication('lisinopril 10 mg', 'community', ['started_on' => '2026-03-03']);
        $this->medication('Synthetic uncategorised medication', null, ['started_on' => '2026-03-03']);
        $this->medication('Synthetic clinic medication', 'outpatient', ['started_on' => '2026-03-03']);
        $this->medication('Synthetic earlier order', 'inpatient', ['started_on' => '2026-02-20']);
        $this->actingAs($this->owner);

        $this->assertSame([$inpatient->id, $discharge->id], $this->ids($this->suggestions($stay)->assertOk(), 'medication'));
    }

    public function test_each_list_runs_by_date_is_capped_with_its_total_and_pages_on_its_own_cursor(): void
    {
        $stay = $this->finishedStay();
        $ids = [];
        foreach (['2026-03-05 06:00:00', '2026-03-03 06:00:00', '2026-03-03 06:00:00', '2026-03-04 06:00:00', '2026-03-02 15:00:00'] as $index => $at) {
            $ids[$at][] = $this->lab('Synthetic lab '.$index, $at)->id;
        }
        $expected = [...$ids['2026-03-02 15:00:00'], ...$ids['2026-03-03 06:00:00'], ...$ids['2026-03-04 06:00:00'], ...$ids['2026-03-05 06:00:00']];
        $this->actingAs($this->owner);

        $first = $this->suggestions($stay, ['limit' => 2])->assertOk();
        $this->assertSame(array_slice($expected, 0, 2), $this->ids($first, 'lab-result'));
        $this->assertSame(5, $first->json('groups.lab-result.suggested.total'));
        $seen = $this->ids($first, 'lab-result');
        $cursor = $first->json('groups.lab-result.suggested.next_cursor');
        $pages = 1;
        while ($cursor !== null && $pages < 5) {
            $page = $this->suggestions($stay, ['limit' => 2, 'types' => ['lab-result'], 'list' => 'suggested', 'cursor' => $cursor])->assertOk();
            $this->assertSame(['lab-result'], array_keys($page->json('groups')));
            $this->assertSame(['suggested'], array_keys($page->json('groups.lab-result')));
            $seen = [...$seen, ...$this->ids($page, 'lab-result')];
            $cursor = $page->json('groups.lab-result.suggested.next_cursor');
            $pages++;
        }
        $this->assertSame(3, $pages);
        $this->assertSame($expected, $seen, 'each once, by date then record');
        $this->assertSame(PhrLinkSuggestions::DEFAULT_LIMIT, 50);

        $this->suggestions($stay, ['cursor' => 'abc', 'list' => 'suggested'])->assertUnprocessable()->assertJsonValidationErrors('cursor');
        $this->suggestions($stay, ['cursor' => 'abc', 'types' => ['lab-result']])->assertUnprocessable()->assertJsonValidationErrors('cursor');
        $this->suggestions($stay, ['cursor' => 'not-a-cursor', 'types' => ['lab-result'], 'list' => 'suggested'])->assertUnprocessable()->assertJsonValidationErrors('cursor');
        $this->suggestions($stay, ['limit' => 51])->assertUnprocessable()->assertJsonValidationErrors('limit');
    }

    public function test_confirming_links_exactly_the_selection_and_a_repeat_is_unchanged(): void
    {
        $stay = $this->finishedStay();
        $creatinine = $this->lab('Creatinine', '2026-03-03 06:00:00');
        $potassium = $this->lab('Potassium', '2026-03-04 06:00:00');
        $leftOut = $this->lab('Sodium', '2026-03-04 06:00:00');
        $note = $this->document('Daily Progress Note', ['authored_at' => '2026-03-04 07:00:00']);
        $this->actingAs($this->owner);
        $this->assertSame([$creatinine->id, $potassium->id, $leftOut->id], $this->ids($this->suggestions($stay), 'lab-result'));

        // The person unticked Sodium.
        $selection = ['links' => [['type' => 'lab-result', 'id' => $creatinine->id], ['type' => 'lab-result', 'id' => $potassium->id], ['type' => 'document', 'id' => $note->id]]];
        $this->postJson($this->linksUrl($stay), $selection)->assertOk()->assertJsonPath('summary', ['linked' => 3, 'unchanged' => 0, 'refused' => 0]);
        $this->postJson($this->linksUrl($stay), $selection)->assertOk()->assertJsonPath('summary', ['linked' => 0, 'unchanged' => 3, 'refused' => 0]);

        $after = $this->suggestions($stay)->assertOk();
        $this->assertSame([$leftOut->id], $this->ids($after, 'lab-result'), 'what is on the stay is no longer suggested');
        $this->assertSame([], $this->ids($after, 'document'));
        $groups = $this->agentSession([AgentApiScopes::CLINICAL_READ, AgentApiScopes::DOCUMENTS_READ])
            ->getJson("/api/v1/patients/{$this->patient->id}/office-visits/{$stay->id}/stay")->assertOk()->json('data.groups');
        $this->assertSame([$creatinine->id, $potassium->id], array_column($groups['lab_results']['data'], 'id'));
        $this->assertSame([$note->id], array_column($groups['documents']['data'], 'id'));
    }

    public function test_an_agent_suggests_and_links_over_rest_with_documents_only_under_documents_read(): void
    {
        $stay = $this->finishedStay();
        $lab = $this->lab('Creatinine', '2026-03-03 06:00:00');
        $taken = $this->lab('Glucose', '2026-03-04 06:00:00');
        $note = $this->document('Daily Progress Note', ['authored_at' => '2026-03-04 07:00:00']);
        $other = $this->stay(['visit_date' => '2026-03-04', 'visit_started_at' => null, 'encounter_state' => 'finished']);
        app(PhrEncounterLinks::class)->link((int) $this->patient->id, (int) $other->id, 'lab-result', (int) $taken->id);
        $suggest = "/api/v1/patients/{$this->patient->id}/office-visits/{$stay->id}/link-suggestions";
        $batch = "/api/v1/patients/{$this->patient->id}/office-visits/{$stay->id}/links/batch";

        $this->agentSession([AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE]);
        $read = $this->getJson($suggest)->assertOk();
        $this->assertSame('office_visit_link_suggestions', $read->json('resource_type'));
        $this->assertSame($stay->id, $read->json('data.office_visit_id'));
        $this->assertSame(['lab-result', 'vital', 'medication', 'procedure'], array_keys($read->json('data.groups')), 'documents need documents:read');
        $this->assertSame([$lab->id], array_column($read->json('data.groups.lab-result.suggested.records'), 'id'));
        $this->assertSame([$taken->id], array_column($read->json('data.groups.lab-result.on_other_visits.records'), 'id'));
        $this->assertSame([], $this->getJson($suggest.'?types[]=document')->assertOk()->json('data.groups'));

        $linked = $this->postJson($batch, ['links' => [
            ['resource_type' => 'lab-result', 'resource_id' => $lab->id],
            ['resource_type' => 'document', 'resource_id' => $note->id],
            ['resource_type' => 'lab-result', 'resource_id' => $taken->id],
        ]])->assertOk();
        $this->assertSame('office_visit_link_batch', $linked->json('resource_type'));
        $this->assertSame(['linked' => 1, 'unchanged' => 0, 'refused' => 2], $linked->json('data.summary'));
        $this->assertSame(['resource_type' => 'lab-result', 'resource_id' => $lab->id, 'outcome' => 'linked'], $linked->json('data.results.0'));
        $this->assertSame(['resource_type' => 'document', 'resource_id' => $note->id, 'outcome' => 'refused', 'reason' => 'insufficient_scope', 'message' => 'Linking a document requires documents:read.'], $linked->json('data.results.1'));
        $this->assertSame('linked_elsewhere', $linked->json('data.results.2.reason'));
        $this->assertSame(['id' => $other->id, 'date' => '2026-03-04', 'visit_type' => 'Synthetic hospital admission'], $linked->json('data.results.2.linked_visit'));
        $this->assertSame(0, DB::table('phr_office_visit_documents')->count());

        $this->agentSession([AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE, AgentApiScopes::DOCUMENTS_READ]);
        $this->assertSame([$note->id], array_column($this->getJson($suggest)->assertOk()->json('data.groups.document.suggested.records'), 'id'));
        $this->postJson($batch, ['links' => [['resource_type' => 'document', 'resource_id' => $note->id], ['resource_type' => 'lab-result', 'resource_id' => $lab->id]]])
            ->assertOk()->assertJsonPath('data.summary', ['linked' => 1, 'unchanged' => 1, 'refused' => 0]);
        $groups = $this->getJson("/api/v1/patients/{$this->patient->id}/office-visits/{$stay->id}/stay")->assertOk()->json('data.groups');
        $this->assertSame([$lab->id], array_column($groups['lab_results']['data'], 'id'));
        $this->assertSame([$note->id], array_column($groups['documents']['data'], 'id'));

        // Each operation's own scopes, and the whole request's refusals.
        $this->agentSession([AgentApiScopes::CLINICAL_READ]);
        $this->getJson($suggest)->assertOk();
        $this->postJson($batch, ['links' => [['resource_type' => 'lab-result', 'resource_id' => $lab->id]]])->assertForbidden();
        $this->agentSession([AgentApiScopes::CLINICAL_WRITE]);
        $this->getJson($suggest)->assertForbidden();
        $this->agentSession([AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE]);
        $rejected = $this->stay(['review_status' => PhrReviewStatus::REJECTED]);
        $this->postJson("/api/v1/patients/{$this->patient->id}/office-visits/{$rejected->id}/links/batch", ['links' => [['resource_type' => 'lab-result', 'resource_id' => $taken->id]]])->assertConflict();
        $this->postJson("/api/v1/patients/{$this->patient->id}/office-visits/999999/links/batch", ['links' => [['resource_type' => 'document', 'resource_id' => $note->id]]])->assertNotFound();
        $this->getJson("/api/v1/patients/{$this->patient->id}/office-visits/999999/link-suggestions")->assertNotFound();
        $this->postJson($batch, ['links' => array_fill(0, 101, ['resource_type' => 'lab-result', 'resource_id' => $lab->id])])->assertUnprocessable();
        $this->postJson($batch, ['links' => [['resource_type' => 'eob', 'resource_id' => $lab->id]]])->assertUnprocessable();
    }

    public function test_an_agent_suggests_and_links_over_mcp_in_the_encounters_toolset(): void
    {
        $this->assertSame('encounters', AgentMcpToolsets::toolsetFor('office_visits.suggest_links'));
        $this->assertSame('encounters', AgentMcpToolsets::toolsetFor('office_visits.link_records'));
        $stay = $this->finishedStay();
        $lab = $this->lab('Creatinine', '2026-03-03 06:00:00');
        $vital = $this->vital('Heart rate', '2026-03-03 08:00:00');
        $this->mcpSession();

        $suggested = $this->tool('office_visits.suggest_links', ['patient_id' => $this->patient->id, 'visit_id' => $stay->id, 'margin_days' => 1]);
        $this->assertFalse($suggested['isError'] ?? false, json_encode($suggested));
        $data = $suggested['structuredContent']['data'];
        $this->assertSame(1, $data['window']['margin_days']);
        $this->assertSame([$lab->id], array_column($data['groups']['lab-result']['suggested']['records'], 'id'));
        $this->assertSame([$vital->id], array_column($data['groups']['vital']['suggested']['records'], 'id'));
        $this->assertSame(0, DB::table('phr_office_visit_lab_results')->count(), 'suggesting links nothing');

        // The person confirmed the lab alone.
        $linked = $this->tool('office_visits.link_records', ['patient_id' => $this->patient->id, 'visit_id' => $stay->id, 'links' => [['resource_type' => 'lab-result', 'resource_id' => $lab->id]]]);
        $this->assertFalse($linked['isError'] ?? false, json_encode($linked));
        $this->assertSame(['linked' => 1, 'unchanged' => 0, 'refused' => 0], $linked['structuredContent']['data']['summary']);
        $repeat = $this->tool('office_visits.link_records', ['patient_id' => $this->patient->id, 'visit_id' => $stay->id, 'links' => [['resource_type' => 'lab-result', 'resource_id' => $lab->id]]]);
        $this->assertSame('unchanged', $repeat['structuredContent']['data']['results'][0]['outcome']);

        $groups = $this->tool('office_visits.get_stay', ['patient_id' => $this->patient->id, 'visit_id' => $stay->id])['structuredContent']['data']['groups'];
        $this->assertSame([$lab->id], array_column($groups['lab_results']['data'], 'id'));
        $this->assertSame([], $groups['vitals']['data']);
        $after = $this->tool('office_visits.suggest_links', ['patient_id' => $this->patient->id, 'visit_id' => $stay->id, 'types' => ['lab-result', 'vital']])['structuredContent']['data']['groups'];
        $this->assertSame([], $after['lab-result']['suggested']['records']);
        $this->assertSame([$vital->id], array_column($after['vital']['suggested']['records'], 'id'));
    }

    public function test_a_viewer_or_a_stranger_cannot_read_the_suggestions(): void
    {
        $stay = $this->finishedStay();
        $this->lab('Creatinine', '2026-03-03 06:00:00');
        $viewer = $this->createUser();
        PhrPatientUserAccess::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $viewer->id, 'access_level' => PhrPatientUserAccess::LEVEL_VIEWER,
            'granted_by_user_id' => $this->owner->id, 'granted_at' => now(),
        ]);
        $this->actingAs($viewer);
        $this->suggestions($stay)->assertForbidden();

        $this->actingAs($this->createUser());
        $this->suggestions($stay)->assertNotFound();

        [$stranger, $strangers] = $this->patientWithOwner('Sam Example');
        $strangersVisit = PhrOfficeVisit::query()->create(['patient_id' => $strangers->id, 'user_id' => $stranger->id, 'visit_date' => '2026-03-02']);
        $this->actingAs($this->owner);
        $this->getJson("/api/phr/patients/{$this->patient->id}/office-visits/{$strangersVisit->id}/link-suggestions")->assertNotFound();
        $this->suggestions($this->stay(['retracted_at' => now()]))->assertNotFound();
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<int>
     */
    private function ids(TestResponse $response, string $type, string $list = PhrLinkSuggestions::SUGGESTED): array
    {
        $groups = $response->json('groups') ?? $response->json('data.groups');

        return array_column($groups[$type][$list]['records'] ?? [], 'id');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return TestResponse<Response>
     */
    private function suggestions(PhrOfficeVisit $visit, array $query = []): TestResponse
    {
        return $this->getJson("/api/phr/patients/{$visit->patient_id}/office-visits/{$visit->id}/link-suggestions?".http_build_query($query));
    }

    private function linksUrl(PhrOfficeVisit $visit): string
    {
        return "/api/phr/patients/{$visit->patient_id}/office-visits/{$visit->id}/links";
    }

    /** @param  list<string>  $scopes */
    private function agentSession(array $scopes): self
    {
        $client = Client::query()->create([
            'name' => 'Synthetic Suggestion Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        Passport::actingAs($this->owner, $scopes, 'api', $client);

        return $this;
    }

    private function mcpSession(): void
    {
        $this->agentSession([AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE]);
        $init = $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1.0.0'],
        ]]);
        $this->session = (string) $init->headers->get('Mcp-Session-Id');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function tool(string $tool, array $arguments): array
    {
        $response = $this->mcp(['jsonrpc' => '2.0', 'id' => ++$this->nextId, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);

        return $response->json('result') ?? $this->fail('No result: '.$response->getContent());
    }

    /**
     * @param  array<string, mixed>  $message
     * @return TestResponse<Response>
     */
    private function mcp(array $message): TestResponse
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        if (isset($this->session) && $message['method'] !== 'initialize') {
            $headers['Mcp-Session-Id'] = $this->session;
        }

        return $this->postJson('/api/v1/mcp', $message, $headers)->assertOk();
    }

    private function finishedStay(): PhrOfficeVisit
    {
        return $this->stay(['encounter_state' => 'finished', 'visit_ended_at' => '2026-03-06 11:00:00']);
    }

    /** @param  array<string, mixed>  $fields */
    private function stay(array $fields = []): PhrOfficeVisit
    {
        return tap((new PhrOfficeVisit)->forceFill([
            'patient_id' => $this->patient->id,
            'user_id' => $this->owner->id,
            'visit_date' => '2026-03-02',
            'visit_started_at' => '2026-03-02 14:20:00',
            'visit_type' => 'Synthetic hospital admission',
            'facility_name' => 'Example General Hospital',
            'encounter_class' => 'IMP',
            'encounter_state' => 'in-progress',
            'review_status' => PhrReviewStatus::PENDING,
            ...$fields,
        ]))->save();
    }

    /** @param  array<string, mixed>  $fields */
    private function lab(string $analyte, ?string $collected, array $fields = []): PhrLabResult
    {
        return tap((new PhrLabResult)->forceFill([
            'patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'test_name' => 'Synthetic basic metabolic panel', 'analyte' => $analyte,
            'value' => '1.8', 'unit' => 'mg/dL', 'collection_datetime' => $collected, 'review_status' => PhrReviewStatus::PENDING, ...$fields,
        ]))->save();
    }

    /** @param  array<string, mixed>  $fields */
    private function vital(string $name, ?string $observed, array $fields = []): PhrPatientVital
    {
        return PhrPatientVital::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'vital_name' => $name, 'vital_value' => '88',
            'observed_at' => $observed, 'review_status' => PhrReviewStatus::PENDING, ...$fields,
        ]);
    }

    /** @param  array<string, mixed>  $fields */
    private function medication(string $name, ?string $category, array $fields = []): PhrMedication
    {
        return tap((new PhrMedication)->forceFill([
            'patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'name' => $name, 'category' => $category,
            'review_status' => PhrReviewStatus::PENDING, ...$fields,
        ]))->save();
    }

    /** @param  array<string, mixed>  $fields */
    private function document(string $title, array $fields = []): PhrDocument
    {
        return PhrDocument::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'title' => $title, 'document_type' => 'other',
            'source' => 'manual_upload', 'storage_disk' => PhrDocument::STORAGE_DISK, 'byte_size' => 0, ...$fields,
        ]);
    }

    /** @return array{0: User, 1: PhrPatient} */
    private function patientWithOwner(string $name): array
    {
        $owner = $this->createUser();
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => $name,
            'relationship' => 'self',
            'birth_date' => '1950-06-15',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $owner->id, 'granted_at' => now(),
        ]);

        return [$owner, $patient];
    }
}

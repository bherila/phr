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
use App\Services\PHR\Encounters\PhrEncounterLinks;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
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
 * Attaching existing records to a visit or stay from its page (#449): the list of records
 * it could take, the batch attach with its per-record outcomes, and removal. Every value is
 * a synthetic stay of Pat Example at Example General Hospital; no real data.
 */
final class PhrOfficeVisitLinksTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    private User $owner;

    private PhrPatient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        $this->travelTo('2026-03-08 12:00:00');
        [$this->owner, $this->patient] = $this->patientWithOwner('Pat Example');
    }

    public function test_the_list_holds_the_standing_records_of_one_type_this_visit_could_take(): void
    {
        $stay = $this->stay();
        $other = $this->stay(['visit_date' => '2026-01-12', 'visit_started_at' => '2026-01-12 09:00:00', 'visit_ended_at' => '2026-01-13 10:00:00']);
        $creatinine = $this->lab('Creatinine', '2026-03-03 06:00:00');
        $potassium = $this->lab('Potassium', '2026-03-04 06:00:00');
        $onThisStay = $this->lab('Sodium', '2026-03-04 06:00:00');
        $onOther = $this->lab('Glucose', '2026-01-12 10:00:00');
        $this->lab('Rejected synthetic lab', '2026-03-03 06:00:00', ['review_status' => PhrReviewStatus::REJECTED]);
        $this->lab('Retracted synthetic lab', '2026-03-03 06:00:00', ['retracted_at' => now()]);
        $this->lab('Deleted synthetic lab', '2026-03-03 06:00:00')->delete();
        [, $stranger] = $this->patientWithOwner('Sam Example');
        PhrLabResult::query()->create(['patient_id' => $stranger->id, 'user_id' => $stranger->owner_user_id, 'test_name' => 'BMP', 'analyte' => 'Stranger lab', 'value' => '1', 'collection_datetime' => '2026-03-03 06:00:00']);
        $this->vital('Heart rate', '2026-03-03 08:00:00');
        $links = app(PhrEncounterLinks::class);
        $links->link((int) $this->patient->id, (int) $stay->id, 'lab-result', (int) $onThisStay->id);
        $links->link((int) $this->patient->id, (int) $other->id, 'lab-result', (int) $onOther->id);

        $listed = $this->actingAs($this->owner)->getJson($this->linkableUrl($stay, ['type' => 'lab-result']))->assertOk();

        $this->assertSame([$potassium->id, $creatinine->id, $onOther->id], array_column($listed->json('records'), 'id'), 'newest first; the rejected, retracted, deleted, already linked and the other patient\'s are left out');
        $this->assertSame([
            'id' => $potassium->id, 'type' => 'lab-result', 'name' => 'Potassium', 'date' => '2026-03-04 06:00:00',
            'detail' => '3.2 mmol/L', 'review_status' => PhrReviewStatus::PENDING, 'linked_visit' => null,
        ], $listed->json('records.0'));
        $this->assertSame(['id' => $other->id, 'date' => '2026-01-12', 'visit_type' => 'Synthetic hospital admission'], $listed->json('records.2.linked_visit'), 'a record on another stay names it');
        $this->assertSame($stay->id, $listed->json('office_visit_id'));
        $this->assertFalse($listed->json('pagination.has_more'));

        // Text and the natural date narrow it; the range is inclusive of both days.
        $this->assertSame([$creatinine->id], array_column($this->getJson($this->linkableUrl($stay, ['type' => 'lab-result', 'q' => 'creat']))->assertOk()->json('records'), 'id'));
        $this->assertSame([$potassium->id, $creatinine->id], array_column($this->getJson($this->linkableUrl($stay, ['type' => 'lab-result', 'from' => '2026-03-03', 'to' => '2026-03-04']))->assertOk()->json('records'), 'id'));
        $this->assertSame([$creatinine->id], array_column($this->getJson($this->linkableUrl($stay, ['type' => 'lab-result', 'from' => '2026-03-03', 'to' => '2026-03-03']))->assertOk()->json('records'), 'id'));
        $this->assertSame([$potassium->id, $creatinine->id], array_column($this->getJson($this->linkableUrl($stay, ['type' => 'lab-result', 'from' => '2026-03-02']))->assertOk()->json('records'), 'id'), 'an open end');
        $this->assertSame(['Heart rate'], array_column($this->getJson($this->linkableUrl($stay, ['type' => 'vital']))->assertOk()->json('records'), 'name'));
    }

    public function test_every_type_reads_its_own_name_and_natural_date_and_an_undated_record_falls_outside_a_range(): void
    {
        $stay = $this->stay();
        $this->vital('Blood pressure', null, ['vital_date' => '2026-03-03']);
        PhrMedication::query()->create(['patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'name' => 'ceftriaxone 1 g IV', 'dose' => '1', 'dose_unit' => 'g', 'started_on' => '2026-03-02', 'review_status' => PhrReviewStatus::PENDING]);
        $procedure = PhrProcedure::query()->create(['patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'name' => 'Synthetic chest radiograph', 'status' => 'completed', 'performed_at' => '2026-03-03 10:00:00', 'review_status' => PhrReviewStatus::PENDING]);
        $authored = $this->document('Daily Progress Note', ['authored_at' => '2026-03-04 07:00:00', 'observed_at' => '2026-03-01 00:00:00']);
        $dated = $this->document('Synthetic discharge summary', ['observed_at' => '2026-03-05 00:00:00']);
        $this->document('Synthetic undated letter');
        $this->actingAs($this->owner);

        $this->assertSame([['Blood pressure', '2026-03-03']], $this->namesAndDates($stay, 'vital'));
        $this->assertSame([['ceftriaxone 1 g IV', '2026-03-02']], $this->namesAndDates($stay, 'medication'));
        $this->assertSame('1 g', $this->getJson($this->linkableUrl($stay, ['type' => 'medication']))->json('records.0.detail'));
        $this->assertSame([['Synthetic chest radiograph', '2026-03-03 10:00:00']], $this->namesAndDates($stay, 'procedure'));
        $this->assertSame($procedure->id, $this->getJson($this->linkableUrl($stay, ['type' => 'procedure', 'q' => 'RADIO']))->json('records.0.id'));
        $documents = $this->namesAndDates($stay, 'document');
        $this->assertContains(['Daily Progress Note', '2026-03-04 07:00:00'], $documents, 'its authored time before its document date');
        $this->assertContains(['Synthetic discharge summary', '2026-03-05 00:00:00'], $documents);
        $this->assertContains(['Synthetic undated letter', null], $documents);
        $this->assertSame(
            [$dated->id, $authored->id],
            array_column($this->getJson($this->linkableUrl($stay, ['type' => 'document', 'from' => '2026-03-02', 'to' => '2026-03-06']))->json('records'), 'id'),
            'an undated record is outside any range',
        );
    }

    public function test_the_list_pages_on_a_cursor_across_records_that_share_a_date(): void
    {
        $stay = $this->stay();
        $ids = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $index => $suffix) {
            $ids[] = $this->lab('Synthetic lab '.$suffix, $index < 3 ? '2026-03-03 06:00:00' : '2026-03-04 06:00:00')->id;
        }
        $this->actingAs($this->owner);

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $this->getJson($this->linkableUrl($stay, ['type' => 'lab-result', 'limit' => 2, ...($cursor === null ? [] : ['cursor' => $cursor])]))->assertOk();
            $seen = [...$seen, ...array_column($page->json('records'), 'id')];
            $cursor = $page->json('pagination.next_cursor');
            $pages++;
        } while ($cursor !== null && $pages < 5);

        $this->assertSame(3, $pages);
        $this->assertSame([$ids[4], $ids[3], $ids[2], $ids[1], $ids[0]], $seen, 'newest date first, then newest record, each once');
        $this->getJson($this->linkableUrl($stay, ['type' => 'lab-result', 'cursor' => 'not-a-cursor']))->assertUnprocessable()->assertJsonValidationErrors('cursor');
        $this->getJson($this->linkableUrl($stay, ['type' => 'eob']))->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->getJson($this->linkableUrl($stay, ['type' => 'lab-result', 'from' => '2026-03-05', 'to' => '2026-03-04']))->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function stays(): iterable
    {
        yield 'an in-progress inpatient stay' => [['encounter_class' => 'IMP', 'encounter_state' => 'in-progress', 'visit_ended_at' => null]];
        yield 'a finished inpatient stay' => [['encounter_class' => 'IMP', 'encounter_state' => 'finished', 'visit_ended_at' => '2026-03-06 11:00:00']];
        yield 'a finished office visit' => [['encounter_class' => 'AMB', 'encounter_state' => 'finished', 'visit_type' => 'Synthetic office visit']];
    }

    /** @param  array<string, mixed>  $fields */
    #[DataProvider('stays')]
    public function test_a_person_attaches_several_records_and_each_refusal_is_reported_on_its_own(array $fields): void
    {
        $stay = $this->stay($fields);
        $elsewhere = $this->stay(['visit_date' => '2026-01-12', 'visit_started_at' => '2026-01-12 09:00:00']);
        $creatinine = $this->lab('Creatinine', '2026-03-03 06:00:00');
        $potassium = $this->lab('Potassium', '2026-03-03 06:00:00');
        $note = $this->document('Daily Progress Note', ['authored_at' => '2026-03-04 07:00:00']);
        $taken = $this->lab('Glucose', '2026-01-12 10:00:00');
        $rejected = $this->lab('Rejected synthetic lab', '2026-03-03 06:00:00', ['review_status' => PhrReviewStatus::REJECTED]);
        $retracted = $this->lab('Retracted synthetic lab', '2026-03-03 06:00:00', ['retracted_at' => now()]);
        [, $stranger] = $this->patientWithOwner('Sam Example');
        $strangers = PhrLabResult::query()->create(['patient_id' => $stranger->id, 'user_id' => $stranger->owner_user_id, 'test_name' => 'BMP', 'analyte' => 'Stranger lab', 'value' => '1']);
        app(PhrEncounterLinks::class)->link((int) $this->patient->id, (int) $elsewhere->id, 'lab-result', (int) $taken->id);
        $before = PhrLabResult::query()->whereKey($creatinine->id)->sole()->updated_at?->toDateTimeString();
        $this->travel(5)->minutes();

        $response = $this->actingAs($this->owner)->postJson($this->linksUrl($stay), ['links' => [
            ['type' => 'lab-result', 'id' => $creatinine->id],
            ['type' => 'lab-result', 'id' => $potassium->id],
            ['type' => 'document', 'id' => $note->id],
            ['type' => 'lab-result', 'id' => $taken->id],
            ['type' => 'lab-result', 'id' => $rejected->id],
            ['type' => 'lab-result', 'id' => $retracted->id],
            ['type' => 'lab-result', 'id' => $strangers->id],
        ]])->assertOk();

        $this->assertSame(['linked' => 3, 'unchanged' => 0, 'refused' => 4], $response->json('summary'));
        $this->assertSame(['linked', 'linked', 'linked', 'refused', 'refused', 'refused', 'refused'], array_column($response->json('results'), 'outcome'));
        $this->assertSame([null, null, null, 'linked_elsewhere', 'rejected', 'not_found', 'not_found'], array_map(static fn (array $result): ?string => $result['reason'] ?? null, $response->json('results')));
        $this->assertSame(['id' => $elsewhere->id, 'date' => '2026-01-12', 'visit_type' => 'Synthetic hospital admission'], $response->json('results.3.linked_visit'));
        $this->assertStringContainsString('Unlink it there first', (string) $response->json('results.3.message'));
        $this->assertSame([$taken->id => $elsewhere->id], app(PhrEncounterLinks::class)->encounterIds((int) $this->patient->id, 'lab-result', [$taken->id]), 'a refusal moves nothing');
        $this->assertSame(0, DB::table('phr_office_visit_lab_results')->where('patient_id', $stranger->id)->count());

        // A repeat is unchanged; a link is evidence, so the record itself is untouched.
        $this->postJson($this->linksUrl($stay), ['links' => [['type' => 'lab-result', 'id' => $creatinine->id]]])
            ->assertOk()->assertJsonPath('results.0.outcome', 'unchanged')->assertJsonPath('summary.unchanged', 1);
        $after = PhrLabResult::query()->whereKey($creatinine->id)->sole();
        $this->assertSame($before, $after->updated_at?->toDateTimeString());
        $this->assertSame(PhrReviewStatus::PENDING, $after->review_status);

        // The visit page lists them, and the agent's grouped read shows the same links.
        $page = $this->getJson("/api/phr/patients/{$this->patient->id}/office-visits/{$stay->id}")->assertOk();
        $this->assertEqualsCanonicalizing([$creatinine->id, $potassium->id], array_column($page->json('office_visit.linked_records.lab_results'), 'id'));
        $this->assertSame([$note->id], array_column($page->json('office_visit.linked_records.documents'), 'id'));
        $groups = $this->agentStay($stay)->json('data.groups');
        $this->assertSame([$creatinine->id, $potassium->id], array_column($groups['lab_results']['data'], 'id'));
        $this->assertSame([$note->id], array_column($groups['documents']['data'], 'id'));
    }

    public function test_a_record_on_another_stay_attaches_here_once_it_is_removed_there(): void
    {
        $stay = $this->stay();
        $other = $this->stay(['visit_date' => '2026-01-12', 'visit_started_at' => '2026-01-12 09:00:00']);
        $lab = $this->lab('Creatinine', '2026-03-03 06:00:00');
        $this->actingAs($this->owner);
        $this->postJson($this->linksUrl($other), ['links' => [['type' => 'lab-result', 'id' => $lab->id]]])->assertOk()->assertJsonPath('results.0.outcome', 'linked');
        $this->postJson($this->linksUrl($stay), ['links' => [['type' => 'lab-result', 'id' => $lab->id]]])->assertOk()->assertJsonPath('results.0.reason', 'linked_elsewhere');

        $this->deleteJson($this->linksUrl($other).'/lab-result/'.$lab->id)->assertOk()
            ->assertJson(['office_visit_id' => $other->id, 'type' => 'lab-result', 'id' => $lab->id, 'outcome' => 'unlinked']);
        $this->deleteJson($this->linksUrl($other).'/lab-result/'.$lab->id)->assertOk()->assertJsonPath('outcome', 'unchanged');
        $this->postJson($this->linksUrl($stay), ['links' => [['type' => 'lab-result', 'id' => $lab->id]]])->assertOk()->assertJsonPath('results.0.outcome', 'linked');

        $this->assertSame([], $this->getJson("/api/phr/patients/{$this->patient->id}/office-visits/{$other->id}")->json('office_visit.linked_records.lab_results'));
        $this->assertSame([$lab->id], array_column($this->agentStay($stay)->json('data.groups.lab_results.data'), 'id'));
        $this->deleteJson($this->linksUrl($stay).'/eob/'.$lab->id)->assertNotFound();
    }

    public function test_links_move_no_change_feed_entry_or_review_count_on_either_path(): void
    {
        $stay = $this->stay();
        $first = $this->lab('Creatinine', '2026-03-03 06:00:00');
        $second = $this->lab('Potassium', '2026-03-03 06:00:00');
        $this->travel(5)->minutes();
        $since = now()->toIso8601String();
        $this->travel(1)->minutes();
        $this->actingAs($this->owner);
        $summary = $this->getJson("/api/phr/patients/{$this->patient->id}/review/summary")->assertOk()->json('summary');

        $this->postJson($this->linksUrl($stay), ['links' => [['type' => 'lab-result', 'id' => $first->id]]])->assertOk();
        $this->assertSame($summary, $this->getJson("/api/phr/patients/{$this->patient->id}/review/summary")->assertOk()->json('summary'));

        $this->agentSession();
        $this->postJson("/api/v1/patients/{$this->patient->id}/office-visits/{$stay->id}/links", ['resource_type' => 'lab-result', 'resource_id' => $second->id])
            ->assertOk()->assertJsonPath('outcome', 'linked');
        $changes = $this->getJson("/api/v1/patients/{$this->patient->id}/changes?updated_after=".urlencode($since))->assertOk();
        $this->assertSame([], $changes->json('data'), 'neither the browser\'s link nor the agent\'s is a change to a record');
    }

    public function test_a_rejected_or_missing_visit_refuses_the_whole_request(): void
    {
        $rejected = $this->stay(['review_status' => PhrReviewStatus::REJECTED]);
        $withdrawn = $this->stay(['retracted_at' => now()]);
        [, $stranger] = $this->patientWithOwner('Sam Example');
        $strangersVisit = PhrOfficeVisit::query()->create(['patient_id' => $stranger->id, 'user_id' => $stranger->owner_user_id, 'visit_date' => '2026-03-02']);
        $lab = $this->lab('Creatinine', '2026-03-03 06:00:00');
        $body = ['links' => [['type' => 'lab-result', 'id' => $lab->id]]];
        $this->actingAs($this->owner);

        $this->postJson($this->linksUrl($rejected), $body)->assertConflict();
        $this->postJson($this->linksUrl($withdrawn), $body)->assertNotFound();
        $this->getJson($this->linkableUrl($withdrawn, ['type' => 'lab-result']))->assertNotFound();
        // Another patient's visit, under this patient or under its own.
        $this->postJson("/api/phr/patients/{$this->patient->id}/office-visits/{$strangersVisit->id}/links", $body)->assertNotFound();
        $this->getJson("/api/phr/patients/{$this->patient->id}/office-visits/{$strangersVisit->id}/linkable-records?type=lab-result")->assertNotFound();
        $this->deleteJson("/api/phr/patients/{$this->patient->id}/office-visits/{$strangersVisit->id}/links/lab-result/{$lab->id}")->assertNotFound();
        $this->getJson($this->linkableUrl($strangersVisit, ['type' => 'lab-result']))->assertNotFound();
        $this->assertSame(0, DB::table('phr_office_visit_lab_results')->count());

        $this->postJson($this->linksUrl($rejected), ['links' => []])->assertUnprocessable();
        $this->postJson($this->linksUrl($rejected), ['links' => array_fill(0, 101, ['type' => 'lab-result', 'id' => $lab->id])])->assertUnprocessable()->assertJsonValidationErrors('links');
        $this->postJson($this->linksUrl($rejected), ['links' => [['type' => 'eob', 'id' => $lab->id]]])->assertUnprocessable()->assertJsonValidationErrors('links.0.type');
    }

    public function test_a_viewer_reads_the_links_but_cannot_list_attach_or_remove(): void
    {
        $stay = $this->stay();
        $lab = $this->lab('Creatinine', '2026-03-03 06:00:00');
        app(PhrEncounterLinks::class)->link((int) $this->patient->id, (int) $stay->id, 'lab-result', (int) $lab->id);
        $viewer = $this->createUser();
        PhrPatientUserAccess::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $viewer->id, 'access_level' => PhrPatientUserAccess::LEVEL_VIEWER,
            'granted_by_user_id' => $this->owner->id, 'granted_at' => now(),
        ]);
        $this->actingAs($viewer);

        $this->getJson("/api/phr/patients/{$this->patient->id}/office-visits/{$stay->id}")->assertOk()
            ->assertJsonPath('can_manage', false)
            ->assertJsonPath('office_visit.linked_records.lab_results.0.id', $lab->id);
        $this->getJson($this->linkableUrl($stay, ['type' => 'lab-result']))->assertForbidden();
        $this->postJson($this->linksUrl($stay), ['links' => [['type' => 'lab-result', 'id' => $lab->id]]])->assertForbidden();
        $this->deleteJson($this->linksUrl($stay).'/lab-result/'.$lab->id)->assertForbidden();
        $this->assertSame(1, DB::table('phr_office_visit_lab_results')->count());

        // Someone with no access learns nothing.
        $this->actingAs($this->createUser());
        $this->getJson($this->linkableUrl($stay, ['type' => 'lab-result']))->assertNotFound();
        $this->postJson($this->linksUrl($stay), ['links' => [['type' => 'lab-result', 'id' => $lab->id]]])->assertNotFound();
        $this->deleteJson($this->linksUrl($stay).'/lab-result/'.$lab->id)->assertNotFound();
    }

    /** @return list<array{0: string, 1: string|null}> */
    private function namesAndDates(PhrOfficeVisit $visit, string $type): array
    {
        return array_map(
            static fn (array $record): array => [$record['name'], $record['date']],
            $this->getJson($this->linkableUrl($visit, ['type' => $type]))->assertOk()->json('records'),
        );
    }

    /** @param  array<string, mixed>  $query */
    private function linkableUrl(PhrOfficeVisit $visit, array $query): string
    {
        return "/api/phr/patients/{$visit->patient_id}/office-visits/{$visit->id}/linkable-records?".http_build_query($query);
    }

    private function linksUrl(PhrOfficeVisit $visit): string
    {
        return "/api/phr/patients/{$visit->patient_id}/office-visits/{$visit->id}/links";
    }

    /** @return TestResponse<Response> */
    private function agentStay(PhrOfficeVisit $visit): TestResponse
    {
        $this->agentSession();

        return $this->getJson("/api/v1/patients/{$visit->patient_id}/office-visits/{$visit->id}/stay")->assertOk();
    }

    private function agentSession(): void
    {
        $client = Client::query()->create([
            'name' => 'Synthetic Encounter Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        Passport::actingAs($this->owner, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE, AgentApiScopes::DOCUMENTS_READ], 'api', $client);
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
            'patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'test_name' => 'BMP', 'analyte' => $analyte,
            'value' => '3.2', 'unit' => 'mmol/L', 'collection_datetime' => $collected, 'review_status' => PhrReviewStatus::PENDING, ...$fields,
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

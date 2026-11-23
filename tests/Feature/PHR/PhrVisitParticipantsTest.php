<?php

namespace Tests\Feature\PHR;

use App\Models\PhrOfficeVisit;
use App\Models\PhrOfficeVisitParticipant;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Export\PhrCcdaExporter;
use App\Services\PHR\Export\PhrExportDataService;
use App\Services\PHR\Export\PhrFhirExporter;
use App\Services\PHR\Import\PhrCcdaImporter;
use App\Services\PHR\Import\PhrFhirImporter;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
use App\Support\PHR\PhrVisitParticipants;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use RuntimeException;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * A visit's care team (#412): written through MCP, read from FHIR Encounter.participant
 * and CareTeam and from C-CDA performers and encounterParticipants, and carried back out
 * through both. Every member is the synthetic care team of #405; no real data.
 */
final class PhrVisitParticipantsTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    private const string ROLES = 'http://terminology.hl7.org/CodeSystem/v3-ParticipationType';

    /** The synthetic care team as PHR stores it, in the source's order. */
    private const array TEAM = [
        ['name' => 'Alex Attending', 'credentials' => 'MD', 'role_code' => 'ATND', 'role_label' => 'Attending Physician', 'specialty' => null, 'is_primary' => true, 'period_start' => '2026-02-09 14:20:00', 'period_end' => null],
        ['name' => 'Robin Nurse', 'credentials' => 'RN', 'role_code' => 'PART', 'role_label' => 'Nurse', 'specialty' => null, 'is_primary' => true, 'period_start' => null, 'period_end' => null],
        ['name' => 'Sam Therapist', 'credentials' => 'RRT', 'role_code' => 'PART', 'role_label' => 'Respiratory Therapist', 'specialty' => null, 'is_primary' => false, 'period_start' => null, 'period_end' => null],
        ['name' => 'Casey Consultant', 'credentials' => 'MD', 'role_code' => 'CON', 'role_label' => 'Consultant', 'specialty' => 'Nephrology', 'is_primary' => false, 'period_start' => null, 'period_end' => null],
        ['name' => 'Jordan Fellow', 'credentials' => 'MD', 'role_code' => 'CON', 'role_label' => 'Fellow', 'specialty' => 'Nephrology', 'is_primary' => false, 'period_start' => null, 'period_end' => null],
        ['name' => 'Taylor Tech', 'credentials' => null, 'role_code' => 'PART', 'role_label' => 'Technician', 'specialty' => null, 'is_primary' => false, 'period_start' => '2026-02-09 15:00:00', 'period_end' => '2026-02-10 07:00:00'],
    ];

    private string $session;

    private int $nextId = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
    }

    public function test_the_synthetic_care_team_round_trips_from_a_care_team_through_phr_to_encounter_participants(): void
    {
        $patient = $this->patient();

        $result = app(PhrFhirImporter::class)->importBundle($this->careTeamBundle(), $patient, $patient->owner_user_id);

        // The CareTeam is the stay's team, not a record of its own; the Practitioners are skipped as before.
        $this->assertSame(1, $result->created);
        $visit = PhrOfficeVisit::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame('Alex Attending, MD', $visit->provider_name);
        $this->assertSame(self::TEAM, $this->team($visit));
        $this->assertSame(
            ['Practitioner/alex', 'Practitioner/robin', 'Practitioner/sam', 'PractitionerRole/casey-nephrology', 'PractitionerRole/jordan-nephrology', 'Practitioner/taylor'],
            $visit->participants->pluck('external_id')->all(),
            'the Encounter and the CareTeam name Alex once; the patient is not a member',
        );
        $this->assertSame(PhrVisitParticipants::digest(array_map(static fn (PhrOfficeVisitParticipant $member): array => $member->toMember(), $visit->participants->all())), $visit->participants_digest);

        $visit->forceFill(['review_status' => PhrReviewStatus::CONFIRMED])->save();
        $encounter = collect($this->fhirExport($patient)['entry'])->pluck('resource')->firstWhere('resourceType', 'Encounter');
        $this->assertCount(6, $encounter['participant']);
        $this->assertSame([
            'type' => [['coding' => [
                ['system' => self::ROLES, 'code' => 'ATND', 'display' => 'attender'],
                ['system' => self::ROLES, 'code' => 'PPRF', 'display' => 'primary performer'],
            ], 'text' => 'Attending Physician']],
            'period' => ['start' => '2026-02-09T14:20:00+00:00'],
            'individual' => ['reference' => '#participant-1', 'display' => 'Alex Attending, MD'],
        ], $encounter['participant'][0]);
        $this->assertSame(['start' => '2026-02-09T15:00:00+00:00', 'end' => '2026-02-10T07:00:00+00:00'], $encounter['participant'][5]['period'], 'the inactive technician keeps their end');
        $this->assertSame('#participant-4-role', $encounter['participant'][3]['individual']['reference']);
        $contained = collect($encounter['contained'])->keyBy('id');
        $this->assertSame([['text' => 'Casey Consultant', 'suffix' => ['MD']]], $contained['participant-4']['name']);
        $this->assertSame([['text' => 'Nephrology']], $contained['participant-4-role']['specialty']);
        $this->assertSame([['text' => 'Taylor Tech']], $contained['participant-6']['name']);

        // PHR's own export reads back as the same team, and again changes nothing.
        $other = $this->patient('Pat Example Copy');
        $exported = $this->fhirExport($patient);
        app(PhrFhirImporter::class)->importBundle($exported, $other, $other->owner_user_id);
        $copy = PhrOfficeVisit::query()->where('patient_id', $other->id)->sole();
        $this->assertSame('Alex Attending, MD', $copy->provider_name);
        $this->assertSame(self::TEAM, $this->team($copy));
        $again = app(PhrFhirImporter::class)->importBundle($exported, $other, $other->owner_user_id);
        $this->assertSame([0, 0], [$again->created, $again->updated]);
    }

    public function test_a_care_team_without_an_encounter_joins_the_one_stay_its_period_falls_within_and_is_never_guessed(): void
    {
        $patient = $this->patient();
        $team = fn (string $id, array $extra): array => ['resource' => [
            'resourceType' => 'CareTeam', 'id' => $id, 'status' => 'active',
            'participant' => [['role' => [['text' => 'Attending Physician']], 'member' => ['display' => 'Alex Attending, MD']]],
            ...$extra,
        ]];
        $bundle = ['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            ['resource' => ['resourceType' => 'Encounter', 'id' => 'stay', 'status' => 'finished', 'period' => ['start' => '2026-02-09T14:20:00Z', 'end' => '2026-02-14T11:00:00Z']]],
            ['resource' => ['resourceType' => 'Encounter', 'id' => 'earlier', 'status' => 'finished', 'period' => ['start' => '2025-11-03', 'end' => '2025-11-06']]],
            $team('within', ['period' => ['start' => '2026-02-09', 'end' => '2026-02-12']]),
            $team('outside', ['period' => ['start' => '2026-03-01']]),
            $team('elsewhere', ['encounter' => ['reference' => 'Encounter/not-in-this-bundle'], 'period' => ['start' => '2026-02-10']]),
        ]];

        $result = app(PhrFhirImporter::class)->importBundle($bundle, $patient, $patient->owner_user_id);

        $this->assertSame([2, 2], [$result->created, $result->skipped], 'the team outside any stay and the one naming another encounter stay unread');
        $stay = PhrOfficeVisit::query()->where('patient_id', $patient->id)->where('external_id', 'Encounter/stay')->sole();
        $this->assertSame([['Alex Attending, MD', 'ATND', 'Attending Physician']], $stay->participants->map(static fn (PhrOfficeVisitParticipant $member): array => [$member->name, $member->role_code, $member->role_label])->all(), 'a role named only by its words is read from them');
        $this->assertSame('Alex Attending, MD', $stay->provider_name);
        $this->assertSame(0, PhrOfficeVisit::query()->where('external_id', 'Encounter/earlier')->sole()->participants()->count());
    }

    public function test_the_team_written_over_mcp_replaces_the_visits_team_and_keeps_its_provider_in_sync(): void
    {
        [, $patient] = $this->mcpSession();

        $created = $this->tool('office_visits.upsert', $this->mcpVisit($patient, ['participants' => $this->apiTeam()]));
        $this->assertFalse($created['isError'] ?? false, json_encode($created));
        $data = $created['structuredContent']['data'];
        $this->assertSame('Alex Attending, MD', $data['provider_name'], 'the primary attending names the visit');
        $this->assertSame(array_column(self::TEAM, 'name'), array_column($data['participants'], 'name'));
        $this->assertSame([true, true, false, false, false, false], array_column($data['participants'], 'is_primary'));
        $this->assertSame('2026-02-10 07:00:00', $data['participants'][5]['period_end']);
        $this->assertSame(PhrReviewStatus::PENDING, $data['review_status']);
        $version = $created['structuredContent']['version'];

        // The same team again is a retry, not a change.
        $same = $this->tool('office_visits.upsert', $this->mcpVisit($patient, ['participants' => $this->apiTeam()]));
        $this->assertSame('unchanged', $same['structuredContent']['outcome']);
        $this->assertSame($version, $same['structuredContent']['version']);

        // A changed team is a change to the visit: it needs the current version.
        $smaller = array_slice($this->apiTeam(), 0, 2);
        $stale = $this->tool('office_visits.upsert', [...$this->mcpVisit($patient, ['participants' => $smaller]), 'expected_version' => str_repeat('0', 64)]);
        $this->assertTrue($stale['isError'] ?? false, json_encode($stale));
        $this->assertSame(6, PhrOfficeVisitParticipant::query()->where('office_visit_id', $data['id'])->count());

        PhrOfficeVisit::query()->whereKey($data['id'])->update(['review_status' => PhrReviewStatus::CONFIRMED]);
        $read = $this->tool('clinical.get', ['resource_type' => 'office-visits', 'patient_id' => $patient->id, 'record_id' => $data['id']]);
        $updated = $this->tool('office_visits.upsert', [...$this->mcpVisit($patient, ['participants' => $smaller]), 'expected_version' => $read['structuredContent']['data']['version']]);
        $this->assertSame('updated', $updated['structuredContent']['outcome']);
        $this->assertSame(['Alex Attending', 'Robin Nurse'], array_column($updated['structuredContent']['data']['participants'], 'name'));
        $this->assertSame(PhrReviewStatus::PENDING, $updated['structuredContent']['data']['review_status'], 'a changed team reopens review');

        // A writer that names the provider keeps it, and the update tool replaces the team too.
        $patched = $this->tool('office_visits.update', [
            'patient_id' => $patient->id, 'record_id' => $data['id'], 'expected_version' => $updated['structuredContent']['version'],
            'data' => ['provider_name' => 'Casey Consultant, MD', 'participants' => [$this->apiTeam()[3]]],
        ]);
        $this->assertFalse($patched['isError'] ?? false, json_encode($patched));
        $this->assertSame('Casey Consultant, MD', $patched['structuredContent']['data']['provider_name']);
        $this->assertSame(['Casey Consultant'], array_column($patched['structuredContent']['data']['participants'], 'name'));

        $listed = $this->tool('clinical.list', ['resource_type' => 'office-visits', 'patient_id' => $patient->id]);
        $this->assertSame($patched['structuredContent']['data']['participants'], $listed['structuredContent']['data'][0]['participants']);
        $this->assertSame($patched['structuredContent']['version'], $listed['structuredContent']['data'][0]['version']);

        // An empty list clears the team and leaves the provider as it is.
        $cleared = $this->tool('office_visits.update', [
            'patient_id' => $patient->id, 'record_id' => $data['id'], 'expected_version' => $patched['structuredContent']['version'],
            'data' => ['participants' => []],
        ]);
        $this->assertSame([], $cleared['structuredContent']['data']['participants']);
        $this->assertSame('Casey Consultant, MD', $cleared['structuredContent']['data']['provider_name']);
        $this->assertNull(PhrOfficeVisit::query()->findOrFail($data['id'])->participants_digest);

        $bad = $this->mcp(['jsonrpc' => '2.0', 'id' => ++$this->nextId, 'method' => 'tools/call', 'params' => ['name' => 'office_visits.upsert', 'arguments' => $this->mcpVisit($patient, ['participants' => [['name' => 'Synthetic Person', 'role_code' => 'NURSE']]])]]);
        $this->assertSame('/data/participants/0/role_code', $bad->json('error.data.validation_errors.0.pointer'), 'only a ParticipationType code is a role');
    }

    public function test_the_care_team_round_trips_through_ccda_performers(): void
    {
        $patient = $this->patient();
        $visit = PhrOfficeVisit::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'review_status' => PhrReviewStatus::CONFIRMED,
            'visit_type' => 'Synthetic hospital admission',
            'visit_date' => '2026-02-09',
            'visit_started_at' => '2026-02-09 14:20:00',
            'facility_name' => 'Example General Hospital',
            'location' => '6 North Telemetry',
            'encounter_class' => 'IMP',
            'participants' => $this->apiTeam(),
        ]);
        $this->assertSame('Alex Attending, MD', $visit->refresh()->provider_name);

        $xml = app(PhrCcdaExporter::class)->documentXml(app(PhrExportDataService::class)->load($patient));
        $this->assertStringContainsString('<sdtc:functionCode code="ATND" codeSystem="2.16.840.1.113883.5.90"', $xml);
        $this->assertStringContainsString('<name>Alex Attending<suffix>MD</suffix></name>', $xml);
        $this->assertStringContainsString('care team Alex Attending, MD (Attending Physician, primary)', $xml, 'the narrative names the team its entries carry');

        $other = $this->patient('Pat Example Copy');
        $first = app(PhrCcdaImporter::class)->importXml($xml, $other, $other->owner_user_id);
        $again = app(PhrCcdaImporter::class)->importXml($xml, $other, $other->owner_user_id);

        $copy = PhrOfficeVisit::query()->where('patient_id', $other->id)->sole();
        $this->assertSame([1, 0, 0], [$first->created, $again->created, $again->updated]);
        $this->assertSame('Alex Attending, MD', $copy->provider_name);
        $this->assertSame('Example General Hospital', $copy->facility_name, 'the first performer still carries the facility');
        $this->assertSame(self::TEAM, $this->team($copy));
    }

    public function test_a_documents_encounter_participants_are_its_visits_team(): void
    {
        $patient = $this->patient();
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <ClinicalDocument xmlns="urn:hl7-org:v3">
              <id root="2.25.4120"/>
              <title>Synthetic Discharge Summary</title>
              <componentOf>
                <encompassingEncounter>
                  <id root="2.25.412412" extension="synthetic-stay"/>
                  <code code="IMP" codeSystem="2.16.840.1.113883.5.4"/>
                  <effectiveTime><low value="20260209142000"/></effectiveTime>
                  <encounterParticipant typeCode="ATND">
                    <assignedEntity><id root="2.25.4121"/><assignedPerson><name><given>Alex</given><family>Attending</family><suffix>MD</suffix></name></assignedPerson></assignedEntity>
                  </encounterParticipant>
                  <encounterParticipant typeCode="CON">
                    <time><low value="20260210"/><high value="20260212"/></time>
                    <assignedEntity><id root="2.25.4122"/><code code="207RN0300X" codeSystem="2.16.840.1.113883.6.101" displayName="Nephrology"/><assignedPerson><name><given>Casey</given><family>Consultant</family><suffix>MD</suffix></name></assignedPerson></assignedEntity>
                  </encounterParticipant>
                  <encounterParticipant typeCode="ATND">
                    <assignedEntity><id root="2.25.4123"/><representedOrganization><name>Example General Hospital</name></representedOrganization></assignedEntity>
                  </encounterParticipant>
                </encompassingEncounter>
              </componentOf>
              <component><structuredBody><component><section><title>Synthetic Note</title><text>Synthetic text</text></section></component></structuredBody></component>
            </ClinicalDocument>
            XML;

        app(PhrCcdaImporter::class)->importXml($xml, $patient, $patient->owner_user_id);

        $visit = PhrOfficeVisit::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame('Alex Attending, MD', $visit->provider_name);
        $this->assertSame([
            ['Alex Attending', 'MD', 'ATND', null, null, null, '2.25.4121'],
            ['Casey Consultant', 'MD', 'CON', 'Nephrology', '2026-02-10 00:00:00', '2026-02-12 00:00:00', '2.25.4122'],
        ], $visit->participants->map(static fn (PhrOfficeVisitParticipant $member): array => [
            $member->name, $member->credentials, $member->role_code, $member->specialty,
            $member->period_start?->toDateTimeString(), $member->period_end?->toDateTimeString(), $member->external_id,
        ])->all(), 'an organization is not a member');
    }

    public function test_a_browser_team_replacement_is_one_locked_transaction_and_never_leaves_rows_the_digest_does_not_describe(): void
    {
        $patient = $this->patient();
        $visit = $this->storedVisit($patient);
        $owner = User::query()->findOrFail($patient->owner_user_id);
        $baseline = DB::transactionLevel();
        $queries = [];
        $failOnReplace = false;
        DB::listen(static function (QueryExecuted $query) use (&$queries, &$failOnReplace): void {
            $queries[] = [$query->sql, $query->connection->transactionLevel()];
            if ($failOnReplace && preg_match('/^delete from [`"]?phr_office_visit_participants\b/i', $query->sql) === 1) {
                throw new RuntimeException('Synthetic failure between the old team leaving and the new one arriving');
            }
        });

        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patient->id}/office-visits/{$visit->id}", [
            'participants' => array_slice($this->apiTeam(), 0, 2),
        ])->assertOk()->assertJsonPath('office_visit.participants.1.name', 'Robin Nurse');

        // The visit's own write and the team's replacement share one transaction, which
        // locked the visit before writing it: a concurrent replacement waits for this one.
        $levels = [];
        $locked = null;
        foreach ($queries as $index => [$sql, $level]) {
            foreach (['visit' => '/^update [`"]?phr_office_visits[`"]? /i', 'delete' => '/^delete from [`"]?phr_office_visit_participants\b/i', 'insert' => '/^insert into [`"]?phr_office_visit_participants\b/i'] as $step => $pattern) {
                if (preg_match($pattern, $sql) === 1) {
                    $levels[$step] ??= [$index, $level];
                }
            }
            if (preg_match('/^select .* from [`"]?phr_office_visits[`"]? .* for update$/is', $sql) === 1) {
                $locked ??= $index;
            }
        }
        $this->assertSame(['visit', 'delete', 'insert'], array_keys($levels));
        foreach ($levels as $step => [, $level]) {
            $this->assertGreaterThan($baseline, $level, "the {$step} runs inside the write's transaction");
        }
        if (DB::connection()->getDriverName() !== 'sqlite') {
            // SQLite has no row locks; its writer lock already serializes the transaction.
            $this->assertNotNull($locked, 'the visit row is locked');
            $this->assertLessThan($levels['visit'][0], $locked, 'before it is written');
        }

        // A replacement that fails part way leaves the team and its digest as they were.
        $failOnReplace = true;
        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patient->id}/office-visits/{$visit->id}", [
            'participants' => [$this->apiTeam()[3]],
        ])->assertServerError();
        $failOnReplace = false;
        $this->assertTeamMatchesDigest($visit, ['Alex Attending', 'Robin Nurse']);
    }

    public function test_every_save_that_replaces_a_team_commits_the_visit_and_its_team_together(): void
    {
        $patient = $this->patient();
        $visit = $this->storedVisit($patient);
        $armed = true;
        DB::listen(static function (QueryExecuted $query) use (&$armed): void {
            if ($armed && preg_match('/^insert into [`"]?phr_office_visit_participants\b/i', $query->sql) === 1) {
                throw new RuntimeException('Synthetic failure after the old team was deleted');
            }
        });

        // An import or reconciler writes a visit with save(), not through a controller.
        try {
            PhrOfficeVisit::query()->findOrFail($visit->id)->update(['participants' => [$this->apiTeam()[3]]]);
            $this->fail('The synthetic failure did not happen');
        } catch (RuntimeException) {
        }
        $armed = false;

        $this->assertTeamMatchesDigest($visit, array_column(self::TEAM, 'name'));
    }

    public function test_an_offset_period_keeps_its_instant_through_fhir_and_ccda(): void
    {
        $patient = $this->patient();
        $bundle = ['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            ['resource' => [
                'resourceType' => 'Encounter', 'id' => 'offset-stay', 'status' => 'finished',
                'class' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'IMP'],
                'period' => ['start' => '2026-02-09T14:20:00Z', 'end' => '2026-02-14T11:00:00Z'],
                'participant' => [[
                    'type' => [['coding' => [['system' => self::ROLES, 'code' => 'ATND']]]],
                    'individual' => ['display' => 'Alex Attending, MD'],
                    'period' => ['start' => '2026-02-09T14:20:00-05:00', 'end' => '2026-02-10T07:00:00-05:00'],
                ]],
            ]],
        ]];

        app(PhrFhirImporter::class)->importBundle($bundle, $patient, $patient->owner_user_id);

        $visit = PhrOfficeVisit::query()->where('patient_id', $patient->id)->sole();
        $member = $visit->participants->sole();
        $this->assertSame(['2026-02-09 19:20:00', '2026-02-10 12:00:00'], [$member->period_start?->toDateTimeString(), $member->period_end?->toDateTimeString()], 'stored as the UTC instant');

        $visit->forceFill(['review_status' => PhrReviewStatus::CONFIRMED])->save();
        $encounter = collect($this->fhirExport($patient)['entry'])->pluck('resource')->firstWhere('resourceType', 'Encounter');
        $this->assertSameInstant('2026-02-09T14:20:00-05:00', $encounter['participant'][0]['period']['start']);
        $this->assertSameInstant('2026-02-10T07:00:00-05:00', $encounter['participant'][0]['period']['end']);

        $xml = app(PhrCcdaExporter::class)->documentXml(app(PhrExportDataService::class)->load($patient));
        $this->assertStringContainsString('<low value="20260209192000+0000"/><high value="20260210120000+0000"/>', $xml);

        // A C-CDA stating the same instant in its own zone stores the same UTC time.
        $zoned = str_replace(['20260209192000+0000', '20260210120000+0000'], ['20260209142000-0500', '20260210070000-0500'], $xml);
        $other = $this->patient('Pat Example Copy');
        app(PhrCcdaImporter::class)->importXml($zoned, $other, $other->owner_user_id);
        $copy = PhrOfficeVisit::query()->where('patient_id', $other->id)->sole();
        $copied = $copy->participants->sole();
        $this->assertSame(['2026-02-09 19:20:00', '2026-02-10 12:00:00'], [$copied->period_start?->toDateTimeString(), $copied->period_end?->toDateTimeString()]);

        $copy->forceFill(['review_status' => PhrReviewStatus::CONFIRMED])->save();
        $again = app(PhrCcdaExporter::class)->documentXml(app(PhrExportDataService::class)->load($other));
        $this->assertStringContainsString('<low value="20260209192000+0000"/><high value="20260210120000+0000"/>', $again);
    }

    public function test_a_bundled_practitioner_role_names_its_own_contained_practitioner_and_a_contained_role_its_containers(): void
    {
        $patient = $this->patient();
        $bundle = ['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            ['resource' => [
                'resourceType' => 'Encounter', 'id' => 'role-stay', 'status' => 'finished',
                'period' => ['start' => '2026-02-09T14:20:00Z'],
                // A contained role's `#` reference is to its container's contained list.
                'contained' => [
                    ['resourceType' => 'Practitioner', 'id' => 'person', 'name' => [['given' => ['Casey'], 'family' => 'Consultant', 'suffix' => ['MD']]]],
                    ['resourceType' => 'PractitionerRole', 'id' => 'casey-role', 'practitioner' => ['reference' => '#person'], 'specialty' => [['text' => 'Nephrology']]],
                ],
                'participant' => [
                    ['type' => [['coding' => [['system' => self::ROLES, 'code' => 'ATND']]]], 'individual' => ['reference' => 'PractitionerRole/alex-role']],
                    ['type' => [['coding' => [['system' => self::ROLES, 'code' => 'CON']]]], 'individual' => ['reference' => '#casey-role']],
                ],
            ]],
            // A bundled role's `#person` is its own contained Practitioner, not the Encounter's.
            ['resource' => [
                'resourceType' => 'PractitionerRole', 'id' => 'alex-role',
                'contained' => [['resourceType' => 'Practitioner', 'id' => 'person', 'name' => [['given' => ['Alex'], 'family' => 'Attending', 'suffix' => ['MD']]]]],
                'practitioner' => ['reference' => '#person'],
                'specialty' => [['text' => 'Hospital Medicine']],
            ]],
        ]];

        app(PhrFhirImporter::class)->importBundle($bundle, $patient, $patient->owner_user_id);

        $visit = PhrOfficeVisit::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame([
            ['Alex Attending', 'MD', 'ATND', 'Hospital Medicine', 'PractitionerRole/alex-role'],
            ['Casey Consultant', 'MD', 'CON', 'Nephrology', null],
        ], $visit->participants->map(static fn (PhrOfficeVisitParticipant $member): array => [
            $member->name, $member->credentials, $member->role_code, $member->specialty, $member->external_id,
        ])->all());
        $this->assertSame('Alex Attending, MD', $visit->provider_name);
    }

    private function storedVisit(PhrPatient $patient): PhrOfficeVisit
    {
        return PhrOfficeVisit::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'visit_type' => 'Synthetic hospital admission',
            'visit_date' => '2026-02-09',
            'participants' => $this->apiTeam(),
        ]);
    }

    /** @param  list<string>  $names */
    private function assertTeamMatchesDigest(PhrOfficeVisit $visit, array $names): void
    {
        $stored = PhrOfficeVisit::query()->findOrFail($visit->id);
        $members = $stored->participants()->get();
        $this->assertSame($names, $members->pluck('name')->all());
        $this->assertSame(PhrVisitParticipants::digest(array_map(static fn (PhrOfficeVisitParticipant $member): array => $member->toMember(), $members->all())), $stored->participants_digest, 'the digest describes the rows stored');
    }

    private function assertSameInstant(string $expected, mixed $actual): void
    {
        $this->assertIsString($actual);
        $this->assertTrue(Carbon::parse($expected)->equalTo(Carbon::parse($actual)), "{$actual} is the instant {$expected}");
    }

    /**
     * The team as stored, without its database and source identifiers.
     *
     * @return list<array<string, mixed>>
     */
    private function team(PhrOfficeVisit $visit): array
    {
        return $visit->participants()->get()->map(static function (PhrOfficeVisitParticipant $member): array {
            $stored = $member->toMember();
            unset($stored['external_id']);

            return $stored;
        })->all();
    }

    /** @return list<array<string, mixed>> the synthetic team as an API client writes it */
    private function apiTeam(): array
    {
        return array_map(static fn (array $member): array => array_filter([
            ...$member,
            'period_start' => $member['period_start'] === null ? null : str_replace(' ', 'T', $member['period_start']).'Z',
            'period_end' => $member['period_end'] === null ? null : str_replace(' ', 'T', $member['period_end']).'Z',
        ], static fn (mixed $value): bool => $value !== null), self::TEAM);
    }

    /** @return array<string, mixed> */
    private function careTeamBundle(): array
    {
        $role = static fn (?string $text, string ...$codes): array => array_filter([
            'coding' => array_map(static fn (string $code): array => ['system' => self::ROLES, 'code' => $code], $codes),
            'text' => $text,
        ]);
        $practitioner = static fn (string $id, string $given, string $family, ?string $suffix): array => ['fullUrl' => 'urn:uuid:practitioner-'.$id, 'resource' => array_filter([
            'resourceType' => 'Practitioner', 'id' => $id,
            'name' => [array_filter(['given' => [$given], 'family' => $family, 'suffix' => $suffix === null ? null : [$suffix]])],
        ])];
        $nephrology = static fn (string $id, string $practitionerId): array => ['resource' => [
            'resourceType' => 'PractitionerRole', 'id' => $id,
            'practitioner' => ['reference' => 'Practitioner/'.$practitionerId],
            'specialty' => [['coding' => [['system' => 'http://nucc.org/provider-taxonomy', 'code' => '207RN0300X', 'display' => 'Nephrology Physician']], 'text' => 'Nephrology']],
        ]];

        return ['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            ['resource' => ['resourceType' => 'Patient', 'id' => 'pat', 'name' => [['text' => 'Pat Example']]]],
            ['fullUrl' => 'urn:uuid:encounter-synthetic-stay', 'resource' => [
                'resourceType' => 'Encounter',
                'id' => 'synthetic-stay',
                'status' => 'in-progress',
                'class' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'IMP'],
                'type' => [['text' => 'Synthetic hospital admission']],
                'period' => ['start' => '2026-02-09T14:20:00-05:00'],
                // The attending is on the Encounter too; the CareTeam says more about them.
                'participant' => [['type' => [$role(null, 'ATND')], 'individual' => ['reference' => 'Practitioner/alex']]],
                'serviceProvider' => ['display' => 'Example General Hospital'],
            ]],
            ['resource' => [
                'resourceType' => 'CareTeam',
                'id' => 'synthetic-stay-team',
                'status' => 'active',
                'subject' => ['reference' => 'Patient/pat'],
                'encounter' => ['reference' => 'Encounter/synthetic-stay'],
                'period' => ['start' => '2026-02-09T14:20:00-05:00'],
                // Member periods are TEAM's UTC instants stated at -05:00; PHR stores them as UTC.
                'participant' => [
                    ['role' => [$role('Attending Physician', 'ATND', 'PPRF')], 'member' => ['reference' => 'Practitioner/alex'], 'period' => ['start' => '2026-02-09T09:20:00-05:00']],
                    ['role' => [$role('Nurse', 'PART', 'PPRF')], 'member' => ['reference' => 'Practitioner/robin']],
                    ['role' => [['coding' => [['system' => 'http://snomed.info/sct', 'code' => '159010006', 'display' => 'Respiratory therapist']], 'text' => 'Respiratory Therapist']], 'member' => ['reference' => 'Practitioner/sam']],
                    ['role' => [$role('Consultant', 'CON')], 'member' => ['reference' => 'PractitionerRole/casey-nephrology']],
                    ['role' => [$role('Fellow', 'CON')], 'member' => ['reference' => 'PractitionerRole/jordan-nephrology']],
                    ['role' => [['text' => 'Technician']], 'member' => ['reference' => 'Practitioner/taylor'], 'period' => ['start' => '2026-02-09T10:00:00-05:00', 'end' => '2026-02-10T02:00:00-05:00']],
                    ['role' => [['text' => 'Patient']], 'member' => ['reference' => 'Patient/pat']],
                ],
            ]],
            $practitioner('alex', 'Alex', 'Attending', 'MD'),
            $practitioner('robin', 'Robin', 'Nurse', 'RN'),
            $practitioner('sam', 'Sam', 'Therapist', 'RRT'),
            $practitioner('casey', 'Casey', 'Consultant', 'MD'),
            $practitioner('jordan', 'Jordan', 'Fellow', 'MD'),
            $practitioner('taylor', 'Taylor', 'Tech', null),
            $nephrology('casey-nephrology', 'casey'),
            $nephrology('jordan-nephrology', 'jordan'),
        ]];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mcpVisit(PhrPatient $patient, array $overrides): array
    {
        return [
            'patient_id' => $patient->id,
            'external_id' => 'synthetic-stay-412',
            'source_document_id' => null,
            'expected_version' => null,
            'data' => [
                'visit_date' => '2026-02-09',
                'visit_started_at' => '2026-02-09T14:20:00Z',
                'visit_type' => 'Synthetic hospital admission',
                'facility_name' => 'Example General Hospital',
                'encounter_class' => 'IMP',
                'encounter_state' => 'in-progress',
                'location' => '6 North Telemetry',
                ...$overrides,
            ],
        ];
    }

    /** @return array{0: User, 1: PhrPatient} */
    private function mcpSession(): array
    {
        $patient = $this->patient();
        $owner = User::query()->findOrFail($patient->owner_user_id);
        $client = Client::query()->create([
            'name' => 'Synthetic Care Team Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        Passport::actingAs($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $init = $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1.0.0'],
        ]]);
        $this->session = (string) $init->headers->get('Mcp-Session-Id');

        return [$owner, $patient];
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

    /** @param  array<string, mixed>  $message */
    private function mcp(array $message): TestResponse
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        if (isset($this->session) && $message['method'] !== 'initialize') {
            $headers['Mcp-Session-Id'] = $this->session;
        }

        return $this->postJson('/api/v1/mcp', $message, $headers)->assertOk();
    }

    /** @return array<string, mixed> */
    private function fhirExport(PhrPatient $patient): array
    {
        return json_decode(app(PhrFhirExporter::class)->bundleJson(app(PhrExportDataService::class)->load($patient)), true, flags: JSON_THROW_ON_ERROR);
    }

    private function patient(string $name = 'Pat Example'): PhrPatient
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
        Carbon::setTestNow('2026-03-01 12:00:00');

        return $patient;
    }
}

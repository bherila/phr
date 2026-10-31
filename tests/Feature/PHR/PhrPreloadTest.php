<?php

namespace Tests\Feature\PHR;

use App\Http\Controllers\PHR\LabResultController;
use App\Models\PhrDocument;
use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\PhrPatientVital;
use App\Models\User;
use App\Services\PHR\Preload\PhrPreloadBuilder;
use App\Services\PHR\Preload\PhrPreloadPlan;
use App\Support\PHR\VitalMetricResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class PhrPreloadTest extends TestCase
{
    private User $owner;

    private PhrPatient $patient;

    /** @var array<string, int|string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Storage::fake(PhrDocument::STORAGE_DISK);
        $this->owner = $this->createUser();
        $this->patient = PhrPatient::query()->create([
            'owner_user_id' => $this->owner->id,
            'display_name' => '</script><script>alert(1)</script>',
            'notes' => 'Synthetic <!-- notes --> & "quotes" \'apostrophes\' 雪',
        ]);
        $this->grant($this->patient, $this->owner, PhrPatientUserAccess::LEVEL_OWNER);
    }

    public function test_every_preloaded_url_is_exactly_the_api_response_and_only_the_views_on_screen_are_preloaded(): void
    {
        $this->seedRecords();
        $p = "/api/phr/patients/{$this->patient->id}";
        $lists = ["{$p}/office-visits", "{$p}/procedures", "{$p}/immunizations"];
        $summary = "{$p}/review/summary";
        $expected = [
            '' => [$p, "{$p}/lab-results", "{$p}/vitals", "{$p}/dicom/studies", "{$p}/exports"],
            '/summary' => [$p, "{$p}/lab-results", "{$p}/vitals", "{$p}/dicom/studies", "{$p}/exports"],
            '/review' => ["{$p}/review"],
            // The first log's entries follow from the preloaded list, so they come last.
            '/health-log' => ["{$p}/health-logs", $summary, "{$p}/health-logs/{$this->ids['log']}/entries"],
            '/labs' => ["{$p}/lab-results"],
            "/labs/{$this->ids['lab']}" => ["{$p}/labs/{$this->ids['lab']}", "{$p}/lab-results"],
            '/vitals' => ["{$p}/vitals"],
            "/vitals/{$this->ids['vital']}" => ["{$p}/vitals/{$this->ids['vital']}", "{$p}/vitals"],
            // A trend opens as its own page, so the list is not on screen.
            "/vitals/trend/{$this->ids['metric']}" => ["{$p}/vitals/trend/{$this->ids['metric']}"],
            '/office-visits' => $lists,
            "/office-visits/{$this->ids['visit']}" => ["{$p}/office-visits/{$this->ids['visit']}", ...$lists],
            "/office-visits/procedure/{$this->ids['procedure']}" => ["{$p}/procedures/{$this->ids['procedure']}", ...$lists],
            "/office-visits/immunization/{$this->ids['immunization']}" => ["{$p}/immunizations/{$this->ids['immunization']}", ...$lists],
            '/medications' => ["{$p}/medications"],
            "/medications/{$this->ids['medication']}" => ["{$p}/medications/{$this->ids['medication']}", "{$p}/medications"],
            '/conditions' => ["{$p}/conditions"],
            "/conditions/{$this->ids['condition']}" => ["{$p}/conditions/{$this->ids['condition']}", "{$p}/conditions"],
            '/procedures' => ["{$p}/procedures"],
            "/procedures/{$this->ids['procedure']}" => ["{$p}/procedures/{$this->ids['procedure']}", "{$p}/procedures"],
            '/immunizations' => ["{$p}/immunizations"],
            "/immunizations/{$this->ids['immunization']}" => ["{$p}/immunizations/{$this->ids['immunization']}", "{$p}/immunizations"],
            '/allergies' => ["{$p}/allergies"],
            "/allergies/{$this->ids['allergy']}" => ["{$p}/allergies/{$this->ids['allergy']}", "{$p}/allergies"],
            '/documents' => ["{$p}/documents"],
            "/documents/{$this->ids['document']}" => ["{$p}/documents/{$this->ids['document']}"],
            '/imaging' => ["{$p}/dicom/studies", $p],
            '/access' => [$p],
            '/access/5' => [$p],
        ];
        // The sidebar's review counts load on every patient view, after the page's own requests.
        $expected = array_map(static fn (array $urls): array => in_array($summary, $urls, true) ? $urls : [...$urls, $summary], $expected);

        foreach ($expected as $suffix => $urls) {
            $html = $this->actingAs($this->owner)->get("/phr/patient/{$this->patient->id}{$suffix}")->assertOk();
            $preload = $this->preload($html);
            $this->assertSame($urls, array_keys($preload), "Preloaded URLs for {$suffix}");
            $this->assertPreloadIsTheApiResponse($this->owner, $preload);
        }

        foreach ([
            '/phr/patients' => ['/api/phr/patients/overview'],
            '/phr/data-hub' => ['/api/phr/data-hub', '/api/phr/data-hub/deletions', '/api/phr/data-hub/native-restores'],
            '/phr/config' => ['/api/user/ai-prefs', '/api/user/ai-execution-mode'],
        ] as $path => $urls) {
            $preload = $this->preload($this->actingAs($this->owner)->get($path)->assertOk());
            $this->assertSame($urls, array_keys($preload), "Preloaded URLs for {$path}");
            $this->assertPreloadIsTheApiResponse($this->owner, $preload);
        }

        // The patient list already arrives with every shell; Imports picks its patient in the browser.
        foreach (['/phr/patients/manage', '/phr/imports'] as $path) {
            $this->assertNull($this->preload($this->actingAs($this->owner)->get($path)->assertOk()), $path);
        }
    }

    public function test_delegates_and_viewers_get_their_own_responses_and_others_get_the_same_404_without_a_preload(): void
    {
        $this->seedRecords();
        $manager = $this->createUser();
        $viewer = $this->createUser();
        $outsider = $this->createUser();
        $this->grant($this->patient, $manager, PhrPatientUserAccess::LEVEL_MANAGER);
        $this->grant($this->patient, $viewer, PhrPatientUserAccess::LEVEL_VIEWER);
        $p = "/api/phr/patients/{$this->patient->id}";

        foreach ([$manager, $viewer] as $actor) {
            $summary = $this->preload($this->actingAs($actor)->get("/phr/patient/{$this->patient->id}")->assertOk());
            // Exports belong to the actual owner: the API refuses them here, so nothing is inlined.
            $this->assertSame([$p, "{$p}/lab-results", "{$p}/vitals", "{$p}/dicom/studies", "{$p}/review/summary"], array_keys($summary));
            $this->assertPreloadIsTheApiResponse($actor, $summary);
            $this->assertSame($actor === $manager, $summary[$p]->patient->can_manage);
            $this->assertSame($actor === $manager, $summary["{$p}/review/summary"]->can_manage);

            $condition = $this->preload($this->actingAs($actor)->get("/phr/patient/{$this->patient->id}/conditions/{$this->ids['condition']}")->assertOk());
            $this->assertSame(["{$p}/conditions/{$this->ids['condition']}", "{$p}/conditions", "{$p}/review/summary"], array_keys($condition));
            $this->assertPreloadIsTheApiResponse($actor, $condition);

            $overview = $this->preload($this->actingAs($actor)->get('/phr/patients')->assertOk());
            $this->assertSame(['/api/phr/patients/overview'], array_keys($overview));
            $this->assertPreloadIsTheApiResponse($actor, $overview);
            $this->assertSame([$this->patient->id], array_column($overview['/api/phr/patients/overview']->patients, 'id'));
        }
        $this->actingAs($outsider)->getJson("{$p}/exports")->assertNotFound();
        $this->actingAs($outsider)->getJson("{$p}/review/summary")->assertNotFound();

        // Someone with no access still gets their own (empty) overview, never this patient's counts.
        $outsiderOverview = $this->preload($this->actingAs($outsider)->get('/phr/patients')->assertOk());
        $this->assertPreloadIsTheApiResponse($outsider, $outsiderOverview);
        $this->assertSame([], $outsiderOverview['/api/phr/patients/overview']->patients);

        foreach (['', '/labs', "/conditions/{$this->ids['condition']}"] as $suffix) {
            $denied = $this->actingAs($outsider)->get("/phr/patient/{$this->patient->id}{$suffix}")->assertNotFound();
            $this->assertStringNotContainsString('phr-preload', (string) $denied->getContent());
            $this->assertStringNotContainsString('Synthetic condition', (string) $denied->getContent());
        }

        // A record of another patient named under this one is not this patient's: the API 404s it.
        $other = PhrPatient::query()->create(['owner_user_id' => $this->owner->id, 'display_name' => 'Synthetic other']);
        $foreign = (int) $this->actingAs($this->owner)->postJson("/api/phr/patients/{$other->id}/conditions", ['name' => 'Synthetic foreign'])
            ->assertCreated()->json('condition.id');
        $mismatch = $this->preload($this->actingAs($viewer)->get("/phr/patient/{$this->patient->id}/conditions/{$foreign}")->assertOk());
        $this->assertSame(["{$p}/conditions", "{$p}/review/summary"], array_keys($mismatch));
    }

    public function test_preload_is_script_safe_round_trips_exactly_and_is_never_cached(): void
    {
        $this->actingAs($this->owner)->postJson("/api/phr/patients/{$this->patient->id}/conditions", [
            'name' => '<!--<script>synthetic</script>',
        ])->assertCreated();

        foreach (['', '/conditions'] as $suffix) {
            $html = $this->actingAs($this->owner)->get("/phr/patient/{$this->patient->id}{$suffix}")
                ->assertOk()
                ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
                ->assertHeader('Pragma', 'no-cache');
            $content = (string) $html->getContent();
            $this->assertStringNotContainsString('</script><script>alert(1)</script>', $content);
            $this->assertStringNotContainsString('<!--<script>', $content);
            $this->assertSame(1, preg_match('/<script type="application\/json" id="phr-preload">(.*?)<\/script>/s', $content, $match));
            $this->assertFalse(strpbrk($match[1], '<>&\'') !== false, 'Markup characters must be escaped inside the script element.');
            $this->assertPreloadIsTheApiResponse($this->owner, $this->preload($html));
        }

        $summary = $this->preload($this->actingAs($this->owner)->get("/phr/patient/{$this->patient->id}"));
        $this->assertSame('</script><script>alert(1)</script>', $summary["/api/phr/patients/{$this->patient->id}"]->patient->display_name);
    }

    public function test_a_response_over_the_size_cap_is_left_to_the_network_and_the_rest_still_fit(): void
    {
        PhrLabResult::query()->create([
            'patient_id' => $this->patient->id,
            'user_id' => $this->owner->id,
            'test_name' => 'Synthetic panel',
            'analyte' => 'Synthetic analyte',
            'value' => str_repeat('x', PhrPreloadBuilder::MAX_BYTES),
        ]);
        $p = "/api/phr/patients/{$this->patient->id}";

        $this->assertSame(["{$p}/review/summary"], array_keys($this->preload($this->actingAs($this->owner)->get("/phr/patient/{$this->patient->id}/labs")->assertOk())));
        $this->actingAs($this->owner)->getJson("{$p}/lab-results")->assertOk()->assertJsonCount(1, 'lab_results');

        $html = $this->actingAs($this->owner)->get("/phr/patient/{$this->patient->id}")->assertOk();
        $summary = $this->preload($html);
        $this->assertSame([$p, "{$p}/vitals", "{$p}/dicom/studies", "{$p}/exports", "{$p}/review/summary"], array_keys($summary));
        preg_match('/id="phr-preload">(.*?)<\/script>/s', (string) $html->getContent(), $match);
        $this->assertLessThanOrEqual(PhrPreloadBuilder::MAX_BYTES, strlen($match[1]));
    }

    public function test_only_allowed_get_routes_whose_middleware_the_page_already_ran_are_dispatched(): void
    {
        Route::middleware(['web', 'auth', 'can:viewAny,'.PhrPatient::class])
            ->get('/api/phr/patients/{patient}/lab-results-guarded', [LabResultController::class, 'index']);
        $p = "/api/phr/patients/{$this->patient->id}";
        $page = Request::create("/phr/patient/{$this->patient->id}/labs");
        $page->setUserResolver(fn (): User => $this->owner);
        $this->actingAs($this->owner);
        $this->app->instance('request', $page);

        $json = app(PhrPreloadBuilder::class)->build($page, new PhrPreloadPlan([
            "{$p}/lab-results-guarded",          // a middleware the page did not run
            "{$p}/search?q=synthetic",           // an action that is not inlined
            "{$p}/lab-results/review",           // no GET route
            'https://other.example.test/api/phr/patients',
            "{$p}/lab-results",
            "{$p}/lab-results",
        ]));

        $this->assertNotNull($json);
        $this->assertSame(["{$p}/lab-results"], array_keys(get_object_vars(json_decode($json, false, flags: JSON_THROW_ON_ERROR))));
        $this->assertSame($page, $this->app->make('request'), 'The page request is restored after each sub-dispatch.');
    }

    public function test_plan_mirrors_the_client_url_model(): void
    {
        $p = '/api/phr/patients/4';
        $summary = "{$p}/review/summary";
        $study = ["{$p}/dicom/studies/9", "{$p}/dicom/studies/9/viewer-json"];
        $this->assertSame([...$study, $summary], PhrPreloadPlan::forPatient(4, '/imaging/9')->urls);
        // A visit's linked study takes the visit's panel; the Visits list stays on screen.
        $this->assertSame([...$study, "{$p}/office-visits", "{$p}/procedures", "{$p}/immunizations", $summary], PhrPreloadPlan::forPatient(4, '/office-visits/3/imaging/9')->urls);
        $this->assertSame(["{$p}/conditions", $summary], PhrPreloadPlan::forPatient(4, '/conditions/not-a-record')->urls);
        $this->assertSame(["{$p}/conditions/0012", "{$p}/conditions", $summary], PhrPreloadPlan::forPatient(4, '/conditions/0012/')->urls);
        $this->assertSame([$summary], PhrPreloadPlan::forPatient(4, '/vitals/trend/a%2Fb')->urls);
        $this->assertSame(["{$p}/review", $summary], PhrPreloadPlan::forPatient(4, '/review')->urls);
        $this->assertSame(["{$p}/health-logs", $summary], PhrPreloadPlan::forPatient(4, '/health-log')->urls);
        $this->assertSame(PhrPreloadPlan::forPatient(4, '')->urls, PhrPreloadPlan::forPatient(4, '/unknown/3')->urls);
        $this->assertSame(['/api/phr/patients/overview'], PhrPreloadPlan::forSection('patients')->urls);
        $this->assertSame(["{$p}/health-logs/8/entries"], PhrPreloadPlan::forPatient(4, '/health-log')
            ->followUps("{$p}/health-logs", (object) ['health_logs' => [(object) ['id' => 8], (object) ['id' => 2]]]));
        $this->assertSame([], PhrPreloadPlan::forPatient(4, '/health-log')->followUps("{$p}/health-logs", (object) ['health_logs' => []]));
    }

    private function seedRecords(): void
    {
        $p = "/api/phr/patients/{$this->patient->id}";
        $this->actingAs($this->owner);
        $this->ids['lab'] = (int) $this->postJson("{$p}/lab-results", ['test_name' => 'Synthetic CBC', 'analyte' => 'Hemoglobin', 'value' => '13.2', 'unit' => 'g/dL'])
            ->assertCreated()->json('lab_result.id');
        $this->ids['vital'] = (int) $this->postJson("{$p}/vitals", ['vital_name' => 'Heart Rate', 'vital_value' => '72', 'unit' => 'bpm'])
            ->assertCreated()->json('vital.id');
        $vital = PhrPatientVital::query()->findOrFail($this->ids['vital']);
        $this->ids['metric'] = app(VitalMetricResolver::class)->metricCandidates($vital)[0]['key'];
        $this->ids['visit'] = (int) $this->postJson("{$p}/office-visits", ['visit_date' => '2026-01-15', 'chief_complaint' => 'Synthetic checkup'])
            ->assertCreated()->json('office_visit.id');
        $this->ids['procedure'] = (int) $this->postJson("{$p}/procedures", ['name' => 'Synthetic procedure'])->assertCreated()->json('procedure.id');
        $this->ids['immunization'] = (int) $this->postJson("{$p}/immunizations", ['vaccine_name' => 'Synthetic vaccine'])->assertCreated()->json('immunization.id');
        $this->ids['medication'] = (int) $this->postJson("{$p}/medications", ['name' => 'Synthetic medication'])->assertCreated()->json('medication.id');
        $this->ids['condition'] = (int) $this->postJson("{$p}/conditions", ['name' => 'Synthetic condition'])->assertCreated()->json('condition.id');
        $this->ids['allergy'] = (int) $this->postJson("{$p}/allergies", ['substance' => 'Synthetic allergen'])->assertCreated()->json('allergy.id');
        $this->ids['log'] = (int) $this->postJson("{$p}/health-logs", ['name' => 'Synthetic journal', 'kind' => 'symptom'])->assertCreated()->json('health_log.id');
        $this->postJson("{$p}/health-logs/{$this->ids['log']}/entries", ['occurred_at' => '2026-07-13 08:30:00', 'title' => 'Synthetic entry'])->assertCreated();

        $bytes = 'Synthetic document body';
        Storage::disk(PhrDocument::STORAGE_DISK)->put('synthetic/note.txt', $bytes);
        $this->ids['document'] = PhrDocument::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'uploaded_by_user_id' => $this->owner->id,
            'title' => 'Synthetic note', 'document_type' => 'other', 'original_filename' => 'note.txt',
            'storage_disk' => PhrDocument::STORAGE_DISK, 'storage_path' => 'synthetic/note.txt', 'mime_type' => 'text/plain',
            'byte_size' => strlen($bytes), 'file_hash' => hash('sha256', $bytes), 'source' => 'manual_upload',
        ])->id;
    }

    /**
     * Each entry, re-encoded with the API response's own encoding options, must be the API's bytes.
     *
     * @param  array<string, mixed>  $preload
     */
    private function assertPreloadIsTheApiResponse(User $actor, array $preload): void
    {
        $this->assertNotSame([], $preload);
        foreach ($preload as $url => $body) {
            $api = $this->actingAs($actor)->getJson($url)->assertOk();
            $options = $api->baseResponse->getEncodingOptions();
            $this->assertSame($api->getContent(), json_encode($body, $options | JSON_THROW_ON_ERROR), $url);
        }
    }

    /** @return array<string, mixed>|null */
    private function preload(TestResponse $response): ?array
    {
        $content = (string) $response->getContent();
        $count = preg_match_all('/<script type="application\/json" id="phr-preload">(.*?)<\/script>/s', $content, $matches);
        if ($count === 0) {
            return null;
        }
        $this->assertSame(1, $count);

        return get_object_vars(json_decode($matches[1][0], false, flags: JSON_THROW_ON_ERROR));
    }

    private function grant(PhrPatient $patient, User $user, string $level): void
    {
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'access_level' => $level,
            'granted_by_user_id' => $patient->owner_user_id,
            'granted_at' => now(),
        ]);
    }
}

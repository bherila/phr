<?php

namespace Tests\Feature\PHR;

use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Access\PhrPatientListService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class PhrPatientBootstrapTest extends TestCase
{
    public function test_every_phr_shell_bootstraps_the_same_full_authorized_payload_as_the_api(): void
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $viewer = $this->createUser();
        $unrelated = $this->createUser();
        $patient = $this->patient($owner, 'Synthetic Zeta', 'Full editable notes');
        $alpha = $this->patient($owner, 'Synthetic Alpha');
        $this->grant($patient, $manager, PhrPatientUserAccess::LEVEL_MANAGER);
        $this->grant($patient, $viewer, PhrPatientUserAccess::LEVEL_VIEWER);
        $this->patient($unrelated, 'Hidden unrelated profile', 'Hidden unrelated notes');

        foreach ([$owner, $manager, $viewer, $unrelated] as $actor) {
            $api = $this->actingAs($actor)->getJson('/api/phr/patients')->assertOk()
                ->assertHeader('Cache-Control', 'max-age=0, no-store, private')->json();
            foreach (['/phr/patients', '/phr/patients/manage', '/phr/imports', '/phr/config', '/phr/data-hub'] as $path) {
                $html = $this->get($path)->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
                $this->assertSame($api, $this->initialData($html)['phr']['patient_list']);
            }
            if ($actor !== $unrelated) {
                $this->assertSame($api, $this->initialData($this->get('/phr/patient/'.$patient->id)->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private'))['phr']['patient_list']);
            }
            if ($actor === $owner) {
                $this->assertSame([$alpha->id, $patient->id], array_column($api['patients'], 'id'));
                $this->assertSame('Full editable notes', $api['patients'][1]['notes']);
                $this->assertCount(2, $api['patients'][1]['access_grants']);
                $this->assertTrue($api['patients'][1]['can_share']);
            } elseif ($actor !== $unrelated) {
                $this->assertSame([$patient->id], array_column($api['patients'], 'id'));
                $this->assertSame([], $api['patients'][0]['access_grants']);
                $this->assertSame($actor === $manager, $api['patients'][0]['can_manage']);
                $this->assertFalse($api['patients'][0]['can_share']);
            }
        }
        $this->actingAs($unrelated)->get('/phr/patient/'.$patient->id)->assertNotFound();
    }

    public function test_empty_list_is_authoritative_and_non_phr_views_never_load_patient_profiles(): void
    {
        $user = $this->createUser();
        $this->assertSame(['patients' => []], $this->initialData($this->actingAs($user)->get('/phr/patients')->assertOk())['phr']['patient_list']);
        $reads = [];
        DB::listen(function ($event) use (&$reads): void {
            if (str_contains($event->sql, 'phr_patients')) {
                $reads[] = $event->sql;
            }
        });
        $html = view('layouts.app')->render();
        $this->assertStringNotContainsString('patient_list', $html);
        $this->assertSame([], $reads);
    }

    public function test_untrusted_profile_text_is_script_safe_and_round_trips_exactly(): void
    {
        $user = $this->createUser();
        $name = '</script><script>window.syntheticInjected = true</script>';
        $notes = 'Synthetic "notes" & apostrophe\' <tag> 雪';
        $this->patient($user, $name, $notes);
        $html = $this->actingAs($user)->get('/phr/patients')->assertOk();
        $this->assertStringNotContainsString($name, $html->getContent());
        $boot = $this->initialData($html)['phr']['patient_list'];
        $this->assertSame($name, $boot['patients'][0]['display_name']);
        $this->assertSame($notes, $boot['patients'][0]['notes']);
        $this->assertSame($this->getJson('/api/phr/patients')->assertOk()->json(), $boot);
    }

    public function test_patient_count_limit_omits_the_entire_seed_without_changing_the_api(): void
    {
        $user = $this->createUser();
        for ($i = 0; $i < PhrPatientListService::BOOTSTRAP_MAX_PATIENTS; $i++) {
            $this->patient($user, 'Synthetic profile '.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
        }
        $this->actingAs($user);
        $seed = $this->initialData($this->get('/phr/patients')->assertOk());
        $this->assertCount(100, $seed['phr']['patient_list']['patients']);
        $this->patient($user, 'Synthetic overflow profile');
        $profileReads = [];
        DB::listen(function ($event) use (&$profileReads): void {
            if (str_contains($event->sql, 'phr_patients')) {
                $profileReads[] = $event->sql;
            }
        });
        // Manage Patients carries no other preload, so every patient read here is the bootstrap's.
        $seed = $this->initialData($this->get('/phr/patients/manage')->assertOk());
        $this->assertArrayNotHasKey('phr', $seed);
        $this->assertCount(1, $profileReads, 'Overflow must stop after the scalar-ID query.');
        $this->assertStringContainsString('limit 101', $profileReads[0]);
        $this->getJson('/api/phr/patients')->assertOk()->assertJsonCount(101, 'patients');
    }

    public function test_exact_encoded_byte_limit_and_one_over_select_bootstrap_or_fallback(): void
    {
        $user = $this->createUser();
        $patient = $this->patient($user, 'Synthetic profile', '');
        $this->actingAs($user);
        $payload = $this->getJson('/api/phr/patients')->assertOk()->json();
        $padding = PhrPatientListService::BOOTSTRAP_MAX_BYTES - strlen(Js::encode($payload, JSON_UNESCAPED_SLASHES));
        $patient->update(['notes' => str_repeat('x', $padding)]);
        $seed = $this->initialData($this->get('/phr/patients')->assertOk())['phr']['patient_list'];
        $this->assertSame(PhrPatientListService::BOOTSTRAP_MAX_BYTES, strlen(Js::encode($seed, JSON_UNESCAPED_SLASHES)));
        $patient->update(['notes' => str_repeat('x', $padding + 1)]);
        $this->assertArrayNotHasKey('phr', $this->initialData($this->get('/phr/patients')->assertOk()));
        $this->getJson('/api/phr/patients')->assertOk()->assertJsonPath('patients.0.notes', str_repeat('x', $padding + 1));
    }

    public function test_oversized_raw_profiles_are_refused_before_hydration_and_invalid_utf8_is_omitted(): void
    {
        $user = $this->createUser();
        $patient = $this->patient($user, 'Synthetic profile', "\0".str_repeat('x', 2 * PhrPatientListService::BOOTSTRAP_MAX_BYTES));
        $hydrated = 0;
        PhrPatient::retrieved(function () use (&$hydrated): void {
            $hydrated++;
        });
        $this->actingAs($user);
        $this->assertArrayNotHasKey('phr', $this->initialData($this->get('/phr/patients')->assertOk()));
        $this->assertSame(0, $hydrated);
        $patient->update(['notes' => "Synthetic invalid \xB1"]);
        $this->assertArrayNotHasKey('phr', $this->initialData($this->get('/phr/patients')->assertOk()));
    }

    private function patient(User $owner, string $name, ?string $notes = null): PhrPatient
    {
        return PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => $name,
            'notes' => $notes,
        ]);
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

    /** @return array<string, mixed> */
    private function initialData(TestResponse $response): array
    {
        $this->assertSame(1, preg_match('/<script id="app-initial-data" type="application\/json">(.*?)<\/script>/s', $response->getContent(), $matches));

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }
}

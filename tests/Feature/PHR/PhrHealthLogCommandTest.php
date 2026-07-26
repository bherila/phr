<?php

namespace Tests\Feature\PHR;

use App\Models\PhrHealthLog;
use App\Models\PhrHealthLogEntry;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PhrHealthLogCommandTest extends TestCase
{
    public function test_command_creates_named_log_and_records_entry(): void
    {
        $owner = $this->createUser();
        $patient = $this->createPatient($owner);

        $this->artisan('phr:health-log:record', [
            '--patient' => $patient->id,
            '--actor' => $owner->id,
            '--log' => 'Meal journal',
            '--kind' => 'meal',
            '--description' => 'Synthetic meal entries',
            '--occurred-at' => '2026-07-13T12:30:00+00:00',
            '--title' => 'Midday meal',
            '--notes' => 'Synthetic meal notes',
            '--tag' => ['lunch', 'home'],
            '--details' => '{"portion":"medium"}',
        ])->assertSuccessful()
            ->expectsOutputToContain('Health log entry recorded.');

        $healthLog = PhrHealthLog::query()->sole();
        $entry = PhrHealthLogEntry::query()->sole();

        $this->assertSame($patient->id, $healthLog->patient_id);
        $this->assertSame($owner->id, $healthLog->user_id);
        $this->assertSame($owner->id, $healthLog->created_by_user_id);
        $this->assertSame('Meal journal', $healthLog->name);
        $this->assertSame('meal', $healthLog->kind);
        $this->assertSame('Synthetic meal entries', $healthLog->description);

        $this->assertSame($healthLog->id, $entry->health_log_id);
        $this->assertSame($owner->id, $entry->recorded_by_user_id);
        $this->assertSame('Midday meal', $entry->title);
        $this->assertSame(['lunch', 'home'], $entry->tags);
        $this->assertSame(['portion' => 'medium'], $entry->details);
    }

    public function test_command_appends_to_log_by_id_and_outputs_json(): void
    {
        $owner = $this->createUser();
        $patient = $this->createPatient($owner);

        $this->artisan('phr:health-log:record', [
            '--patient' => $patient->id,
            '--actor' => $owner->id,
            '--log' => 'Symptom journal',
            '--kind' => 'symptom',
            '--title' => 'Initial entry',
        ])->assertSuccessful();

        $healthLog = PhrHealthLog::query()->sole();

        $exitCode = Artisan::call('phr:health-log:record', [
            '--patient' => $patient->id,
            '--actor' => $owner->id,
            '--log' => (string) $healthLog->id,
            '--occurred-at' => '2026-07-13 15:45:00',
            '--title' => 'Follow-up entry',
            '--intensity' => '6',
            '--tag' => ['afternoon'],
            '--details' => '{"side":"both"}',
            '--format' => 'json',
        ]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame($healthLog->id, $payload['health_log']['id']);
        $this->assertSame('symptom', $payload['health_log']['kind']);
        $this->assertSame('Follow-up entry', $payload['entry']['title']);
        $this->assertSame(6, $payload['entry']['intensity']);
        $this->assertSame(['afternoon'], $payload['entry']['tags']);
        $this->assertSame(['side' => 'both'], $payload['entry']['details']);
        $this->assertSame(1, PhrHealthLog::query()->count());
        $this->assertSame(2, PhrHealthLogEntry::query()->count());
    }

    public function test_viewer_cannot_record_health_log_entry(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $patient = $this->createPatient($owner);

        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $viewer->id,
            'access_level' => PhrPatientUserAccess::LEVEL_VIEWER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);

        $this->artisan('phr:health-log:record', [
            '--patient' => $patient->id,
            '--actor' => $viewer->id,
            '--log' => 'Viewer journal',
            '--title' => 'Blocked entry',
        ])->assertFailed()
            ->expectsOutputToContain('You do not have write access to this patient.');

        $this->assertSame(0, PhrHealthLog::query()->count());
        $this->assertSame(0, PhrHealthLogEntry::query()->count());
    }

    public function test_numeric_log_id_must_belong_to_patient(): void
    {
        $owner = $this->createUser();
        $firstPatient = $this->createPatient($owner, 'First Test Patient');
        $secondPatient = $this->createPatient($owner, 'Second Test Patient');

        $this->artisan('phr:health-log:record', [
            '--patient' => $secondPatient->id,
            '--actor' => $owner->id,
            '--log' => 'Scoped journal',
            '--title' => 'Existing entry',
        ])->assertSuccessful();

        $healthLog = PhrHealthLog::query()->sole();

        $this->artisan('phr:health-log:record', [
            '--patient' => $firstPatient->id,
            '--actor' => $owner->id,
            '--log' => (string) $healthLog->id,
            '--title' => 'Cross-patient entry',
        ])->assertFailed()
            ->expectsOutputToContain("Health log {$healthLog->id} was not found for phr_patients#{$firstPatient->id}.");

        $this->assertSame(1, PhrHealthLogEntry::query()->count());
    }

    public function test_command_rejects_invalid_options_without_writing(): void
    {
        $owner = $this->createUser();
        $patient = $this->createPatient($owner);

        $invalidOptionSets = [
            ['--log' => 'Invalid kind', '--kind' => 'other'],
            ['--log' => 'Invalid intensity', '--intensity' => '11'],
            ['--log' => 'Invalid time', '--occurred-at' => 'not-a-date'],
            ['--log' => 'Invalid details', '--details' => '["not", "an", "object"]'],
            ['--log' => 'Invalid format', '--format' => 'yaml'],
        ];

        foreach ($invalidOptionSets as $options) {
            $this->artisan('phr:health-log:record', [
                '--patient' => $patient->id,
                '--actor' => $owner->id,
                ...$options,
            ])->assertFailed();
        }

        $this->assertSame(0, PhrHealthLog::query()->count());
        $this->assertSame(0, PhrHealthLogEntry::query()->count());
    }

    private function createPatient(User $owner, string $displayName = 'Test Patient'): PhrPatient
    {
        return PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => $displayName,
            'relationship' => 'self',
        ]);
    }
}

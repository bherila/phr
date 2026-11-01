<?php

namespace Tests\Feature\PHR;

use App\Models\PhrMedicationFill;
use App\Models\User;
use Tests\TestCase;

class UserDisplayPreferencesTest extends TestCase
{
    private const string URL = '/api/user/display-preferences';

    public function test_everything_shows_until_a_person_chooses_otherwise(): void
    {
        $this->assertSame(['date_format' => 'YYYY-MM-DD', 'hide_medication_list' => false, 'separate_columns' => false, 'hidden_fill_columns' => []], User::factory()->create()->displayPreferences());
    }

    public function test_a_change_saves_on_the_logged_in_user_and_leaves_other_settings_and_other_people_alone(): void
    {
        $person = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($person)->putJson(self::URL, ['hide_medication_list' => true])->assertOk()
            ->assertJsonPath('display_preferences.hide_medication_list', true);
        $this->actingAs($person)->putJson(self::URL, ['hidden_fill_columns' => ['pharmacy', 'prescriber']])->assertOk()
            ->assertJsonPath('display_preferences.hide_medication_list', true)
            ->assertJsonPath('display_preferences.hidden_fill_columns', ['prescriber', 'pharmacy']);

        $this->assertTrue($person->refresh()->displayPreferences()['hide_medication_list']);
        $this->assertFalse($other->refresh()->displayPreferences()['hide_medication_list']);

        $this->actingAs($person)->putJson(self::URL, ['separate_columns' => true])->assertOk()
            ->assertJsonPath('display_preferences.separate_columns', true)
            ->assertJsonPath('display_preferences.hide_medication_list', true);

        $this->actingAs($person)->putJson(self::URL, ['hidden_fill_columns' => []])->assertOk()
            ->assertJsonPath('display_preferences.hidden_fill_columns', []);
    }

    public function test_only_known_settings_and_values_are_accepted(): void
    {
        $person = User::factory()->create();

        $this->actingAs($person)->putJson(self::URL, ['hidden_fill_columns' => ['rx_number']])->assertUnprocessable();
        $this->actingAs($person)->putJson(self::URL, ['date_format' => 'YY.MM.DD'])->assertUnprocessable();
        $this->actingAs($person)->putJson(self::URL, ['hide_medication_list' => 'yes'])->assertUnprocessable();
        $this->actingAs($person)->putJson(self::URL, ['user_role' => 'admin', 'hide_medication_list' => true])->assertOk();

        $this->assertSame(['date_format', 'hide_medication_list', 'separate_columns', 'hidden_fill_columns'], array_keys($person->refresh()->display_preferences));
        $this->assertNotSame('admin', $person->user_role);
    }

    public function test_a_date_format_changes_only_text_written_for_that_person_never_stored_or_exchanged_dates(): void
    {
        $person = User::factory()->create(['display_preferences' => ['date_format' => 'MM/DD/YYYY']]);
        $other = User::factory()->create();
        $patientId = (int) $this->actingAs($person)->postJson('/api/phr/patients', ['display_name' => 'Synthetic patient', 'relationship' => 'self'])->assertCreated()->json('patient.id');
        $this->postJson("/api/phr/patients/{$patientId}/access", ['email' => $other->email, 'access_level' => 'viewer'])->assertCreated();
        $this->postJson("/api/phr/patients/{$patientId}/medication-fills", ['drug_as_printed' => 'Synthetic drug', 'filled_on' => '2026-03-04', 'quantity' => '30'])->assertCreated();

        PhrMedicationFill::query()->where('patient_id', $patientId)->update(['review_status' => 'pending_review']);

        // Exchanged data stays ISO.
        $this->getJson("/api/phr/patients/{$patientId}/medication-fills")->assertOk()->assertJsonPath('medication_fills.0.filled_on', '2026-03-04');

        // Text the server writes for a person to read follows that person.
        $detail = fn (User $user): string => (string) collect($this->actingAs($user)->getJson("/api/phr/patients/{$patientId}/review")->assertOk()->json('groups'))
            ->flatMap(static fn (array $group): array => $group['items'])->firstWhere('section', 'medication-fills')['detail'];
        $this->assertStringContainsString('03/04/2026', $detail($person));
        $this->assertStringContainsString('2026-03-04', $detail($other));
    }

    public function test_it_requires_a_session(): void
    {
        $this->putJson(self::URL, ['hide_medication_list' => true])->assertUnauthorized();
    }

    public function test_a_page_carries_the_setting_for_its_first_paint(): void
    {
        $person = User::factory()->create(['display_preferences' => ['hide_medication_list' => true]]);

        $this->actingAs($person)->get('/phr/patients')->assertOk()->assertSee('display_preferences', false);
    }
}

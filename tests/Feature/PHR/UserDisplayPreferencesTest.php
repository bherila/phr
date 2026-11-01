<?php

namespace Tests\Feature\PHR;

use App\Models\User;
use Tests\TestCase;

class UserDisplayPreferencesTest extends TestCase
{
    private const string URL = '/api/user/display-preferences';

    public function test_everything_shows_until_a_person_chooses_otherwise(): void
    {
        $this->assertSame(['hide_medication_list' => false, 'hidden_fill_columns' => []], User::factory()->create()->displayPreferences());
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

        $this->actingAs($person)->putJson(self::URL, ['hidden_fill_columns' => []])->assertOk()
            ->assertJsonPath('display_preferences.hidden_fill_columns', []);
    }

    public function test_only_known_settings_and_values_are_accepted(): void
    {
        $person = User::factory()->create();

        $this->actingAs($person)->putJson(self::URL, ['hidden_fill_columns' => ['rx_number']])->assertUnprocessable();
        $this->actingAs($person)->putJson(self::URL, ['hide_medication_list' => 'yes'])->assertUnprocessable();
        $this->actingAs($person)->putJson(self::URL, ['user_role' => 'admin', 'hide_medication_list' => true])->assertOk();

        $this->assertSame(['hide_medication_list', 'hidden_fill_columns'], array_keys($person->refresh()->display_preferences));
        $this->assertNotSame('admin', $person->user_role);
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

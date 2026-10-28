<?php

namespace Tests\Feature\PHR\GenAi;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\UserAiConfiguration;
use Tests\TestCase;

/**
 * Where AI extraction runs by default (#235): the AI Agent Inbox, unless the user
 * has saved a usable provider key. A choice the user makes is kept, even one that
 * equals the default, so saving a key later does not move them.
 */
class GenAiExecutionModeDefaultTest extends TestCase
{
    public function test_a_user_with_no_usable_key_defaults_to_the_inbox(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->getJson('/api/user/ai-execution-mode')->assertOk()
            ->assertJsonPath('mode', GenAiImportJob::EXECUTION_EXTERNAL)
            ->assertJsonPath('mode_chosen', false);

        // A key that has expired or been marked invalid is not usable.
        UserAiConfiguration::factory()->for($user)->gemini()->create(['is_active' => true, 'expires_at' => now()->subDay()]);
        self::assertSame(GenAiImportJob::EXECUTION_EXTERNAL, $user->refresh()->genAiExecutionMode());
    }

    public function test_a_user_with_a_usable_key_defaults_to_server_side_ai(): void
    {
        $user = $this->createUser();
        UserAiConfiguration::factory()->for($user)->gemini()->create(['is_active' => true, 'expires_at' => null, 'api_key_invalid_at' => null]);

        $this->actingAs($user)->getJson('/api/user/ai-execution-mode')->assertOk()
            ->assertJsonPath('mode', GenAiImportJob::EXECUTION_API)
            ->assertJsonPath('mode_chosen', false);
    }

    public function test_choosing_the_inbox_is_kept_when_a_key_is_saved_later(): void
    {
        $user = $this->createUser();

        // The same as the default, but chosen: recorded, so it stays.
        $this->actingAs($user)->putJson('/api/user/ai-execution-mode', ['mode' => GenAiImportJob::EXECUTION_EXTERNAL])->assertOk()
            ->assertJsonPath('mode', GenAiImportJob::EXECUTION_EXTERNAL)
            ->assertJsonPath('mode_chosen', true);
        UserAiConfiguration::factory()->for($user)->gemini()->create(['is_active' => true, 'expires_at' => null, 'api_key_invalid_at' => null]);

        $this->actingAs($user)->getJson('/api/user/ai-execution-mode')->assertOk()
            ->assertJsonPath('mode', GenAiImportJob::EXECUTION_EXTERNAL)
            ->assertJsonPath('mode_chosen', true);
    }
}

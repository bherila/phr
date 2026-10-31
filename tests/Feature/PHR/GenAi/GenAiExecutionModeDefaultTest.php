<?php

namespace Tests\Feature\PHR\GenAi;

use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\User;
use App\Models\UserAiConfiguration;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Where AI extraction runs by default (#235): the AI Agent Inbox, unless the user
 * has saved a usable key. The mode is stored, never derived on read, and the
 * default moves at one moment only, when a user who never chose saves a usable
 * key, taking their queued work with it. A choice is kept.
 */
class GenAiExecutionModeDefaultTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_a_new_user_starts_on_the_inbox(): void
    {
        $this->actingAs($this->createUser())->getJson('/api/user/ai-execution-mode')->assertOk()
            ->assertJsonPath('mode', GenAiImportJob::EXECUTION_EXTERNAL)
            ->assertJsonPath('mode_chosen', false);
    }

    public function test_activating_a_key_moves_a_user_who_never_chose_and_their_queued_work(): void
    {
        $user = $this->createUser();
        $queued = $this->job($user, GenAiImportJob::EXECUTION_EXTERNAL);
        $key = UserAiConfiguration::factory()->for($user)->gemini()->create(['is_active' => false, 'expires_at' => null, 'api_key_invalid_at' => null]);

        $this->actingAs($user)->postJson("/api/user/ai-prefs/{$key->id}/activate")->assertOk();

        self::assertSame(GenAiImportJob::EXECUTION_API, $user->refresh()->genAiExecutionMode());
        self::assertNull($user->chosenGenAiExecutionMode(), 'still the default, not a choice');
        self::assertSame(GenAiImportJob::EXECUTION_API, $queued->refresh()->execution_mode, 'the queued job moved with them');
        Queue::assertPushed(ParseImportJob::class, 1);
    }

    public function test_a_key_expiring_or_turning_invalid_moves_no_one(): void
    {
        $user = $this->createUser();
        $key = UserAiConfiguration::factory()->for($user)->gemini()->create(['is_active' => false, 'expires_at' => null, 'api_key_invalid_at' => null]);
        $this->actingAs($user)->postJson("/api/user/ai-prefs/{$key->id}/activate")->assertOk();
        $queued = $this->job($user, GenAiImportJob::EXECUTION_API);

        $key->update(['expires_at' => now()->subMinute(), 'api_key_invalid_at' => now()]);

        // Still server-side, so the pipeline reports the broken key instead of
        // quietly handing work to an inbox no agent may be watching.
        self::assertSame(GenAiImportJob::EXECUTION_API, $user->refresh()->genAiExecutionMode());
        self::assertSame(GenAiImportJob::EXECUTION_API, $queued->refresh()->execution_mode);
    }

    public function test_choosing_the_inbox_is_kept_when_a_key_is_activated_later(): void
    {
        $user = $this->createUser();
        $this->actingAs($user)->putJson('/api/user/ai-execution-mode', ['mode' => GenAiImportJob::EXECUTION_EXTERNAL])->assertOk()
            ->assertJsonPath('mode_chosen', true);
        $key = UserAiConfiguration::factory()->for($user)->gemini()->create(['is_active' => false, 'expires_at' => null, 'api_key_invalid_at' => null]);

        $this->actingAs($user)->postJson("/api/user/ai-prefs/{$key->id}/activate")->assertOk();

        $this->actingAs($user)->getJson('/api/user/ai-execution-mode')->assertOk()
            ->assertJsonPath('mode', GenAiImportJob::EXECUTION_EXTERNAL)
            ->assertJsonPath('mode_chosen', true);
    }

    public function test_choosing_the_mode_in_effect_still_moves_jobs_left_in_the_other_one(): void
    {
        $user = $this->createUser();
        $straggler = $this->job($user, GenAiImportJob::EXECUTION_API);

        $this->actingAs($user)->putJson('/api/user/ai-execution-mode', ['mode' => GenAiImportJob::EXECUTION_EXTERNAL])->assertOk();

        self::assertSame(GenAiImportJob::EXECUTION_EXTERNAL, $straggler->refresh()->execution_mode, 'its completion would otherwise be refused');
        Queue::assertPushed(ParseImportJob::class, 1);
    }

    private function job(User $user, string $mode): GenAiImportJob
    {
        return GenAiImportJob::query()->create([
            'user_id' => $user->id,
            'job_type' => 'phr_lab_result',
            'file_hash' => hash('sha256', 'synthetic '.$mode),
            'original_filename' => 'synthetic.pdf',
            's3_path' => 'genai-import/'.$user->id.'/synthetic.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 10,
            'execution_mode' => $mode,
            'status' => 'pending',
            'context_json' => json_encode(['patient_id' => 1, 'document_id' => 1], JSON_THROW_ON_ERROR),
        ]);
    }
}

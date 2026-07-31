<?php

namespace Tests\Feature\PHR\GenAi;

use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class GenAiQueueRecoveryTest extends TestCase
{
    public function test_it_dispatches_due_deferred_jobs_but_leaves_future_jobs_scheduled(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-07-30 12:00:00 UTC'));
        $user = $this->createUser();

        $due = $this->createJob($user, 'queued_tomorrow', [
            'scheduled_for' => now()->subDay()->toDateString(),
            'error_message' => 'Daily quota exhausted.',
        ]);
        $future = $this->createJob($user, 'queued_tomorrow', [
            'scheduled_for' => now()->addDay()->toDateString(),
        ]);

        $this->artisan('genai:requeue-stale')->assertSuccessful();

        $this->assertSame('pending', $due->refresh()->status);
        $this->assertNull($due->scheduled_for);
        $this->assertNull($due->error_message);
        $this->assertSame('queued_tomorrow', $future->refresh()->status);
        Queue::assertPushed(
            ParseImportJob::class,
            fn (ParseImportJob $job): bool => $job->jobId === $due->id,
        );
        Queue::assertNotPushed(
            ParseImportJob::class,
            fn (ParseImportJob $job): bool => $job->jobId === $future->id,
        );
    }

    public function test_it_retries_stale_processing_jobs_and_fails_exhausted_jobs(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-07-30 12:00:00 UTC'));
        $user = $this->createUser();

        $stale = $this->createJob($user, 'processing', ['retry_count' => 1]);
        $this->setUpdatedAt($stale, now()->subMinutes(11));

        $recent = $this->createJob($user, 'processing');
        $this->setUpdatedAt($recent, now()->subMinutes(9));

        $exhausted = $this->createJob($user, 'processing', [
            'retry_count' => GenAiImportJob::MAX_RETRIES,
        ]);
        $this->setUpdatedAt($exhausted, now()->subMinutes(11));

        $this->artisan('genai:requeue-stale')->assertSuccessful();

        $this->assertSame('pending', $stale->refresh()->status);
        $this->assertSame(2, $stale->retry_count);
        $this->assertSame('processing', $recent->refresh()->status);
        $this->assertSame('failed', $exhausted->refresh()->status);
        $this->assertStringContainsString('exhausting stale-recovery retries', (string) $exhausted->error_message);

        Queue::assertPushed(
            ParseImportJob::class,
            fn (ParseImportJob $job): bool => $job->jobId === $stale->id,
        );
        Queue::assertNotPushed(
            ParseImportJob::class,
            fn (ParseImportJob $job): bool => in_array($job->jobId, [$recent->id, $exhausted->id], true),
        );
    }

    public function test_it_redispatches_a_stranded_pending_job_only_once_per_recovery_window(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-07-30 12:00:00 UTC'));
        $user = $this->createUser();
        $pending = $this->createJob($user, 'pending');
        $this->setUpdatedAt($pending, now()->subMinutes(6));

        $this->artisan('genai:requeue-stale')->assertSuccessful();
        $this->artisan('genai:requeue-stale')->assertSuccessful();

        $this->assertSame(now()->timestamp, $pending->refresh()->updated_at?->timestamp);
        Queue::assertPushed(ParseImportJob::class, 1);
        Queue::assertPushed(
            ParseImportJob::class,
            fn (ParseImportJob $job): bool => $job->jobId === $pending->id,
        );
    }

    public function test_a_queue_outage_leaves_recovered_work_pending_for_the_next_pass(): void
    {
        $this->travelTo(Carbon::parse('2026-07-30 12:00:00 UTC'));
        $user = $this->createUser();
        $due = $this->createJob($user, 'queued_tomorrow', [
            'scheduled_for' => now()->subDay()->toDateString(),
        ]);
        Bus::shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('queue unavailable'));

        $this->artisan('genai:requeue-stale')
            ->expectsOutputToContain("Failed to redispatch GenAI job {$due->id}")
            ->assertSuccessful();

        $this->assertSame('pending', $due->refresh()->status);
        $this->assertSame(now()->timestamp, $due->updated_at?->timestamp);
    }

    public function test_parse_job_claim_makes_duplicate_dispatches_no_ops(): void
    {
        $user = $this->createUser();
        $job = $this->createJob($user, 'pending', ['job_type' => 'unsupported']);

        (new ParseImportJob($job->id))->handle();
        (new ParseImportJob($job->id))->handle();

        $this->assertSame('failed', $job->refresh()->status);
        $this->assertSame(1, $job->retry_count);
        $this->assertSame('Unsupported job type: unsupported', $job->error_message);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createJob(User $user, string $status, array $attributes = []): GenAiImportJob
    {
        return GenAiImportJob::create([
            'user_id' => $user->id,
            'job_type' => 'phr_document',
            'file_hash' => hash('sha256', $status.microtime(true).random_int(1, PHP_INT_MAX)),
            'original_filename' => 'source.pdf',
            's3_path' => 'genai-import/source.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'context_json' => '{}',
            'status' => $status,
            ...$attributes,
        ]);
    }

    private function setUpdatedAt(GenAiImportJob $job, Carbon $updatedAt): void
    {
        $job->forceFill(['updated_at' => $updatedAt])->saveQuietly();
    }
}

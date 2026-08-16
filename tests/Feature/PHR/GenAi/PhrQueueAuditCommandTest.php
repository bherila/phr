<?php

namespace Tests\Feature\PHR\GenAi;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhrQueueAuditCommandTest extends TestCase
{
    public function test_it_reports_backlog_metadata_without_exposing_payloads_or_exceptions(): void
    {
        $this->travelTo(Carbon::parse('2026-07-31 12:00:00 UTC'));
        config(['queue.default' => 'database']);
        $now = now()->timestamp;

        DB::table('jobs')->insert([
            [
                'queue' => 'genai-imports',
                'payload' => 'SECRET-GENAI-PAYLOAD',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now,
                'created_at' => $now - 300,
            ],
            [
                'queue' => 'genai-imports',
                'payload' => 'ANOTHER-SECRET-PAYLOAD',
                'attempts' => 2,
                'reserved_at' => $now - 20,
                'available_at' => $now - 30,
                'created_at' => $now - 120,
            ],
            [
                'queue' => 'phr-exports',
                'payload' => 'SECRET-EXPORT-PAYLOAD',
                'attempts' => 1,
                'reserved_at' => null,
                'available_at' => $now,
                'created_at' => $now - 60,
            ],
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'genai-imports',
            'payload' => 'SECRET-FAILED-PAYLOAD',
            'exception' => 'SECRET-EXCEPTION',
            'failed_at' => now()->subMinutes(10),
        ]);

        $this->artisan('phr:queue:audit')
            ->expectsOutputToContain('queue-audit driver=database retry_after=3660 pending_total=3 failed_total=1')
            ->expectsOutputToContain('pending queue=genai-imports count=2 reserved=1 max_attempts=2 oldest_seconds=300')
            ->expectsOutputToContain('pending queue=phr-exports count=1 reserved=0 max_attempts=1 oldest_seconds=60')
            ->expectsOutputToContain('failed queue=genai-imports count=1 oldest_seconds=600')
            ->doesntExpectOutputToContain('SECRET')
            ->assertSuccessful();
    }
}

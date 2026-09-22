<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// PHR jobs run through an allow-listed uptime wrapper that persists only fixed
// job names and numeric result metadata (never output, exception text, or PHI).
Schedule::command('phr:uptime:run-task', ['phr:dicom:gc'])->hourly()->withoutOverlapping(30);
Schedule::command('phr:uptime:run-task', ['phr:exports:purge'])->daily()->withoutOverlapping(30);
Schedule::command('phr:uptime:run-task', ['phr:native-backups:purge'])->daily()->withoutOverlapping(30);
Schedule::command('phr:uptime:run-task', ['phr:native-restores:purge'])->daily()->withoutOverlapping(30);
Schedule::command('phr:uptime:run-task', ['phr:data-hub:prune-audits'])->daily()->withoutOverlapping(30);
Schedule::command('phr:uptime:run-task', ['phr:agent-api:prune-audits'])->daily()->withoutOverlapping(30);
Schedule::command('phr:uptime:run-task', ['phr:agent-api:prune-oauth-credentials'])->daily()->withoutOverlapping(30);
Schedule::command('phr:uptime:run-task', ['genai:requeue-stale'])->everyFiveMinutes()->withoutOverlapping(10);
// An external request that no import job references can never be claimed, so
// it is swept even when the inline cancellation that should have removed it
// failed transiently or its process died before linking it.
Schedule::command('genai:cancel-orphaned-requests')->everyFifteenMinutes()->withoutOverlapping(10);
Schedule::command('genai:mcp:deliver')->everyMinute()->withoutOverlapping(10);
Schedule::command('genai:mcp:prune')->daily()->withoutOverlapping(10);
Schedule::command('phr:uptime:prune', ['--days' => 30])->daily()->withoutOverlapping(10);

// Auth audit log retention pruning (bherila/auth-laravel).
// No-op unless BHERILA_AUTH_AUDIT_RETENTION_DAYS is set in .env.
Schedule::command('bherila-auth:prune-audit-log')->daily()->withoutOverlapping(10);

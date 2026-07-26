<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// PHR DICOM storage cleanup: reclaim stuck pending uploads + orphan objects.
Schedule::command('phr:dicom:gc')->hourly()->withoutOverlapping(30);
Schedule::command('phr:exports:purge')->daily()->withoutOverlapping(30);

// Auth audit log retention pruning (bherila/auth-laravel).
// No-op unless BHERILA_AUTH_AUDIT_RETENTION_DAYS is set in .env.
Schedule::command('bherila-auth:prune-audit-log')->daily()->withoutOverlapping(10);

// NOTE: the monorepo also schedules genai:run-queue / genai:process-scheduled /
// genai:requeue-stale as recovery pollers for the shared GenAiProcessor pipeline.
// PHR's minimal queue (bherila/2025-website#1805, option (c)) dispatches
// ParseImportJob directly at enqueue time (PhrGenAiEnqueueCommand, PhrDocumentController
// ::process()), so there is no separate poller yet. A `queued_tomorrow` job or one stuck
// in `processing` will not currently self-heal — add a recovery command if that becomes
// a real problem.

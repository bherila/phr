<?php

namespace App\Console\Commands\GenAi;

use App\GenAiProcessor\Services\PhrExternalGenAiRequestService;
use Illuminate\Console\Command;

/**
 * Cancel external GenAI requests that no import job references any more.
 *
 * An orphan is created whenever the inline cancellation in
 * PhrExternalGenAiRequestService cannot complete - a transient database error
 * while cancelling, or a crash between creating a request and linking it. The
 * request row itself is the durable record of the cleanup still owed; this
 * command is what consumes it, so an orphan can never stay pending and
 * unclaimable forever.
 */
class CancelOrphanedGenAiRequests extends Command
{
    protected $signature = 'genai:cancel-orphaned-requests
                            {--min-age-minutes=15 : Only sweep requests created this many minutes ago, so an in-flight enqueue is never cancelled between creating its request and linking it}
                            {--batch=100 : Maximum orphaned requests to inspect in one pass}';

    protected $description = 'Cancel external GenAI requests that no import job references';

    public function handle(PhrExternalGenAiRequestService $requests): int
    {
        $cancelled = $requests->cancelOrphanedRequests(
            max(0, (int) $this->option('min-age-minutes')),
            min(1000, max(1, (int) $this->option('batch'))),
        );

        $this->info(sprintf('External GenAI orphan sweep complete: %d request(s) cancelled.', $cancelled));

        return self::SUCCESS;
    }
}

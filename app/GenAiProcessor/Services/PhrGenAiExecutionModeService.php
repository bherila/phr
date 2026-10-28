<?php

namespace App\GenAiProcessor\Services;

use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\User;
use App\Support\Logging\SafeLog;
use Bherila\GenAiLaravel\Mcp\McpQueueService;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class PhrGenAiExecutionModeService
{
    public function __construct(private McpQueueService $queue) {}

    /** The user's explicit choice: recorded, so the default never moves them again. */
    public function update(User $user, string $mode): User
    {
        return $this->apply($user, $mode, true);
    }

    /**
     * The default follows a saved key (#235): a user who never chose moves to
     * server-side AI once they have a usable key, and their queued work with them.
     * Call it after a key is stored or activated. Losing a key moves no one.
     */
    public function adoptDefault(User $user): User
    {
        if ($user->chosenGenAiExecutionMode() !== null || ! $user->hasUsableAiProvider()) {
            return $user;
        }

        return $this->apply($user, GenAiImportJob::EXECUTION_API, false);
    }

    private function apply(User $user, string $mode, bool $chosen): User
    {
        if (! in_array($mode, GenAiImportJob::VALID_EXECUTION_MODES, true)) {
            throw new \InvalidArgumentException('Unsupported GenAI execution mode.');
        }

        [$jobIds, $requestIds] = DB::transaction(function () use ($user, $mode, $chosen): array {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $chosen && $lockedUser->chosenGenAiExecutionMode() !== null) {
                // Chose meanwhile: a choice outranks the default.
                return [[], []];
            }
            $lockedUser->forceFill([
                'genai_execution_mode' => $mode,
                ...($chosen ? ['genai_execution_mode_chosen_at' => now()] : []),
            ])->save();
            // Every queued job not already in this mode moves, whether or not the
            // user-level mode changed: a job left in the other mode would have its
            // completion refused.
            $jobs = GenAiImportJob::query()
                ->where('user_id', $lockedUser->id)
                ->whereIn('status', ['pending', 'processing', 'queued_tomorrow'])
                ->where('execution_mode', '!=', $mode)
                ->lockForUpdate()
                ->get();
            $requestIds = $jobs->pluck('mcp_request_id')->filter()->map(static fn (mixed $id): string => (string) $id)->all();
            foreach ($jobs as $job) {
                $job->forceFill([
                    'execution_mode' => $mode,
                    'mcp_request_id' => null,
                    'mcp_generation' => $job->mcp_generation + 1,
                    'status' => 'pending',
                    'scheduled_for' => null,
                    'error_message' => null,
                ])->save();
            }

            return [$jobs->modelKeys(), $requestIds];
        });

        foreach ($requestIds as $requestId) {
            try {
                $request = McpRequest::query()->find($requestId);
                if ($request instanceof McpRequest) {
                    $this->queue->cancel($request);
                }
            } catch (Throwable $exception) {
                // SafeLog: the mode change has already committed. A failing log
                // destination here must neither skip the remaining
                // cancellations nor the successor dispatches below, nor
                // report the committed change to the caller as a failure.
                SafeLog::info('External GenAI request was already terminal while changing execution mode.', [
                    'request_id_hash' => hash('sha256', $requestId),
                    'exception' => $exception::class,
                ]);
            }
        }
        foreach ($jobIds as $jobId) {
            try {
                ParseImportJob::dispatch((int) $jobId);
            } catch (Throwable $exception) {
                SafeLog::warning('GenAI mode change left import pending for recovery.', [
                    'job_id' => $jobId,
                    'exception' => $exception::class,
                ]);
            }
        }

        return $user->refresh();
    }
}

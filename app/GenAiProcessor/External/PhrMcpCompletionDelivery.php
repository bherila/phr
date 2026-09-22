<?php

namespace App\GenAiProcessor\External;

use App\GenAiProcessor\Mail\GenAiJobCompleteMail;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Services\PhrGenAiRequestPreparationService;
use App\GenAiProcessor\Services\PhrImportProposalApplicationService;
use App\GenAiProcessor\Support\PhrExternalImportStatusMap;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\Logging\SafeLog;
use Bherila\GenAiLaravel\Contracts\CompletionDelivery;
use Bherila\GenAiLaravel\Mcp\Models\McpDelivery;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Throwable;
use UnexpectedValueException;

final readonly class PhrMcpCompletionDelivery implements CompletionDelivery
{
    public function __construct(private PhrImportProposalApplicationService $application) {}

    public function deliver(McpDelivery $delivery): bool
    {
        $requestId = $delivery->getAttribute('request_id');
        if (! is_string($requestId) || $requestId === '') {
            return true;
        }
        $request = McpRequest::query()->find($requestId);
        if (! $request instanceof McpRequest) {
            return true;
        }
        $jobId = $request->metadata['phr_import_job_id'] ?? null;
        if (! is_int($jobId) && ! (is_string($jobId) && ctype_digit($jobId))) {
            return true;
        }
        $job = GenAiImportJob::query()->find((int) $jobId);
        if (! $job instanceof GenAiImportJob || $job->mcp_request_id !== $request->id) {
            return true;
        }

        $deliveryType = $delivery->getAttribute('type');
        if ($deliveryType === 'failed') {
            GenAiImportJob::query()
                ->whereKey($job->id)
                ->where('mcp_request_id', $request->id)
                ->whereIn('status', PhrExternalImportStatusMap::NONTERMINAL_PHR_STATUSES)
                ->update([
                    'status' => 'failed',
                    'error_message' => 'The external client could not process this import.',
                    'retry_count' => min(GenAiImportJob::MAX_RETRIES, $job->retry_count + 1),
                    'updated_at' => now(),
                ]);

            return true;
        }
        if ($deliveryType !== 'completed') {
            return true;
        }

        try {
            $data = $this->completionData($delivery);
            $created = $this->application->apply($job->id, $data, function (GenAiImportJob $lockedJob, User $lockedUser) use ($request): void {
                $this->authorizeApplication($lockedJob, $lockedUser, $request);
            });
        } catch (PhrExternalCompletionRejected|UnexpectedValueException|ModelNotFoundException $exception) {
            GenAiImportJob::query()
                ->whereKey($job->id)
                ->where('mcp_request_id', $request->id)
                ->whereIn('status', PhrExternalImportStatusMap::NONTERMINAL_PHR_STATUSES)
                ->update([
                    'status' => 'failed',
                    'error_message' => 'External processing was cancelled because access or source state changed.',
                    'updated_at' => now(),
                ]);
            // SafeLog: the rejection is already recorded. A throwing log
            // destination would leave this delivery unacknowledged and retried.
            SafeLog::info('External GenAI completion rejected after reauthorization.', [
                'job_id' => $job->id,
                'exception' => $exception::class,
            ]);

            return true;
        }
        $job->refresh();
        $executor = $request->result['executor'] ?? [];
        $job->forceFill([
            'ai_provider' => 'mcp',
            'ai_model' => is_array($executor) && is_string($executor['model'] ?? null)
                ? mb_substr($executor['model'], 0, 255)
                : null,
            'raw_response' => null,
            'input_tokens' => null,
            'output_tokens' => null,
        ])->save();

        // SafeLog: the completion is applied and committed. A throwing log
        // destination - or a failing diagnostic-only count query - must not
        // leave the delivery unacknowledged, which would replay it with
        // nothing left to create and so never send the completion mail.
        SafeLog::info('External GenAI import completion applied.', [
            'job_id' => $job->id,
            'result_count' => $this->resultCountForDiagnostic($job),
            'created_count' => $created,
        ]);
        if ($created > 0 && $job->user instanceof User) {
            try {
                Mail::to($job->user->email)->send(new GenAiJobCompleteMail($job));
            } catch (Throwable $exception) {
                SafeLog::warning('Failed to send external GenAI completion mail.', [
                    'job_id' => $job->id,
                    'exception' => $exception::class,
                ]);
            }
        }

        return true;
    }

    /**
     * The proposal count for the completion diagnostic, or null when it cannot
     * be read. Context is built before SafeLog is ever called, so this read
     * would otherwise be the one part of a best-effort diagnostic that can
     * still throw.
     */
    private function resultCountForDiagnostic(GenAiImportJob $job): ?int
    {
        try {
            return $job->results()->count();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<array-key, mixed> */
    private function completionData(McpDelivery $delivery): array
    {
        $result = $delivery->payload['result'] ?? null;
        $toolCalls = is_array($result) ? ($result['tool_calls'] ?? null) : null;
        if (! is_array($toolCalls) || count($toolCalls) !== 1 || ! is_array($toolCalls[0] ?? null)) {
            throw new PhrExternalCompletionRejected('The external completion did not contain exactly one structured result.');
        }
        $call = $toolCalls[0];
        if (($call['name'] ?? null) !== PhrGenAiRequestPreparationService::SUBMISSION_TOOL
            || ! is_array($call['input'] ?? null)) {
            throw new PhrExternalCompletionRejected('The external completion used an unexpected submission tool.');
        }

        return $call['input'];
    }

    private function authorizeApplication(GenAiImportJob $job, User $user, McpRequest $request): void
    {
        if ($job->mcp_request_id !== $request->id
            || $job->execution_mode !== GenAiImportJob::EXECUTION_EXTERNAL
            || ! in_array($job->status, ['pending', 'processing', 'parsed', 'imported'], true)) {
            throw new PhrExternalCompletionRejected('The import no longer accepts this external completion.');
        }
        if (! $user->canLogin() || $user->genAiExecutionMode() !== GenAiImportJob::EXECUTION_EXTERNAL) {
            throw new PhrExternalCompletionRejected('The import owner no longer permits external processing.');
        }
        $document = PhrDocument::query()
            ->where('genai_job_id', $job->id)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();
        if (! $document instanceof PhrDocument) {
            throw new PhrExternalCompletionRejected('The source document is no longer available.');
        }
        $patient = PhrPatient::query()->lockForUpdate()->find($document->patient_id);
        if (! $patient instanceof PhrPatient) {
            throw new PhrExternalCompletionRejected('The patient is no longer available.');
        }
        if ((int) $patient->owner_user_id === (int) $user->id) {
            return;
        }
        $grant = PhrPatientUserAccess::query()
            ->where('patient_id', $patient->id)
            ->where('user_id', $user->id)
            ->whereIn('access_level', [PhrPatientUserAccess::LEVEL_OWNER, PhrPatientUserAccess::LEVEL_MANAGER])
            ->lockForUpdate()
            ->first();
        if (! $grant instanceof PhrPatientUserAccess) {
            throw new PhrExternalCompletionRejected('Patient write access was revoked before completion delivery.');
        }
    }
}

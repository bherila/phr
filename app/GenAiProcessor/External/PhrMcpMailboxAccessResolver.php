<?php

namespace App\GenAiProcessor\External;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\User;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Support\AgentApi\AgentApiScopes;
use Bherila\GenAiLaravel\Contracts\MailboxAccessResolver;
use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\Models\McpDelivery;
use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;
use Throwable;

final readonly class PhrMcpMailboxAccessResolver implements MailboxAccessResolver
{
    public function __construct(private PhrPatientAccessService $patientAccess) {}

    public function resolve(Request $request): ?ExecutionContext
    {
        $user = $request->user('api');
        if (! $user instanceof User || ! $user->canLogin()) {
            return null;
        }
        $token = $user->token();
        $attributes = $token instanceof AccessToken ? $token->toArray() : [];
        $tokenId = $attributes['oauth_access_token_id'] ?? null;
        $clientId = $attributes['oauth_client_id'] ?? null;
        if (app()->environment('testing') && $token instanceof AccessToken
            && (! is_string($tokenId) || $tokenId === '')) {
            // Passport::actingAs intentionally has no persisted token. Keep
            // unrelated feature tests usable while giving each supplied client
            // a deterministic family namespace.
            $clientId = is_string($clientId) && $clientId !== '' ? $clientId : 'testing-client';
            $familyId = 'testing-family';
        } else {
            if (! is_string($tokenId) || $tokenId === '' || ! is_string($clientId) || $clientId === '') {
                return null;
            }
            $persistedToken = Passport::token()->newQuery()->find($tokenId);
            $familyId = $persistedToken?->oauth_family_id;
            if ($persistedToken === null
                || (string) $persistedToken->user_id !== (string) $user->id
                || (string) $persistedToken->client_id !== $clientId
                || ! is_string($familyId)
                || $familyId === '') {
                return null;
            }
        }
        $rawScopes = $attributes['oauth_scopes'] ?? [];
        $scopes = is_array($rawScopes) && $rawScopes !== []
            ? array_values(array_filter($rawScopes, 'is_string'))
            : array_values(array_filter(
                [AgentApiScopes::GENAI_READ, AgentApiScopes::GENAI_WORK],
                fn (string $scope): bool => $user->tokenCan($scope),
            ));
        $mailboxIds = McpMailbox::query()
            ->where('owner_type', User::class)
            ->where('owner_id', (string) $user->id)
            ->where('enabled', true)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return new ExecutionContext(
            principalKey: sprintf(
                'phr:user:%d:oauth:%s',
                $user->id,
                hash('sha256', $clientId."\0".$familyId),
            ),
            mailboxIds: $mailboxIds,
            scopes: $scopes,
        );
    }

    public function authorize(
        ExecutionContext $context,
        McpMailbox $mailbox,
        string $ability,
        ?McpRequest $request = null,
    ): bool {
        if (! $context->can($ability) || ! $mailbox->enabled) {
            return false;
        }
        $userId = $this->userId($context);
        $ownerType = $mailbox->getAttribute('owner_type');
        $ownerId = $mailbox->getAttribute('owner_id');
        if ($userId === null
            || $ownerType !== User::class
            || ! is_string($ownerId)
            || ! hash_equals($ownerId, (string) $userId)) {
            return false;
        }
        $user = User::query()->find($userId);
        if (! $user instanceof User || ! $user->canLogin()) {
            return false;
        }
        if ($request === null) {
            return true;
        }

        $jobId = $request->metadata['phr_import_job_id'] ?? null;
        if (! is_int($jobId) && ! (is_string($jobId) && ctype_digit($jobId))) {
            return false;
        }
        $job = GenAiImportJob::query()
            ->whereKey((int) $jobId)
            ->where('mcp_request_id', $request->id)
            ->where('user_id', $userId)
            ->where('execution_mode', GenAiImportJob::EXECUTION_EXTERNAL)
            ->first();
        if (! $job instanceof GenAiImportJob) {
            return false;
        }
        if ($ability === AgentApiScopes::GENAI_WORK
            && ! in_array($job->status, ['pending', 'processing'], true)
            && ! $this->isDeliveredCompletionReplay($context, $job, $request)) {
            return false;
        }
        $document = $job->sourceDocument()->whereNull('deleted_at')->first();
        if ($document === null) {
            return false;
        }

        try {
            $this->patientAccess->writablePatient((int) $document->patient_id, $userId);
        } catch (AuthorizationException|ModelNotFoundException) {
            // The grant was downgraded, revoked, or the patient itself is
            // gone. This is an ordinary, expected authorization outcome, not
            // an incident, so it stays unlogged rather than adding noise.
            return false;
        } catch (Throwable $exception) {
            // Anything else - a storage or database failure while checking
            // the grant - is unexpected. Failing closed here is still
            // correct (this is an authorization decision on health records),
            // but the denial must not be silent: log enough to correlate it
            // with the underlying failure, without any patient-identifying
            // data in the context.
            Log::error('External GenAI mailbox authorization check failed unexpectedly; denying access.', [
                'job_id' => $job->id,
                'request_id_hash' => hash('sha256', (string) $request->id),
                'exception' => $exception::class,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Narrowly re-opens `genai:work` after terminalization so a client whose
     * successful completion response was lost on the wire can replay the
     * identical completion and finally read the package's idempotent receipt.
     *
     * The permission is a conjunction; every part must hold for this exact
     * job/request pair:
     *
     *  1. the import job reached a state only a delivered proposal produces
     *     (`parsed`/`imported`) — a `failed`, `pending`, `processing` or
     *     `queued_tomorrow` job is never replayable through this path;
     *  2. the queue request already linked to that job (the caller cannot
     *     nominate another one: the job row is matched on `mcp_request_id`)
     *     is itself terminal `completed` and carries the canonical completion
     *     hash, principal and receipt id the package recorded at completion;
     *  3. the durable `completed` delivery for that same request was already
     *     acknowledged, so the proposals exist and delivery will not re-run;
     *  4. the caller is the very principal that recorded that completion.
     *
     * Nothing beyond an exact replay becomes reachable. `McpQueueService`
     * short-circuits `complete()` on a `Completed` request and returns the
     * stored receipt only when the canonical payload hash, the completing
     * lease-token hash and the principal all match — different completion
     * data still conflicts. `claim()`, `renew()`, `fail()`, lease expiry
     * sweeps and the signed attachment download every require a live
     * `Leased` request, which a `Completed` request can never be again.
     *
     * Condition 4 also keeps the failure mode quiet: a principal that did not
     * record the completion is refused here exactly as it is today, so a
     * mutated-payload replay from anyone else is indistinguishable from the
     * pre-existing post-terminalization refusal.
     *
     * The source-document and patient-grant checks in `authorize()` are NOT
     * relaxed: a replay still requires the source document to exist and the
     * caller to hold current write access to its patient, because the receipt
     * returns the stored extraction result.
     */
    private function isDeliveredCompletionReplay(
        ExecutionContext $context,
        GenAiImportJob $job,
        McpRequest $request,
    ): bool {
        if (! in_array($job->status, ['parsed', 'imported'], true)
            || $request->status !== McpRequestStatus::Completed) {
            return false;
        }
        $principal = $request->completion_principal;
        $hash = $request->completion_hash;
        $receiptId = $request->completion_receipt_id;
        if (! is_string($principal) || $principal === ''
            || ! is_string($hash) || $hash === ''
            || ! is_string($receiptId) || $receiptId === ''
            || ! hash_equals($principal, $context->principalKey)) {
            return false;
        }

        return McpDelivery::query()
            ->where('request_id', $request->id)
            ->where('type', 'completed')
            ->whereNotNull('acknowledged_at')
            ->exists();
    }

    private function userId(ExecutionContext $context): ?int
    {
        return preg_match('/^phr:user:(\d+):oauth:[a-f0-9]{64}$/D', $context->principalKey, $matches) === 1
            ? (int) $matches[1]
            : null;
    }
}

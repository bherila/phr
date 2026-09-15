<?php

namespace App\GenAiProcessor\External;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\User;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Support\AgentApi\AgentApiScopes;
use Bherila\GenAiLaravel\Contracts\MailboxAccessResolver;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
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
        if ((! is_string($tokenId) || $tokenId === '') && app()->environment('testing') && $token !== null) {
            $tokenId = 'testing-user-'.$user->id;
        }
        if (! is_string($tokenId) || $tokenId === '') {
            return null;
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
            principalKey: sprintf('phr:user:%d:oauth:%s', $user->id, hash('sha256', $tokenId)),
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
            && ! in_array($job->status, ['pending', 'processing'], true)) {
            return false;
        }
        $document = $job->sourceDocument()->whereNull('deleted_at')->first();
        if ($document === null) {
            return false;
        }

        try {
            $this->patientAccess->writablePatient((int) $document->patient_id, $userId);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function userId(ExecutionContext $context): ?int
    {
        return preg_match('/^phr:user:(\d+):oauth:[a-f0-9]{64}$/D', $context->principalKey, $matches) === 1
            ? (int) $matches[1]
            : null;
    }
}

<?php

namespace App\GenAiProcessor\Support;

use App\GenAiProcessor\Models\GenAiImportJob;
use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\McpQueueService;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;

/**
 * The single source of truth for PHR import statuses that are derived from
 * durable external (package MCP queue) request state.
 *
 * # Why this exists
 *
 * PHR status and package status are two different state machines that a user
 * sees as one. Before this map, three call sites each re-derived the mapping
 * with their own conditionals: the `McpRequestClaimed` / `McpRequestFailed`
 * listeners, the external enqueue service, and the recovery command. Those
 * conditionals were not inverses of one another, so a retryable external
 * failure could return the package request to `pending` while a local
 * recovery timer pushed the PHR row back to `processing` with no external
 * client holding a lease (issue #119).
 *
 * # Which side is authoritative
 *
 * **The package request row is authoritative; local timers are advisory.**
 * The package row is the only record of whether a client currently holds a
 * live lease, and it is the record the client itself writes through. A local
 * recovery timer or a `ParseImportJob` claim only says "nobody has touched
 * this row recently" — that is a dispatch lock, never evidence of external
 * work in flight. Every write below therefore reads the package row and
 * conforms the PHR row to it, never the reverse.
 *
 * # The mapping table
 *
 * `live lease` means the request is `leased` AND `lease_expires_at` is still
 * in the future. A `leased` row whose lease has expired is re-claimable by any
 * client, so no one is working it.
 *
 * | package status | live lease | PHR status  | applied by                                        |
 * | -------------- | ---------- | ----------- | ------------------------------------------------- |
 * | `pending`      | n/a        | `pending`   | this map                                          |
 * | `leased`       | yes        | `processing`| this map                                          |
 * | `leased`       | no         | `pending`   | this map                                          |
 * | `expired`      | n/a        | `failed`    | this map (the package emits no `failed` delivery) |
 * | `completed`    | n/a        | — (no-op)   | PhrMcpCompletionDelivery -> `parsed`              |
 * | `failed`       | n/a        | — (no-op)   | PhrMcpCompletionDelivery -> `failed` (+retry)     |
 * | `cancelled`    | n/a        | — (no-op)   | PhrGenAiExecutionModeService -> new generation    |
 *
 * A `—` entry means the package state does not determine the PHR status: a
 * durable delivery record or the execution-mode transaction owns that write,
 * including the attempt/retry bookkeeping that goes with it. Returning `null`
 * rather than guessing is what keeps this map from fighting those owners.
 *
 * # Invariants
 *
 * - Writes only ever move a row between {@see self::NONTERMINAL_PHR_STATUSES}.
 *   A PHR terminal status (`parsed`, `imported`, `failed`) is never overwritten.
 * - No write here touches `retry_count`, `scheduled_for`, or the package's own
 *   `attempt_count` / `available_at`, so retryable-failure backoff and
 *   maximum-attempt behaviour survive every reconciliation.
 * - Rows are matched by `mcp_request_id`, so a row whose generation has moved
 *   on (execution-mode changes null the link and bump `mcp_generation`) can
 *   never be reconciled from a superseded request.
 */
final class PhrExternalImportStatusMap
{
    /** PHR statuses an external request may still move between. */
    public const NONTERMINAL_PHR_STATUSES = ['pending', 'processing'];

    /** PHR statuses that are final for a user and must never be overwritten. */
    public const TERMINAL_PHR_STATUSES = ['parsed', 'imported', 'failed'];

    /**
     * The mapping table above, in code. This array is the only place a package
     * status is turned into a PHR status.
     *
     * @var array<string, array{with_live_lease: ?string, without_live_lease: ?string}>
     */
    private const MAP = [
        McpRequestStatus::Pending->value => ['with_live_lease' => 'pending', 'without_live_lease' => 'pending'],
        McpRequestStatus::Leased->value => ['with_live_lease' => 'processing', 'without_live_lease' => 'pending'],
        McpRequestStatus::Expired->value => ['with_live_lease' => 'failed', 'without_live_lease' => 'failed'],
        McpRequestStatus::Completed->value => ['with_live_lease' => null, 'without_live_lease' => null],
        McpRequestStatus::Failed->value => ['with_live_lease' => null, 'without_live_lease' => null],
        McpRequestStatus::Cancelled->value => ['with_live_lease' => null, 'without_live_lease' => null],
    ];

    /**
     * Package statuses where the external queue still owns the work, so PHR's
     * own recovery timer must not redispatch or re-enqueue behind its back.
     */
    private const QUEUE_OWNED_PACKAGE_STATUSES = [
        McpRequestStatus::Pending,
        McpRequestStatus::Leased,
    ];

    /** The PHR status demanded by a package status, or null when the map has no opinion. */
    public static function phrStatusFor(McpRequestStatus $status, bool $hasLiveLease): ?string
    {
        // The table is total over McpRequestStatus by construction, so there is
        // no fallback branch to disagree with it.
        $row = self::MAP[$status->value];

        return $row[$hasLiveLease ? 'with_live_lease' : 'without_live_lease'];
    }

    /** The PHR status demanded by a durable request row, or null when the map has no opinion. */
    public static function phrStatusForRequest(McpRequest $request): ?string
    {
        return self::phrStatusFor($request->status, self::hasLiveLease($request));
    }

    /**
     * A lease is live only while the package would refuse to hand the request
     * to another client. {@see McpQueueService::claim()}
     * re-claims a `leased` row once `lease_expires_at` has passed.
     */
    public static function hasLiveLease(McpRequest $request): bool
    {
        return $request->status === McpRequestStatus::Leased
            && $request->lease_expires_at !== null
            && $request->lease_expires_at->isFuture();
    }

    /** Whether the external queue still owns this request's work. */
    public static function queueOwnsWork(?McpRequest $request): bool
    {
        return $request !== null
            && in_array($request->status, self::QUEUE_OWNED_PACKAGE_STATUSES, true);
    }

    /**
     * Conform every PHR row linked to this request to the mapping table.
     *
     * Returns true when a row actually changed. Safe to call repeatedly.
     */
    public static function reconcile(McpRequest $request): bool
    {
        $target = self::phrStatusForRequest($request);
        if ($target === null) {
            return false;
        }

        return GenAiImportJob::query()
            ->where('mcp_request_id', $request->id)
            ->where('execution_mode', GenAiImportJob::EXECUTION_EXTERNAL)
            ->whereIn('status', self::NONTERMINAL_PHR_STATUSES)
            ->where('status', '!=', $target)
            ->update(['status' => $target, 'updated_at' => now()]) > 0;
    }

    /** Reconcile by request id, tolerating a request that has since been pruned. */
    public static function reconcileRequestId(string $requestId): bool
    {
        $request = McpRequest::query()->find($requestId);

        return $request instanceof McpRequest && self::reconcile($request);
    }

    /**
     * Apply a write that only this API execution generation is entitled to make.
     *
     * Everything an API run does after it has called the provider races a
     * successor generation: the execution-mode transaction bumps
     * `mcp_generation` and dispatches a successor run that can claim, complete
     * and durably deliver before the old provider call returns. A read-then-
     * write would clobber the successor's `parsed` row and its provider
     * metadata (issue #120), so the generation, execution mode and nonterminal
     * status all belong in the WHERE clause. A superseded run simply loses.
     *
     * Returns true when this run still owned the row and the write landed.
     *
     * @param  array<string, mixed>  $values
     */
    public static function updateApiGeneration(int $jobId, int $apiGeneration, array $values): bool
    {
        return GenAiImportJob::query()
            ->whereKey($jobId)
            ->where('mcp_generation', $apiGeneration)
            ->where('execution_mode', GenAiImportJob::EXECUTION_API)
            ->whereIn('status', self::NONTERMINAL_PHR_STATUSES)
            ->update([...$values, 'updated_at' => now()]) === 1;
    }

    /**
     * Hand a still-nonterminal API execution generation to the external queue.
     *
     * Returns true when this run still owned the row and now owns the handoff.
     */
    public static function handOffApiGenerationToExternal(int $jobId, int $apiGeneration): bool
    {
        return self::updateApiGeneration($jobId, $apiGeneration, [
            'execution_mode' => GenAiImportJob::EXECUTION_EXTERNAL,
            'status' => 'pending',
            'raw_response' => null,
            'input_tokens' => null,
            'output_tokens' => null,
        ]);
    }
}

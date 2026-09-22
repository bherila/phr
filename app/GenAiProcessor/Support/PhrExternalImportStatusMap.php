<?php

namespace App\GenAiProcessor\Support;

use App\GenAiProcessor\Models\GenAiImportJob;
use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\McpQueueService;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

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
 * - A reconciling write never represents an older queue state than one already
 *   applied: {@see self::reconcile()} re-reads the request row under a lock and
 *   derives the target inside that same transaction, so the caller's in-memory
 *   instance supplies identity only, never state.
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
     * The argument supplies identity only. A caller can hold an instance that
     * is already several state changes old — `enqueue()` returns the request it
     * read before handing it back, and a client may claim it in that window —
     * and writing a status derived from that snapshot would let an older queue
     * state overwrite a newer one, which is exactly what this map exists to
     * prevent. The request row is therefore re-read under a lock and the target
     * derived inside the same transaction as the write, so reconciliation is
     * serialized against the package's own claim/fail transactions.
     *
     * Returns true when a row actually changed. Safe to call repeatedly.
     */
    public static function reconcile(McpRequest $request): bool
    {
        return self::reconcileRequestId((string) $request->getKey());
    }

    /** Reconcile by request id, tolerating a request that has since been pruned. */
    public static function reconcileRequestId(string $requestId): bool
    {
        return DB::transaction(static function () use ($requestId): bool {
            $request = McpRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            if (! $request instanceof McpRequest) {
                return false;
            }

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
        });
    }

    /**
     * Recover one import revision whose external enqueue failed after the
     * revision was already linked to a request, by conforming it to that
     * request rather than guessing.
     *
     * A linked row is exactly the case where the failed attempt knows least
     * and the durable request knows most: a client may hold - or may have
     * just gained - a live lease on it, and forcing the import to `pending`
     * then contradicts the table above. The target therefore comes from
     * {@see self::phrStatusForRequest()}, derived from the request row as it
     * reads under a lock, never from the failure.
     *
     * This is {@see self::reconcileRequestId()} narrowed to one owned
     * revision, not a call to it, for three reasons:
     *
     * - reconcile() matches every external row linked to the request, with no
     *   generation or job fence. Recovery is a compare-and-swap on the
     *   revision the failed attempt captured - execution mode, generation, the
     *   link it started from, still nonterminal - and those predicates have to
     *   sit in the same UPDATE, inside the transaction that holds the request
     *   lock, so a successor that relinked, regenerated or terminalized the
     *   row in the meantime matches nothing.
     * - reconcile() reports only "a row changed" and treats a pruned request
     *   as nothing to do. Recovery has to tell a pruned request (nothing owns
     *   the work, so the import falls back to PHR's own recovery, as
     *   {@see self::queueOwnsWork()} says of null) from a request whose state
     *   the map has no opinion about (leave the row to that write's owner).
     * - A recovery that lands on `pending` also records why the import is
     *   waiting, in the same write.
     *
     * Lock order is reconcileRequestId()'s: the request row first, under
     * `lockForUpdate`, then the import row through the UPDATE. No PHR path
     * locks an import row and then a request row.
     *
     * Nothing here is caught. When the database cannot establish the request's
     * state the transaction rolls back and the exception propagates, so the
     * caller leaves the import as it was instead of writing a guess over it.
     *
     * Returns the locked request's status (`request_status`, null when it has
     * been pruned) and the status this call wrote (`import_status`, null when
     * the map has no opinion or the attempt no longer owns the row).
     *
     * @return array{request_status: string|null, import_status: string|null}
     */
    public static function recoverLinkedRevision(
        int $jobId,
        string $ownedMode,
        int $ownedGeneration,
        string $ownedLink,
        string $pendingMessage,
    ): array {
        return DB::transaction(static function () use ($jobId, $ownedMode, $ownedGeneration, $ownedLink, $pendingMessage): array {
            $request = McpRequest::query()->whereKey($ownedLink)->lockForUpdate()->first();
            $requestStatus = $request instanceof McpRequest ? $request->status->value : null;
            // A pruned request owns no work, so the import waits for PHR's own
            // pending recovery to re-enqueue it.
            $target = $request instanceof McpRequest ? self::phrStatusForRequest($request) : 'pending';
            if ($target === null) {
                return ['request_status' => $requestStatus, 'import_status' => null];
            }

            $values = ['status' => $target, 'updated_at' => now()];
            if ($target === 'pending') {
                // Only a row that is waiting says why. A `processing` row has
                // a client working it, and an `expired` request's `failed` is
                // written without an annotation on every other path too.
                $values['error_message'] = $pendingMessage;
            }
            $written = GenAiImportJob::query()
                ->whereKey($jobId)
                ->where('execution_mode', $ownedMode)
                ->where('mcp_generation', $ownedGeneration)
                ->when(
                    $request instanceof McpRequest,
                    fn (Builder $query) => $query->where('mcp_request_id', $ownedLink),
                    // `mcp_request_id` is a `nullOnDelete` foreign key, so once
                    // the request is pruned a null link and the pruned link are
                    // the same observation - the predicate the enqueue
                    // service's vanished-link reset uses. The nonterminal
                    // clause below is what keeps a same-generation
                    // terminalization, which also nulls the link, out of it.
                    fn (Builder $query) => $query->where(fn (Builder $link) => $link
                        ->where('mcp_request_id', $ownedLink)
                        ->orWhereNull('mcp_request_id')),
                )
                ->whereIn('status', self::NONTERMINAL_PHR_STATUSES)
                ->update($values);

            return ['request_status' => $requestStatus, 'import_status' => $written === 1 ? $target : null];
        });
    }

    /**
     * Narrow a {@see GenAiImportJob} query to external rows whose durable
     * request demands a status the row is not already showing.
     *
     * Eligibility belongs in SQL rather than in a skip after the fact. The
     * recovery command inspects a bounded, id-ordered batch, so a batch filled
     * with rows reconciliation cannot change re-selects those same rows on
     * every scheduled run and starves every later import out of the pass. The
     * predicate is generated from {@see self::MAP}, so the mapping table stays
     * the only place a package status becomes a PHR status.
     *
     * @param  Builder<GenAiImportJob>  $query
     * @return Builder<GenAiImportJob>
     */
    public static function scopeReconcilable(Builder $query): Builder
    {
        $jobs = $query->getModel()->getTable();
        $now = now();

        return $query
            ->where($jobs.'.execution_mode', GenAiImportJob::EXECUTION_EXTERNAL)
            ->whereIn($jobs.'.status', self::NONTERMINAL_PHR_STATUSES)
            ->whereNotNull($jobs.'.mcp_request_id')
            ->where(static function (Builder $eligible) use ($jobs, $now): void {
                foreach (McpRequestStatus::cases() as $status) {
                    $withLease = self::phrStatusFor($status, true);
                    $withoutLease = self::phrStatusFor($status, false);
                    if ($withLease === $withoutLease) {
                        // Lease liveness cannot change the outcome, so one
                        // branch covers the package status; `null` on both
                        // sides means the map states no opinion at all.
                        if ($withLease !== null) {
                            self::orReconcilableBranch($eligible, $jobs, $status, null, $withLease, $now);
                        }

                        continue;
                    }
                    if ($withLease !== null) {
                        self::orReconcilableBranch($eligible, $jobs, $status, true, $withLease, $now);
                    }
                    if ($withoutLease !== null) {
                        self::orReconcilableBranch($eligible, $jobs, $status, false, $withoutLease, $now);
                    }
                }
            });
    }

    /**
     * Narrow a {@see GenAiImportJob} query to rows the external queue does not
     * own — the rows {@see self::queueOwnsWork()} answers false for.
     *
     * Same reason as above: PHR's pending recovery must leave a queue-owned
     * request to the package's own backoff, and skipping it in PHP after the
     * `LIMIT` lets a batch of permanently skipped rows hide every later job.
     *
     * @param  Builder<GenAiImportJob>  $query
     * @return Builder<GenAiImportJob>
     */
    public static function scopeQueueDoesNotOwnWork(Builder $query): Builder
    {
        $jobs = $query->getModel()->getTable();
        $requests = (new McpRequest)->getTable();

        return $query->where(static function (Builder $eligible) use ($jobs, $requests): void {
            $eligible
                ->where($jobs.'.execution_mode', '!=', GenAiImportJob::EXECUTION_EXTERNAL)
                ->orWhereNull($jobs.'.mcp_request_id')
                // A pruned request owns nothing, which is what
                // queueOwnsWork(null) already says.
                ->orWhereNotExists(static function (QueryBuilder $request) use ($jobs, $requests): void {
                    $request->selectRaw('1')
                        ->from($requests)
                        ->whereColumn($requests.'.id', $jobs.'.mcp_request_id')
                        ->whereIn($requests.'.status', array_map(
                            static fn (McpRequestStatus $status): string => $status->value,
                            self::QUEUE_OWNED_PACKAGE_STATUSES,
                        ));
                });
        });
    }

    /**
     * One `(the request is in this state) AND (the row does not already show
     * the status that state maps to)` disjunct of {@see self::scopeReconcilable()}.
     *
     * @param  Builder<GenAiImportJob>  $query
     * @param  bool|null  $hasLiveLease  null when lease liveness cannot change the outcome
     */
    private static function orReconcilableBranch(
        Builder $query,
        string $jobs,
        McpRequestStatus $status,
        ?bool $hasLiveLease,
        string $target,
        CarbonInterface $now,
    ): void {
        $requests = (new McpRequest)->getTable();

        $query->orWhere(static function (Builder $branch) use ($jobs, $requests, $status, $hasLiveLease, $target, $now): void {
            $branch
                ->where($jobs.'.status', '!=', $target)
                ->whereExists(static function (QueryBuilder $request) use ($jobs, $requests, $status, $hasLiveLease, $now): void {
                    $request->selectRaw('1')
                        ->from($requests)
                        ->whereColumn($requests.'.id', $jobs.'.mcp_request_id')
                        ->where($requests.'.status', $status->value);
                    if ($hasLiveLease !== null) {
                        self::whereLeaseLiveness($request, $requests, $hasLiveLease, $now);
                    }
                });
        });
    }

    /**
     * {@see self::hasLiveLease()} expressed over the request table.
     *
     * The negative case is spelled out instead of wrapped in `NOT`, so a
     * `leased` row with a null `lease_expires_at` still reads as "no live
     * lease" rather than vanishing into three-valued logic.
     */
    private static function whereLeaseLiveness(QueryBuilder $request, string $requests, bool $hasLiveLease, CarbonInterface $now): void
    {
        if ($hasLiveLease) {
            $request
                ->where($requests.'.status', McpRequestStatus::Leased->value)
                ->where($requests.'.lease_expires_at', '>', $now);

            return;
        }

        $request->where(static function (QueryBuilder $lease) use ($requests, $now): void {
            $lease
                ->where($requests.'.status', '!=', McpRequestStatus::Leased->value)
                ->orWhereNull($requests.'.lease_expires_at')
                ->orWhere($requests.'.lease_expires_at', '<=', $now);
        });
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

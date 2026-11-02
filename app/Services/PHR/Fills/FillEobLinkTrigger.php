<?php

namespace App\Services\PHR\Fills;

use App\Models\PhrEob;
use App\Models\PhrEobLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs {@see FillEobLinker} after a fill, a pharmacy claim or a person's link changes, once the
 * transaction that changed it has committed, however many rows that transaction wrote.
 *
 * Synchronous, in the request, like every other evidence write on these paths: the links a write
 * caused are there when its response is read, recomputing one patient is a handful of queries,
 * and no queue worker is needed for a record to be right.
 *
 * A failure here is logged and swallowed: the write has committed and its caller is not told.
 * The log carries the patient id and the exception's class only, never its message, which could
 * carry query bindings. The next write, or the backfill command, recomputes from scratch.
 */
final class FillEobLinkTrigger
{
    /** @var array<int, true> patients with a recompute still to run */
    private static array $pending = [];

    public static function schedule(int $patientId): void
    {
        self::$pending[$patientId] = true;
        // One callback per change, because a rollback discards callbacks and not this list. The
        // first to run after a commit does the work.
        DB::afterCommit(static function () use ($patientId): void {
            self::run($patientId);
        });
    }

    /** A line's claim may be any type, and only pharmacy claims concern the linker. */
    public static function scheduleForLine(PhrEobLine $line): void
    {
        try {
            if (PhrEob::query()->whereKey($line->eob_id)->where('claim_type', 'pharmacy')->exists()) {
                self::schedule((int) $line->patient_id);
            }
        } catch (Throwable $failure) {
            self::report((int) $line->patient_id, $failure);
        }
    }

    private static function run(int $patientId): void
    {
        if (! isset(self::$pending[$patientId])) {
            return;
        }
        unset(self::$pending[$patientId]);

        try {
            app(FillEobLinker::class)->recompute($patientId);
        } catch (Throwable $failure) {
            self::report($patientId, $failure);
        }
    }

    private static function report(int $patientId, Throwable $failure): void
    {
        Log::warning('Linking fills to pharmacy claims failed.', ['patient_id' => $patientId, 'exception' => $failure::class]);
    }
}

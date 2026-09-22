<?php

namespace App\Support\Logging;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Best-effort diagnostic logging for graceful-degradation paths.
 *
 * `config/logging.php` runs the `stack` channel with `ignore_exceptions` set
 * to `false`, so a broken log destination (full or read-only
 * `storage/logs`, a misconfigured `LOG_STACK` channel) makes
 * `Log::error()` / `Log::warning()` / `Log::info()` throw. Every call site that reaches for
 * this helper already sits inside a `catch` whose only job is to degrade
 * gracefully and return a safe value; a throwing logger would turn that
 * into an uncaught exception, converting a handled degradation into an
 * HTTP 500 or a crashed job. SafeLog swallows logging failures instead, so
 * a broken log destination never changes the surrounding business outcome.
 *
 * This is deliberately narrow - it is not a general-purpose exception
 * redaction framework. Callers remain fully responsible for the logging
 * boundary contract:
 *
 *  - `$message` must be a fixed, literal event identifier, never
 *    interpolated from exception text or request data.
 *  - `$context` must already be a pre-approved, per-event allowlist. Never
 *    pass an exception's message, its trace, its previous chain, SQL or SQL
 *    bindings, a patient identifier, a document name or filename, a prompt,
 *    or any other clinical payload. `$exception::class` is fine;
 *    `$exception->getMessage()` is not.
 *  - Internal job ids and hashed request ids are legitimate, useful
 *    context - they are linkable operational metadata, not de-identified or
 *    anonymous data, so treat them with the same care as any other
 *    operational log.
 *
 * SafeLog does not inspect `$context` for PHI; it only bounds it to scalar
 * values (see `normalize()`) so a caller mistake - passing an exception
 * object, a model, or a nested array - degrades to a dropped key instead of
 * a second thrown exception. It cannot and does not decide what is safe to
 * log; that decision is made once, by the caller, when the context array is
 * built.
 *
 * Do NOT reach for this on a mandatory audit or security record. Swallowing
 * an audit write is a silent loss of an operational record, not a graceful
 * degradation - log those with `Log` directly and let a broken destination
 * fail loudly.
 */
final class SafeLog
{
    private function __construct() {}

    /**
     * For a success-path diagnostic written after the work it describes has
     * already succeeded, where a throwing logger would otherwise be caught
     * by the surrounding business `catch` and treated as a failure of that
     * work. Same contract as warning()/error(); the level stays `info`
     * rather than being raised to fit the helper.
     *
     * @param  array<string, scalar|null>  $context  a pre-approved, per-event context allowlist
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /**
     * @param  array<string, scalar|null>  $context  a pre-approved, per-event context allowlist
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /**
     * @param  array<string, scalar|null>  $context  a pre-approved, per-event context allowlist
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function write(string $level, string $message, array $context): void
    {
        $safeContext = self::normalize($context);

        try {
            match ($level) {
                'error' => Log::error($message, $safeContext),
                'info' => Log::info($message, $safeContext),
                default => Log::warning($message, $safeContext),
            };

            return;
        } catch (Throwable) {
            // The configured log destination is itself unavailable. Do not
            // retry it and do not call report() - that would re-enter the
            // same broken machinery. Fall through to a sink that does not
            // depend on the Log facade or the Monolog stack at all.
        }

        self::fallback($level, $message, $safeContext);
    }

    /**
     * Bounds an already-reviewed context to plain scalars so a caller
     * mistake cannot smuggle an unreviewed object/array into the write, and
     * so normalisation itself can never be the thing that throws.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<string, scalar|null>
     */
    private static function normalize(array $context): array
    {
        try {
            $safe = [];
            foreach ($context as $key => $value) {
                if (! is_string($key) || $key === '') {
                    continue;
                }
                if (is_string($value)) {
                    $safe[$key] = mb_strimwidth($value, 0, 500, '…');
                } elseif (is_scalar($value) || $value === null) {
                    $safe[$key] = $value;
                }
                // Anything else (arrays, objects, exceptions, models, ...) is
                // dropped rather than serialised: this call site never
                // reviewed what serialising it would expose.
            }

            return $safe;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * A last-resort write that bypasses the Log stack: PHP's own
     * `error_log()` goes to the SAPI/php.ini-configured destination, never
     * through Monolog or Laravel's logging config, so a failure in either of
     * those does not repeat here. It is not an independent failure domain,
     * though - that destination can itself be full, unwritable or
     * misconfigured, possibly for the same reason the primary failed. Hence
     * the guard: a failure of this fallback must not throw either.
     *
     * @param  array<string, scalar|null>  $context
     */
    private static function fallback(string $level, string $message, array $context): void
    {
        try {
            $encodedContext = json_encode($context, JSON_UNESCAPED_SLASHES);
            error_log(sprintf(
                '[phr.safelog.%s] %s %s',
                $level,
                $message,
                is_string($encodedContext) ? $encodedContext : '{}',
            ));
        } catch (Throwable) {
            // Genuinely nothing more can be done here. The caller's business
            // outcome (already decided before this write was attempted) must
            // not change because a diagnostic write failed twice over.
        }
    }
}

<?php

namespace App\Support\Logging;

/**
 * Test-only override of the built-in `error_log()`, reachable because PHP
 * resolves an unqualified function call by first checking the *calling*
 * namespace (`App\Support\Logging`, same as `SafeLog`) before falling back
 * to the global function. `SafeLog::fallback()` calls `error_log()`
 * unqualified, so once this file is `require`d, that call resolves here
 * instead of to the real built-in.
 *
 * This exists to test `SafeLog::fallback()`'s own `try`/`catch`: in this
 * environment the real `error_log()` never throws, even pointed at an
 * invalid destination - it degrades further on its own. Forcing a genuine
 * throw here is the only honest way to prove that guard does something.
 */
function safelog_test_error_log_should_throw(bool $shouldThrow): void
{
    $GLOBALS['__safelog_test_error_log_throws'] = $shouldThrow;
}

function error_log(string $message, int $message_type = 0, ?string $destination = null, ?string $extra_headers = null): bool
{
    if ($GLOBALS['__safelog_test_error_log_throws'] ?? false) {
        throw new \RuntimeException('forced error_log() failure for SafeLogTest');
    }

    return \error_log($message, $message_type, $destination ?? '', $extra_headers ?? '');
}

<?php

namespace App\Support\Logging;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

/**
 * SDK logger that never forwards SDK messages or context to a log sink.
 * MCP tool data and schema diagnostics can contain health information, and a
 * throwing logger must not replace the fixed protocol error with its message.
 */
final class McpSafeLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @param mixed $level */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        // Deliberately discard the SDK message and context: their contents are
        // not part of the approved logging contract for this protocol boundary.
        SafeLog::warning('MCP SDK emitted a diagnostic event.');
    }
}

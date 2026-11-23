<?php

namespace App\Support\AgentApi;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * One window of a long text, counted in characters (Unicode code points of the
 * UTF-8 text), never bytes, so a window never splits a multibyte character and
 * consecutive windows (offset, then next_offset) reassemble the text exactly.
 *
 * The window is also bounded by its JSON-escaped size, so the worst case (every
 * character escaped as a surrogate pair or control escape, and the tool result
 * carrying the text twice, once more escaped) stays well under the MCP response
 * cap, `agent_api.mcp_max_response_body_bytes`.
 */
final readonly class AgentTextWindow
{
    public const int DEFAULT_MAX_CHARS = 50_000;

    public const int MAX_CHARS = 100_000;

    /** The most a window's text may take as a JSON string with default escaping. */
    public const int MAX_ENCODED_BYTES = 196_608;

    private function __construct(
        public string $content,
        public int $offset,
        public int $totalChars,
    ) {}

    public static function of(string $text, int $offset, int $maxChars, int $maxEncodedBytes = self::MAX_ENCODED_BYTES): self
    {
        $total = mb_strlen($text, 'UTF-8');
        if ($offset > $total) {
            throw new UnprocessableEntityHttpException("offset is past the end of the text ({$total} characters).");
        }
        $window = mb_substr($text, $offset, max(1, $maxChars), 'UTF-8');
        // Shrink proportionally until the escaped window fits. Each pass removes
        // characters while it is over budget, and one character always fits.
        while (($encoded = strlen(json_encode($window, JSON_THROW_ON_ERROR))) > $maxEncodedBytes) {
            $chars = mb_strlen($window, 'UTF-8');
            $keep = max(1, min($chars - 1, intdiv($chars * $maxEncodedBytes, $encoded) - 1));
            $window = mb_substr($window, 0, $keep, 'UTF-8');
        }

        return new self($window, $offset, $total);
    }

    public function returnedChars(): int
    {
        return mb_strlen($this->content, 'UTF-8');
    }

    /** Whether text follows this window. */
    public function truncated(): bool
    {
        return $this->offset + $this->returnedChars() < $this->totalChars;
    }

    public function nextOffset(): ?int
    {
        return $this->truncated() ? $this->offset + $this->returnedChars() : null;
    }
}

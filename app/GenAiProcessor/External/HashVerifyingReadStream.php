<?php

namespace App\GenAiProcessor\External;

use Closure;
use HashContext;
use InvalidArgumentException;
use Throwable;

/**
 * Read-only stream that hashes the bytes of an inner stream as they are consumed
 * and reaches a SHA-256 verdict at the end of the expected byte count.
 *
 * This lets an attachment be verified and delivered from a single read of the
 * object: the bytes hashed are exactly the bytes handed to the caller, so there
 * is no window between a verifying read and a delivering read.
 *
 * - At most $expectedSize bytes are ever passed through; anything beyond that is
 *   never read, so only hashed bytes can be delivered.
 * - When the read that completes $expectedSize bytes produces a mismatching hash,
 *   that final chunk is withheld (the caller sees a short body), and $onMismatch
 *   runs exactly once.
 * - A stream that ends early, or is closed before the verdict, is inconclusive
 *   and triggers nothing: a broken transfer is not evidence of mutation.
 *
 * PHP only instantiates stream wrappers by class name, so the per-stream state is
 * passed through the stream context under the {@see self::PROTOCOL} key.
 */
final class HashVerifyingReadStream
{
    public const PROTOCOL = 'phr-hash-verifying';

    /** @var resource|null Set by PHP when the stream is opened with a context. */
    public $context;

    /** @var resource|null */
    private $inner;

    private HashContext $hash;

    private string $expectedHash = '';

    private int $size = 0;

    private int $remaining = 0;

    private bool $finished = false;

    private ?Closure $onMismatch = null;

    /**
     * @param  resource  $inner
     * @param  Closure(): void  $onMismatch
     * @return resource
     */
    public static function wrap($inner, string $expectedSha256, int $expectedSize, Closure $onMismatch)
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
        $context = stream_context_create([self::PROTOCOL => [
            'inner' => $inner,
            'sha256' => strtolower($expectedSha256),
            'size' => $expectedSize,
            'on_mismatch' => $onMismatch,
        ]]);
        $stream = fopen(self::PROTOCOL.'://attachment', 'rb', false, $context);
        if (! is_resource($stream)) {
            fclose($inner);
            throw new InvalidArgumentException('Unable to open the hash-verifying attachment stream.');
        }

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $settings = is_resource($this->context) ? stream_context_get_options($this->context)[self::PROTOCOL] ?? null : null;
        if (! is_array($settings) || ! is_resource($settings['inner'] ?? null) || ! ($settings['on_mismatch'] ?? null) instanceof Closure) {
            return false;
        }
        $this->inner = $settings['inner'];
        $this->expectedHash = (string) $settings['sha256'];
        $this->size = (int) $settings['size'];
        $this->remaining = $this->size;
        $this->onMismatch = $settings['on_mismatch'];
        $this->hash = hash_init('sha256');

        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->finished || ! is_resource($this->inner)) {
            return '';
        }
        if ($this->remaining <= 0) {
            // A zero-byte attachment: the verdict is available before any read.
            return $this->finish('');
        }
        $chunk = fread($this->inner, max(1, min($count, $this->remaining)));
        if ($chunk === false || $chunk === '') {
            // The inner stream ended or failed short of the expected size. That is
            // an inconclusive read, never a verdict. Stop here either way so a
            // stalled inner stream cannot spin the caller's read loop.
            $this->finished = true;

            return $chunk === false ? false : '';
        }
        hash_update($this->hash, $chunk);
        $this->remaining -= strlen($chunk);
        if ($this->remaining > 0) {
            return $chunk;
        }

        return $this->finish($chunk);
    }

    public function stream_eof(): bool
    {
        return $this->finished || ! is_resource($this->inner);
    }

    public function stream_close(): void
    {
        if (is_resource($this->inner)) {
            fclose($this->inner);
        }
        $this->inner = null;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return ['size' => $this->size];
    }

    private function finish(string $finalChunk): string
    {
        $this->finished = true;
        if (hash_equals($this->expectedHash, hash_final($this->hash))) {
            return $finalChunk;
        }
        $onMismatch = $this->onMismatch;
        $this->onMismatch = null;
        if ($onMismatch instanceof Closure) {
            // This runs inside a read, typically while a streamed response is
            // being sent after its headers. An exception here cannot become an
            // error response, so it is reported and the chunk is still withheld;
            // the caller sees a short body and lease recovery retries the request.
            try {
                $onMismatch();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return '';
    }
}

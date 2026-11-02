<?php

namespace Tests\Support;

/** A read stream that cannot seek or stat and returns a little at a time. */
final class NetworkLikeStream
{
    private const string PROTOCOL = 'phr-test-network';

    /** @var resource */
    public $context;

    /** @var resource|null */
    private $inner = null;

    /**
     * @param  resource  $inner
     * @return resource
     */
    public static function wrap($inner)
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
        $stream = fopen(self::PROTOCOL.'://object', 'rb', false, stream_context_create([self::PROTOCOL => ['inner' => $inner]]));
        assert(is_resource($stream));

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $this->inner = stream_context_get_options($this->context)[self::PROTOCOL]['inner'] ?? null;

        return is_resource($this->inner);
    }

    public function stream_read(int $count): string|false
    {
        return is_resource($this->inner) ? fread($this->inner, min($count, 1024)) : false;
    }

    public function stream_eof(): bool
    {
        return ! is_resource($this->inner) || feof($this->inner);
    }

    public function stream_close(): void
    {
        if (is_resource($this->inner)) {
            fclose($this->inner);
        }
    }
}

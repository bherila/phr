<?php

namespace App\Services\PHR\NativeBackup;

use php_user_filter;
use stdClass;

/**
 * A read filter that passes bytes through unchanged while hashing them, so an
 * artifact is verified as it is written into the archive rather than by an
 * earlier, separate read that a concurrent change could make stale.
 */
final class PhrNativeHashingFilter extends php_user_filter
{
    public const string NAME = 'phr.native.hashing';

    /**
     * @param  resource  $stream
     * @return stdClass with HashContext `context` and int `bytes`; finish() reads it
     */
    public static function attach($stream): stdClass
    {
        if (! in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }
        $holder = (object) ['context' => hash_init('sha256'), 'bytes' => 0];
        stream_filter_append($stream, self::NAME, STREAM_FILTER_READ, $holder);

        return $holder;
    }

    /** @return array{sha256: string, bytes: int} */
    public static function finish(stdClass $holder): array
    {
        return ['sha256' => hash_final($holder->context), 'bytes' => (int) $holder->bytes];
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @param  int  $consumed
     *
     * @param-out int $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        /** @var stdClass $holder */
        $holder = $this->params;
        while ($bucket = stream_bucket_make_writeable($in)) {
            hash_update($holder->context, $bucket->data);
            $holder->bytes = (int) $holder->bytes + strlen($bucket->data);
            $consumed += (int) $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}

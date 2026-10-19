<?php

namespace App\Services\PHR\Import\Ehi;

use php_user_filter;
use stdClass;

/**
 * A read filter that passes every byte through unchanged while keeping the
 * first `limit` bytes, so a caller can derive something (a member's first
 * line) from exactly the read it hashed instead of a separate, earlier read.
 */
final class EhiPrefixCapture extends php_user_filter
{
    public const string NAME = 'phr.ehi.prefix-capture';

    /**
     * Attach to a readable stream; the returned holder's prefix fills as the
     * stream is read.
     *
     * @param  resource  $stream
     * @return stdClass with int `limit` and string `prefix`
     */
    public static function attach($stream, int $limit): stdClass
    {
        if (! in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }
        $holder = (object) ['limit' => $limit, 'prefix' => ''];
        stream_filter_append($stream, self::NAME, STREAM_FILTER_READ, $holder);

        return $holder;
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
            $room = (int) $holder->limit - strlen((string) $holder->prefix);
            if ($room > 0) {
                $holder->prefix .= substr($bucket->data, 0, $room);
            }
            $consumed += (int) $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}

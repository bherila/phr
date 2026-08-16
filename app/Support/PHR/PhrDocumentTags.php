<?php

namespace App\Support\PHR;

use Illuminate\Support\Str;

final class PhrDocumentTags
{
    /** @return list<string> */
    public static function normalize(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        $normalized = [];
        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            $trimmed = trim($tag);
            if ($trimmed !== '') {
                $normalized[Str::lower($trimmed)] = $trimmed;
            }
        }

        return array_values($normalized);
    }
}

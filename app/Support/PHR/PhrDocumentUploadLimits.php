<?php

namespace App\Support\PHR;

final class PhrDocumentUploadLimits
{
    public const int MAX_KILOBYTES = 51_200;

    public const int MAX_BYTES = self::MAX_KILOBYTES * 1024;
}

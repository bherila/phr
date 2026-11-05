<?php

namespace App\Services\PHR\Import\Ehi;

/** Safe public failure detail. Time stops restart proposal generation, without a saved cursor. */
enum EhiPreviewLimit: string
{
    case Time = 'time';
    case Capacity = 'capacity';
}

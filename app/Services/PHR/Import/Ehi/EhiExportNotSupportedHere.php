<?php

namespace App\Services\PHR\Import\Ehi;

use InvalidArgumentException;

/**
 * Raised by a non-EHI importer (MyChart ZIP dispatch) that recognized an EHI Tables
 * export, so the export fails loudly instead of importing as an empty success.
 */
final class EhiExportNotSupportedHere extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Recognized an Epic EHI Tables export; use phr:ehi:intake.');
    }
}

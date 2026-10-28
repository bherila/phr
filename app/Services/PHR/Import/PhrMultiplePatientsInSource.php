<?php

namespace App\Services\PHR\Import;

use InvalidArgumentException;

/** A structured file or ZIP holds records for more than one person; nothing was imported. */
final class PhrMultiplePatientsInSource extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('The records name more than one person (different birth dates); nothing was imported.');
    }
}

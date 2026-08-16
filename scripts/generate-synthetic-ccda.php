<?php

use Tests\Support\PhrCcdaSyntheticDocument;

require dirname(__DIR__).'/vendor/autoload.php';

fwrite(STDOUT, PhrCcdaSyntheticDocument::xml());

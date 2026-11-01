<?php

namespace App\Services\PHR\Ndc;

use RuntimeException;

/** openFDA could not answer (timeout, rate limit, server error), as opposed to answering "no such product". */
final class OpenFdaUnavailableException extends RuntimeException {}

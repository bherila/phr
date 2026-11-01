<?php

namespace App\Services\PHR\Ndc;

use RuntimeException;

/** RxNav could not answer (timeout, rate limit, server error), as opposed to answering "no such NDC". */
final class RxNavUnavailableException extends RuntimeException {}

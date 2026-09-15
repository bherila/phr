<?php

namespace App\GenAiProcessor\External;

use RuntimeException;

/** A permanent domain rejection that should acknowledge durable delivery. */
final class PhrExternalCompletionRejected extends RuntimeException {}

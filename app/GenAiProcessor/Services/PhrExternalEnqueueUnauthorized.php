<?php

namespace App\GenAiProcessor\Services;

use RuntimeException;

/**
 * A permanent loss of the authorization external work depends on: the owning
 * account can no longer sign in, the source document was deleted, or the
 * patient write grant was revoked.
 *
 * Infrastructure failures (storage or database outages) are deliberately NOT
 * reported with this exception; they stay ordinary throwables so the import
 * remains retryable.
 */
final class PhrExternalEnqueueUnauthorized extends RuntimeException {}

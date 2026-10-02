<?php

namespace App\Domain\Identity\Oidc;

use RuntimeException;

/**
 * An ID token failed a check. The message names the check (e.g. "aud"),
 * never the token or its claims, so it is safe to log.
 */
class InvalidIdToken extends RuntimeException {}

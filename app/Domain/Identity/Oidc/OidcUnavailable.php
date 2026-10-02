<?php

namespace App\Domain\Identity\Oidc;

use RuntimeException;

/**
 * The identity provider's metadata or keys could not be loaded. The message
 * is meant for administrators (ada:doctor, Admin > Sign-in).
 */
final class OidcUnavailable extends RuntimeException {}

<?php

namespace App\Domain\Identity\Exceptions;

use DomainException;

/**
 * The change would leave the instance without an active super administrator.
 */
final class LastSuperAdmin extends DomainException {}

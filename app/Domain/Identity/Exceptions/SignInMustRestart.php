<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * The IdP sent a response Ada did not ask for (IdP-initiated sign-in). It is
 * not trusted; the browser is sent through a normal SP-initiated sign-in,
 * which completes silently while the IdP session exists.
 */
final class SignInMustRestart extends RuntimeException {}

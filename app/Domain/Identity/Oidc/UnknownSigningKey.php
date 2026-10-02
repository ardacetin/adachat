<?php

namespace App\Domain\Identity\Oidc;

/**
 * No key in the cached key set matches the token: the identity provider may
 * have rotated its keys, so the set is fetched again once.
 */
final class UnknownSigningKey extends InvalidIdToken
{
    public function __construct()
    {
        parent::__construct('kid');
    }
}

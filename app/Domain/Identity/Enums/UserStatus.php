<?php

namespace App\Domain\Identity\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}

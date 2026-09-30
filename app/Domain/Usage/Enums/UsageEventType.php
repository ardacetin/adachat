<?php

namespace App\Domain\Usage\Enums;

enum UsageEventType: string
{
    /** Cost of a generation request. */
    case Charge = 'charge';
    /** Manual correction by a super admin; may be negative. */
    case Adjustment = 'adjustment';
}

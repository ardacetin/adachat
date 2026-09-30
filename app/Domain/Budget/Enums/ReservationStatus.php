<?php

namespace App\Domain\Budget\Enums;

/**
 * active → settled | released | expired; expired → settled (late settlement).
 */
enum ReservationStatus: string
{
    case Active = 'active';
    case Settled = 'settled';
    case Released = 'released';
    case Expired = 'expired';
}

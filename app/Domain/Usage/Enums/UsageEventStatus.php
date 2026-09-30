<?php

namespace App\Domain\Usage\Enums;

enum UsageEventStatus: string
{
    case Completed = 'completed';
    /** Stopped by the user or cut off; usage up to that point. */
    case Partial = 'partial';
    /** Ended with a provider error after tokens were consumed. */
    case Failed = 'failed';
}

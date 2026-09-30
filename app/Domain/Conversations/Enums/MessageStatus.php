<?php

namespace App\Domain\Conversations\Enums;

enum MessageStatus: string
{
    case Completed = 'completed';
    /** Assistant message being generated; content is flushed periodically. */
    case Streaming = 'streaming';
    case Failed = 'failed';
    /** Stopped by the user; content is what was generated until then. */
    case Cancelled = 'cancelled';
}

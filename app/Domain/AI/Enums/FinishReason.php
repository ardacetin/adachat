<?php

namespace App\Domain\AI\Enums;

enum FinishReason: string
{
    case Stop = 'stop';
    /** The output reached max_output_tokens. */
    case Length = 'length';
    case ContentFilter = 'content_filter';
    case Cancelled = 'cancelled';
    case Error = 'error';
}

<?php

namespace App\Domain\AI\Enums;

enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}

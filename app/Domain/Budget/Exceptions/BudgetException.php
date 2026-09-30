<?php

namespace App\Domain\Budget\Exceptions;

use RuntimeException;

/**
 * A request refused by the budget engine. Nothing was reserved.
 */
abstract class BudgetException extends RuntimeException
{
    /**
     * Stable, translatable error code (chat.errors.<code>).
     */
    abstract public function code(): string;
}

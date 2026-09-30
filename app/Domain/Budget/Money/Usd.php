<?php

namespace App\Domain\Budget\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use JsonSerializable;
use Stringable;

/**
 * An immutable US dollar amount with 10 decimals, matching the
 * DECIMAL(20,10) columns. Money is never a float: arithmetic is exact and
 * anything finer than 10 decimals is rounded up (never under-charge).
 */
final readonly class Usd implements JsonSerializable, Stringable
{
    public const SCALE = 10;

    private function __construct(public BigDecimal $amount) {}

    /**
     * @param  BigNumber|int|string  $amount  decimal string, integer or brick number
     */
    public static function of(BigNumber|int|string $amount): self
    {
        return new self(BigDecimal::of($amount)->toScale(self::SCALE, RoundingMode::Up));
    }

    public static function zero(): self
    {
        return self::of(0);
    }

    public function plus(self $other): self
    {
        return new self($this->amount->plus($other->amount));
    }

    public function minus(self $other): self
    {
        return new self($this->amount->minus($other->amount));
    }

    public function negated(): self
    {
        return new self($this->amount->negated());
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->amount->isGreaterThan($other->amount);
    }

    public function isLessThan(self $other): bool
    {
        return $this->amount->isLessThan($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->amount->isEqualTo($other->amount);
    }

    public function max(self $other): self
    {
        return $this->isLessThan($other) ? $other : $this;
    }

    /**
     * The decimal string stored in the database, e.g. "0.0000125000".
     */
    public function toString(): string
    {
        return (string) $this->amount;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }
}

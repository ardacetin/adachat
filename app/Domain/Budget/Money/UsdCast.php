<?php

namespace App\Domain\Budget\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts DECIMAL(20,10) columns to Usd. PDO returns decimals as strings, so
 * no float is ever involved.
 *
 * Setting accepts Usd, decimal strings and integers; anything else (a float)
 * is refused at runtime, hence the mixed set type.
 *
 * @implements CastsAttributes<Usd, mixed>
 */
final class UsdCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Usd
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("{$key} must be a decimal string.");
        }

        return Usd::of($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof Usd => $value->toString(),
            is_string($value), is_int($value) => Usd::of($value)->toString(),
            default => throw new InvalidArgumentException("{$key} must be a Usd amount, not a float."),
        };
    }
}

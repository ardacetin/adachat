<?php

namespace App\Rules;

use App\Domain\Institution\Theme\ThemeTokens;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A #rrggbb colour with at least 3:1 contrast against the light background,
 * so buttons and focus rings in the institution colour stay visible.
 */
class UsablePrimaryColor implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
            $fail('validation.hex_color')->translate();

            return;
        }

        if (! ThemeTokens::isUsable($value)) {
            $fail('admin.primary_color_contrast')->translate();
        }
    }
}

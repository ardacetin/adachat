<?php

namespace App\Http\Requests\Admin;

use App\Domain\Institution\Services\ContentTexts;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateContentSettingsRequest extends FormRequest
{
    /** Fields that are a line, not a paragraph. */
    private const SHORT = ['title', 'sign_in_hint', 'models_title', 'budget_title', 'privacy_title', 'footer', 'subject', 'heading', 'sign_in', 'button'];

    public function authorize(): bool
    {
        return $this->user()?->can('manage-system') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = ['landing' => ['nullable', 'array'], 'invitation' => ['nullable', 'array']];

        foreach ((array) config('ada.locales.available') as $locale) {
            foreach (['landing' => ContentTexts::LANDING, 'invitation' => ContentTexts::INVITATION] as $kind => $fields) {
                foreach ($fields as $field) {
                    $rules["{$kind}.{$locale}.{$field}"] = ['nullable', 'string', 'max:'.(in_array($field, self::SHORT, true) ? 200 : 2000)];
                }
            }
        }

        return $rules;
    }

    /**
     * The texts given for one kind, trimmed, without the empty ones (those
     * keep the default) and without locales left empty altogether.
     *
     * @param  list<string>  $fields
     * @return array<string, array<string, string>> locale → field → text
     */
    public function texts(string $kind, array $fields): array
    {
        $out = [];

        foreach ((array) config('ada.locales.available') as $locale) {
            foreach ($fields as $field) {
                $value = $this->validated("{$kind}.{$locale}.{$field}");

                if (is_string($value) && trim($value) !== '') {
                    $out[$locale][$field] = trim($value);
                }
            }
        }

        return $out;
    }
}

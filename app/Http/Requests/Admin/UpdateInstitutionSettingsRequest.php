<?php

namespace App\Http\Requests\Admin;

use App\Rules\UsablePrimaryColor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInstitutionSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-system') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $domain = $this->input('domain');
        $color = $this->input('primary_color');

        $this->merge([
            'domain' => is_string($domain) ? mb_strtolower(trim($domain)) : $domain,
            'primary_color' => is_string($color) ? mb_strtolower(trim($color)) : $color,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $image = ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:50'],
            'domain' => ['nullable', 'string', 'max:255', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'default_locale' => ['required', Rule::in(config('ada.locales.available'))],
            'timezone' => ['required', 'timezone:all'],
            'privacy_url' => ['nullable', 'url:https,http', 'max:2048'],
            'terms_url' => ['nullable', 'url:https,http', 'max:2048'],
            'primary_color' => ['nullable', new UsablePrimaryColor],
            'budget_display' => ['required', Rule::in(['amount', 'percent'])],
            'logo' => $image,
            'logo_dark' => $image,
            'favicon' => ['nullable', 'image', 'mimes:png', 'max:256', 'dimensions:min_width=16,max_width=512,ratio=1'],
            'remove_logo' => ['boolean'],
            'remove_logo_dark' => ['boolean'],
            'remove_favicon' => ['boolean'],
        ];
    }
}

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
        $cap = $this->input('monthly_cap_usd');
        $emails = $this->input('notification_emails');

        $this->merge([
            'domain' => is_string($domain) ? mb_strtolower(trim($domain)) : $domain,
            'primary_color' => is_string($color) ? mb_strtolower(trim($color)) : $color,
            'monthly_cap_usd' => is_string($cap) && trim($cap) !== '' ? str_replace(',', '.', trim($cap)) : null,
        ]);

        // One address per line (commas and semicolons work too).
        if (is_string($emails)) {
            $this->merge(['notification_emails' => array_values(array_unique(array_filter(array_map(
                fn (string $email) => mb_strtolower(trim($email)),
                preg_split('/[\s,;]+/', $emails) ?: [],
            ))))]);
        }
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
            // Whole dollars and cents only; a decimal string, never a float.
            'monthly_cap_usd' => ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'notification_emails' => ['nullable', 'array', 'max:20'],
            'notification_emails.*' => ['string', 'email:rfc', 'max:255'],
            'user_budget_emails' => ['sometimes', 'boolean'],
            'logo' => $image,
            'logo_dark' => $image,
            'favicon' => ['nullable', 'image', 'mimes:png', 'max:256', 'dimensions:min_width=16,max_width=512,ratio=1'],
            'remove_logo' => ['boolean'],
            'remove_logo_dark' => ['boolean'],
            'remove_favicon' => ['boolean'],
        ];
    }
}

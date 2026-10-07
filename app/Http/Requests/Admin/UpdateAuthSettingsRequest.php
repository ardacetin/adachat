<?php

namespace App\Http\Requests\Admin;

use App\Domain\Identity\Services\GroupMapping;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAuthSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-system') ?? false;
    }

    /**
     * Accept one domain per line and normalise to lower case.
     */
    protected function prepareForValidation(): void
    {
        $raw = $this->input('allowed_domains');

        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }

        $domains = is_array($raw)
            ? array_values(array_unique(array_filter(array_map(
                static fn (mixed $domain): string => mb_strtolower(trim((string) $domain)),
                $raw,
            ))))
            : $raw;

        $this->merge(['allowed_domains' => $domains]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Removing every domain would lock everybody out.
            'allowed_domains' => ['required', 'array', 'min:1', 'max:20'],
            'allowed_domains.*' => ['string', 'max:255', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/'],
            'auto_provision' => ['required', 'boolean'],
            'group_mapping' => ['sometimes', 'boolean'],
            'group_mapping_unmatched' => ['sometimes', Rule::in(GroupMapping::UNMATCHED)],
        ];
    }
}

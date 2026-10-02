<?php

namespace App\Http\Requests\Admin;

use App\Models\Assistant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-system') ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Empty starter fields from the form are dropped.
        $this->merge([
            'starter_prompts' => array_values(array_filter(
                array_map(fn (mixed $prompt) => is_string($prompt) ? trim($prompt) : $prompt, (array) $this->input('starter_prompts', [])),
                fn (mixed $prompt) => $prompt !== '' && $prompt !== null,
            )),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Assistant|null $assistant */
        $assistant = $this->route('assistant');
        $rules = [
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('assistants', 'slug')->ignore($assistant?->id)],
            'name' => ['required', 'array'],
            'description' => ['nullable', 'array'],
            'instructions' => ['required', 'string', 'max:20000'],
            'model_alias_id' => ['required', 'integer', 'exists:model_aliases,id'],
            'starter_prompts' => ['array', 'max:4'],
            'starter_prompts.*' => ['string', 'max:200'],
            'icon' => ['required', Rule::in(Assistant::ICONS)],
            'sort_order' => ['required', 'integer', 'min:-1000', 'max:1000'],
            'enabled' => ['required', 'boolean'],
            'group_ids' => ['array'],
            'group_ids.*' => ['integer', 'distinct', 'exists:groups,id'],
        ];

        foreach (config('ada.locales.available') as $locale) {
            $rules["name.{$locale}"] = ['required', 'string', 'max:60'];
            $rules["description.{$locale}"] = ['nullable', 'string', 'max:200'];
        }

        return $rules;
    }
}

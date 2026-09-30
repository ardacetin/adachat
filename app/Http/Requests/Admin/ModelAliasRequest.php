<?php

namespace App\Http\Requests\Admin;

use App\Models\AiModel;
use App\Models\ModelAlias;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModelAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-system') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string|Closure>
     */
    public function rules(): array
    {
        /** @var ModelAlias|null $alias */
        $alias = $this->route('alias');
        $locales = config('ada.locales.available');

        $rules = [
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('model_aliases', 'slug')->ignore($alias?->id)],
            'name' => ['required', 'array'],
            'description' => ['nullable', 'array'],
            'ai_model_id' => ['required', 'integer', 'exists:ai_models,id'],
            'max_output_tokens' => ['nullable', 'integer', 'min:1', $this->withinModelMaximum(...)],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2', 'decimal:0,2'],
            'system_prompt' => ['nullable', 'string', 'max:4000'],
            'show_model_details' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:-1000', 'max:1000'],
            'enabled' => ['required', 'boolean'],
            // Groups whose members may use the alias.
            'group_ids' => ['sometimes', 'array'],
            'group_ids.*' => ['integer', 'distinct', 'exists:groups,id'],
        ];

        foreach ($locales as $locale) {
            $rules["name.{$locale}"] = ['required', 'string', 'max:60'];
            $rules["description.{$locale}"] = ['nullable', 'string', 'max:160'];
        }

        return $rules;
    }

    private function withinModelMaximum(string $attribute, mixed $value, Closure $fail): void
    {
        $maximum = AiModel::query()->whereKey($this->integer('ai_model_id'))->value('max_output_tokens');

        if (is_numeric($maximum) && (int) $value > (int) $maximum) {
            $fail(__('admin.alias_max_tokens', ['max' => $maximum]));
        }
    }
}

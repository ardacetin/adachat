<?php

namespace App\Http\Requests\Admin;

use App\Models\AiModel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiModelRequest extends FormRequest
{
    private const PRICE = ['numeric', 'min:0', 'max:99999999', 'decimal:0,6'];

    public function authorize(): bool
    {
        return $this->user()?->can('manage-system') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var AiModel|null $model */
        $model = $this->route('model');

        return [
            'provider_id' => ['required', 'integer', 'exists:providers,id'],
            'provider_model_id' => [
                'required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:\/-]+$/',
                Rule::unique('ai_models')->where('provider_id', $this->integer('provider_id'))->ignore($model?->id),
            ],
            'display_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'input_price_per_million' => ['required', ...self::PRICE],
            'output_price_per_million' => ['required', ...self::PRICE],
            'cached_input_price_per_million' => ['nullable', ...self::PRICE],
            'cache_write_price_per_million' => ['nullable', ...self::PRICE],
            'context_window' => ['required', 'integer', 'min:1', 'max:10000000'],
            'max_output_tokens' => ['required', 'integer', 'min:1', 'lte:context_window'],
            'supports_vision' => ['required', 'boolean'],
            'supports_files' => ['required', 'boolean'],
            'supports_tools' => ['required', 'boolean'],
            'supports_reasoning' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
        ];
    }
}

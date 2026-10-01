<?php

namespace App\Http\Requests\Admin;

use App\Domain\AI\Enums\ProviderDriver;
use App\Models\Provider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-system') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Provider|null $provider */
        $provider = $this->route('provider');
        // OpenAI-compatible endpoints have no default address.
        $driver = $provider?->driver ?? ProviderDriver::tryFrom((string) $this->input('driver'));
        $needsUrl = $driver === ProviderDriver::OpenAICompatible;

        return [
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('providers', 'slug')->ignore($provider?->id)],
            // The driver decides the API dialect; it cannot change once models exist.
            'driver' => [$provider === null ? 'required' : 'prohibited', Rule::enum(ProviderDriver::class)],
            'name' => ['required', 'string', 'max:255'],
            'base_url' => [$needsUrl ? 'required' : 'nullable', 'url:https,http', 'max:2048'],
            'enabled' => ['required', 'boolean'],
            // Write-only: never sent back to the browser.
            'api_key' => ['nullable', 'string', 'min:8', 'max:512'],
        ];
    }
}

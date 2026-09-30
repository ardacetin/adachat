<?php

namespace App\Http\Requests\Admin;

use App\Models\Group;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Group|null $group */
        $group = $this->route('group');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('groups', 'name')->ignore($group?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'budget_policy_id' => ['required', 'integer', 'exists:budget_policies,id'],
            'requests_per_minute' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_concurrent_streams' => ['required', 'integer', 'min:1', 'max:20'],
            // Model aliases the members may use.
            'alias_ids' => ['present', 'array'],
            'alias_ids.*' => ['integer', 'distinct', 'exists:model_aliases,id'],
            'apply_to_current_period' => ['boolean'],
        ];
    }
}

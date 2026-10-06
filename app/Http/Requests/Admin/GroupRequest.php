<?php

namespace App\Http\Requests\Admin;

use App\Domain\Identity\Enums\UserRole;
use App\Models\Group;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        /** @var Group|null $group */
        $group = $this->route('group');

        return $actor instanceof User && $actor->can('access-admin')
            && ($group === null || ! self::reservedFor($actor, $group));
    }

    /**
     * Administrators do not change their own group or super administrators'
     * limits (UserPolicy::update); a group's policy, aliases and rate limits
     * are those limits for its members.
     */
    public static function reservedFor(User $actor, Group $group): bool
    {
        return ! $actor->can('manage-system') && $group->users()
            ->where(fn ($members) => $members
                ->whereKey($actor->getKey())
                ->orWhere('role', UserRole::SuperAdmin->value))
            ->exists();
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

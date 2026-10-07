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
     * Administrators change only users' limits (UserPolicy::update), not
     * their own or another administrator's: a group's policy, aliases and
     * rate limits are those limits for its members.
     */
    public static function reservedFor(User $actor, Group $group): bool
    {
        return ! $actor->can('manage-system') && $group->users()
            ->where('role', '!=', UserRole::User->value)
            ->exists();
    }

    /**
     * Identity provider groups: one value per line.
     */
    protected function prepareForValidation(): void
    {
        $raw = $this->input('idp_groups');

        // An emptied list arrives as null (ConvertEmptyStringsToNull).
        if ($raw === null && $this->has('idp_groups')) {
            $raw = [];
        }

        if (is_string($raw)) {
            $raw = preg_split('/\R/', $raw) ?: [];
        }

        if (is_array($raw)) {
            $values = [];

            foreach ($raw as $value) {
                $value = trim((string) $value);

                if ($value !== '') {
                    $values[mb_strtolower($value)] ??= $value;
                }
            }

            $this->merge(['idp_groups' => array_values($values)]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Group|null $group */
        $group = $this->route('group');
        $taken = Group::query()
            ->whereNotNull('idp_groups')
            ->when($group !== null, fn ($query) => $query->whereKeyNot($group->id))
            ->get()
            ->flatMap(fn (Group $other) => array_map(mb_strtolower(...), $other->idp_groups ?? []))
            ->all();

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
            // A value belongs to one group, so the priority only decides
            // between groups a person is in at the identity provider.
            'idp_groups' => ['sometimes', 'array', 'max:50'],
            'idp_groups.*' => ['string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) use ($taken): void {
                if (in_array(mb_strtolower((string) $value), $taken, true)) {
                    $fail(__('admin.idp_group_taken', ['value' => $value]));
                }
            }],
            'idp_priority' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ];
    }
}

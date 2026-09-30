<?php

namespace App\Http\Requests\Admin;

use App\Models\BudgetPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BudgetPolicyRequest extends FormRequest
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
        /** @var BudgetPolicy|null $policy */
        $policy = $this->route('budget_policy');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('budget_policies', 'name')->ignore($policy?->id)],
            // $0 blocks usage; there is no unlimited policy.
            'monthly_limit_usd' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100000'],
            'apply_to_current_period' => ['boolean'],
        ];
    }
}

<?php

use App\Domain\AI\Data\InputTokenCount;
use App\Domain\AI\Data\TokenUsage;
use App\Domain\AI\Enums\InputCountMethod;
use App\Domain\Budget\Data\Settlement;
use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Services\BudgetEngine;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Models\AiModel;
use App\Models\BudgetPeriod;
use App\Models\BudgetPolicy;
use App\Models\Group;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    updateSettings(InstitutionSettings::class, ['timezone' => 'Europe/Istanbul']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();

    // $1 per million input tokens, $10 per million output tokens.
    $this->gpt = AiModel::factory()->create(['display_name' => 'GPT Test', 'input_price_per_million' => '1', 'output_price_per_million' => '10']);
    $this->claude = AiModel::factory()->create(['display_name' => 'Claude Test', 'input_price_per_million' => '1', 'output_price_per_million' => '10']);

    $policy = BudgetPolicy::factory()->create(['monthly_limit_usd' => '100']);
    $this->staff = Group::factory()->create(['name' => 'Staff', 'budget_policy_id' => $policy->id]);
    $this->students = Group::factory()->create(['name' => 'Students', 'budget_policy_id' => $policy->id]);
    $this->ada = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.edu', 'group_id' => $this->staff->id]);
    $this->bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.edu', 'group_id' => $this->students->id]);
});

/**
 * One request: $0.01 per 10 000 input tokens and $0.01 per 1 000 output tokens.
 * Billing more input than was counted makes an overshoot.
 */
function spend(User $user, AiModel $model, int $input = 10000, int $output = 1000, ?int $billedInput = null): void
{
    $engine = app(BudgetEngine::class);
    $reservation = $engine->reserve($user, $model, new InputTokenCount($input, InputCountMethod::ProviderEndpoint, 0.0), max(1, $output));
    $engine->settle($reservation, new Settlement(new TokenUsage(input: $billedInput ?? $input, output: $output)));
}

test('reports are for administrators', function (string $route) {
    $this->actingAs(User::factory()->create())->get(route($route))->assertForbidden();
    $this->actingAs($this->admin)->get(route($route))->assertOk();
})->with(['admin.index', 'admin.reports.index']);

test('totals and breakdowns cover the chosen range and filters', function () {
    spend($this->ada, $this->gpt);                  // $0.02
    spend($this->ada, $this->claude, output: 4000); // $0.05
    spend($this->bob, $this->gpt);                  // $0.02
    app(BudgetEngine::class)->adjust($this->bob, Usd::of('-0.01'), 'Refund', $this->admin);

    $this->actingAs($this->admin)->get(route('admin.reports.index', ['by' => 'model']))
        ->assertInertia(fn ($page) => $page
            ->component('admin/reports/index')
            ->where('filters.from', '2026-10-01')
            ->where('filters.to', '2026-10-15')
            ->where('totals.requests', 3)
            ->where('totals.users', 2)
            ->where('totals.cost_usd', '0.09')
            ->where('totals.input_tokens', 30000)
            ->where('totals.output_tokens', 6000)
            ->where('totals.adjustments_usd', '-0.01')
            ->where('breakdown.0.label', 'Claude Test')
            ->where('breakdown.0.cost_usd', '0.05')
            ->where('breakdown.1.label', 'GPT Test')
            ->where('breakdown.1.requests', 2));

    $this->actingAs($this->admin)->get(route('admin.reports.index', ['by' => 'group']))
        ->assertInertia(fn ($page) => $page
            ->where('breakdown.0.label', 'Staff')
            ->where('breakdown.0.cost_usd', '0.07')
            ->where('breakdown.1.label', 'Students'));

    $this->actingAs($this->admin)->get(route('admin.reports.index', ['by' => 'user', 'group_id' => $this->students->id]))
        ->assertInertia(fn ($page) => $page
            ->where('totals.requests', 1)
            ->where('breakdown', [[
                'id' => $this->bob->id, 'label' => 'Bob', 'detail' => 'bob@example.edu',
                'requests' => 1, 'input_tokens' => 10000, 'output_tokens' => 1000, 'web_searches' => 0, 'cost_usd' => '0.02',
            ]]));

    $this->actingAs($this->admin)->get(route('admin.reports.index', ['user_id' => $this->ada->id, 'ai_model_id' => $this->gpt->id]))
        ->assertInertia(fn ($page) => $page->where('totals.cost_usd', '0.02')->where('options.user.email', 'ada@example.edu'));

    $this->actingAs($this->admin)->get(route('admin.reports.index', ['by' => 'provider']))
        ->assertInertia(fn ($page) => $page->has('breakdown', 2));
});

test('days are the institution\'s days and empty days are shown', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 20:30:00', 'UTC')); // 2 Oct 23:30 in Istanbul
    spend($this->ada, $this->gpt);
    $this->travelTo(CarbonImmutable::parse('2026-10-02 21:30:00', 'UTC')); // 3 Oct 00:30 in Istanbul
    spend($this->ada, $this->gpt);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));

    $this->actingAs($this->admin)->get(route('admin.reports.index', ['from' => '2026-10-01', 'to' => '2026-10-04']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.interval', 'day')
            ->where('timeline', [
                ['date' => '2026-10-01', 'requests' => 0, 'cost_usd' => '0.00'],
                ['date' => '2026-10-02', 'requests' => 1, 'cost_usd' => '0.02'],
                ['date' => '2026-10-03', 'requests' => 1, 'cost_usd' => '0.02'],
                ['date' => '2026-10-04', 'requests' => 0, 'cost_usd' => '0.00'],
            ]));
});

test('long ranges are shown per month', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-10 12:00:00', 'UTC'));
    spend($this->ada, $this->gpt);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    spend($this->ada, $this->gpt);

    $this->actingAs($this->admin)->get(route('admin.reports.index', ['from' => '2026-07-01', 'to' => '2026-10-31']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.interval', 'month')
            ->where('timeline', [
                ['date' => '2026-07', 'requests' => 0, 'cost_usd' => '0.00'],
                ['date' => '2026-08', 'requests' => 1, 'cost_usd' => '0.02'],
                ['date' => '2026-09', 'requests' => 0, 'cost_usd' => '0.00'],
                ['date' => '2026-10', 'requests' => 1, 'cost_usd' => '0.02'],
            ]));
});

test('overshoots and counter deviation are reported', function () {
    spend($this->ada, $this->gpt);                              // exact: 0 %
    spend($this->ada, $this->gpt, input: 1000, output: 1, billedInput: 2000); // billed twice what was counted

    $this->actingAs($this->admin)->get(route('admin.reports.index'))
        ->assertInertia(fn ($page) => $page
            ->where('overshoots.count', 1)
            ->where('overshoots.latest.0.user', 'ada@example.edu')
            ->where('overshoots.latest.0.reserved_input_tokens', 1000)
            ->where('overshoots.latest.0.billed_input_tokens', 2000)
            ->where('deviation.0.model', 'GPT Test')
            ->where('deviation.0.requests', 2)
            ->where('deviation.0.billed_input_tokens', 12000)
            ->where('deviation.0.reserved_input_tokens', 11000)
            ->where('deviation.0.deviation_percent', 9.1)
            ->where('deviation.0.worst_percent', 100)
            ->where('deviation.0.above_count', 1));
});

test('the dashboard compares this month with the last one', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'));
    spend($this->ada, $this->gpt);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    spend($this->ada, $this->gpt);
    spend($this->bob, $this->claude, output: 3000);
    BudgetPeriod::query()->where('user_id', $this->bob->id)->update(['limit_usd' => '0.04']);

    $this->actingAs($this->admin)->get(route('admin.index'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/index')
            ->where('kpis.month', '2026-10')
            ->where('kpis.cost_usd', '0.06')
            ->where('kpis.previous_cost_usd', '0.02')
            ->where('kpis.requests', 2)
            ->where('kpis.active_users', 2)
            ->where('kpis.previous_active_users', 1)
            ->where('kpis.average_per_user_usd', '0.03')
            ->where('kpis.users_at_limit', 1)
            ->has('daily', 31)
            ->where('topModels.0.label', 'Claude Test')
            ->where('overshoots', 0));
});

test('report ranges are validated', function (array $query, string $error) {
    $this->actingAs($this->admin)->get(route('admin.reports.index', $query))->assertSessionHasErrors($error);
})->with([
    'backwards' => [['from' => '2026-10-10', 'to' => '2026-10-01'], 'to'],
    'too long' => [['from' => '2025-01-01', 'to' => '2026-10-01'], 'from'],
    'not a date' => [['from' => 'yesterday'], 'from'],
    'unknown dimension' => [['by' => 'secret'], 'by'],
]);

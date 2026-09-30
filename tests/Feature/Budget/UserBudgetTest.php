<?php

use App\Domain\AI\Data\InputTokenCount;
use App\Domain\AI\Data\TokenUsage;
use App\Domain\AI\Enums\InputCountMethod;
use App\Domain\Budget\Data\Settlement;
use App\Domain\Budget\Services\BudgetEngine;
use App\Domain\Budget\Services\BudgetSummary;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Models\AiModel;
use App\Models\AuditLog;
use App\Models\BudgetPeriod;
use App\Models\BudgetPolicy;
use App\Models\Group;
use App\Models\ModelAlias;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * The user's own view of the budget: the shared "budget" prop (sidebar,
 * budget-exhausted state), the usage page and ada:user:budget.
 */

beforeEach(function () {
    updateSettings(InstitutionSettings::class, ['timezone' => 'Europe/Istanbul', 'budget_display' => 'amount']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));

    // $1 per million input tokens, $10 per million output tokens.
    $this->model = AiModel::factory()->create(['input_price_per_million' => '1', 'output_price_per_million' => '10']);
    $this->alias = ModelAlias::factory()->create(['ai_model_id' => $this->model->id, 'name' => ['en' => 'Smart', 'tr' => 'Akıllı']]);
});

function limitedUser(string $limit = '10'): User
{
    $policy = BudgetPolicy::factory()->create(['monthly_limit_usd' => $limit]);

    return User::factory()->create(['group_id' => Group::factory()->create(['budget_policy_id' => $policy->id])->id]);
}

/** 10 000 input tokens ($0.01) plus the given output tokens ($10 per million). */
function charge(User $user, int $outputTokens, ?int $aliasId = null): void
{
    $engine = app(BudgetEngine::class);
    $reservation = $engine->reserve($user, test()->model, new InputTokenCount(10000, InputCountMethod::ProviderEndpoint, 0.0), max(1, $outputTokens));
    $engine->settle($reservation, new Settlement(new TokenUsage(input: 10000, output: $outputTokens), modelAliasId: $aliasId));
}

test('the budget summary shows amounts without creating a period', function () {
    $user = limitedUser('10');

    expect(app(BudgetSummary::class)->for($user))->toBe([
        'display' => 'amount',
        'percent_used' => 0,
        'exhausted' => false,
        'period_start' => '2026-10-01',
        'resets_on' => '2026-11-01',
        'limit_usd' => '10.00',
        'spent_usd' => '0.00',
        'remaining_usd' => '10.00',
    ])->and(BudgetPeriod::query()->count())->toBe(0);
});

test('the summary reflects spending and marks an exhausted budget', function () {
    $user = limitedUser('0.05');
    charge($user, 1000); // $0.01 input + $0.01 output

    $summary = app(BudgetSummary::class)->for($user);
    expect($summary['spent_usd'])->toBe('0.02')
        ->and($summary['remaining_usd'])->toBe('0.03')
        ->and($summary['percent_used'])->toBe(40)
        ->and($summary['exhausted'])->toBeFalse();

    $user->forceFill(['monthly_limit_override_usd' => '0'])->save();
    BudgetPeriod::query()->update(['limit_usd' => '0']);

    expect(app(BudgetSummary::class)->for($user->refresh()))
        ->toMatchArray(['exhausted' => true, 'percent_used' => 100, 'remaining_usd' => '0.00']);
});

test('with the percent display no dollar amounts reach the browser', function () {
    updateSettings(InstitutionSettings::class, ['budget_display' => 'percent']);
    $user = limitedUser('0.05');
    charge($user, 1000, $this->alias->id);

    $this->actingAs($user)->get(route('home'))->assertInertia(fn ($page) => $page
        ->where('budget.display', 'percent')
        ->where('budget.percent_used', 40)
        ->missing('budget.limit_usd')
        ->missing('budget.spent_usd')
        ->missing('budget.remaining_usd'));

    $response = $this->actingAs($user)->get(route('usage'))->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('usage')
        ->where('by_model.0.percent_of_limit', 40)
        ->missing('by_model.0.cost_usd')
        ->missing('by_day.0.cost_usd'));

    expect($response->getContent())->not->toContain('0.02');
});

test('every page shares the user\'s budget', function () {
    $user = limitedUser('10');

    $this->actingAs($user)->get(route('home'))
        ->assertInertia(fn ($page) => $page->where('budget.limit_usd', '10.00')->where('budget.exhausted', false));
});

test('the usage page breaks this month down by model and day', function () {
    $user = limitedUser('10');
    charge($user, 1000, $this->alias->id);   // $0.02
    charge($user, 2000, $this->alias->id);   // $0.03
    $this->travelTo(CarbonImmutable::parse('2026-10-16 22:30:00', 'UTC')); // 17 Oct in Istanbul
    charge($user, 0);                          // $0.01, no alias

    $this->actingAs($user)->get(route('usage'))->assertInertia(fn ($page) => $page
        ->component('usage')
        ->where('summary.spent_usd', '0.06')
        ->where('by_model', [
            ['alias' => 'Smart', 'requests' => 2, 'input_tokens' => 20000, 'output_tokens' => 3000, 'cost_usd' => '0.05'],
            ['alias' => null, 'requests' => 1, 'input_tokens' => 10000, 'output_tokens' => 0, 'cost_usd' => '0.01'],
        ])
        ->where('by_day', [
            ['date' => '2026-10-15', 'requests' => 2, 'cost_usd' => '0.05'],
            ['date' => '2026-10-17', 'requests' => 1, 'cost_usd' => '0.01'],
        ])
        ->where('months', []));
});

test('previous months are listed and other users\' usage is not', function () {
    $user = limitedUser('10');
    $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00:00', 'UTC'));
    charge($user, 1000);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    charge(limitedUser('10'), 1000);

    $this->actingAs($user)->get(route('usage'))->assertInertia(fn ($page) => $page
        ->where('by_model', [])
        ->where('months', [['month' => '2026-09', 'percent_used' => 1, 'spent_usd' => '0.02', 'limit_usd' => '10.00']]));
});

test('the usage page needs a signed-in user', function () {
    $this->get(route('usage'))->assertRedirect(route('login'));
});

test('ada:user:budget sets an individual limit for the current period', function () {
    $user = limitedUser('10');
    charge($user, 1000);

    $this->artisan('ada:user:budget', ['email' => $user->email, '--limit' => '25'])
        ->expectsOutputToContain('$25.00')
        ->assertSuccessful();

    expect($user->refresh()->monthly_limit_override_usd?->toString())->toBe('25.0000000000')
        ->and(BudgetPeriod::query()->sole()->limit_usd->toString())->toBe('25.0000000000')
        ->and(AuditLog::query()->where('action', 'user.budget_override_changed')->sole()->new_values['monthly_limit_override_usd'])->toBe('25.0000000000');

    $this->artisan('ada:user:budget', ['email' => $user->email, '--clear' => true])->assertSuccessful();

    expect($user->refresh()->monthly_limit_override_usd)->toBeNull()
        ->and(BudgetPeriod::query()->sole()->limit_usd->toString())->toBe('10.0000000000');
});

test('ada:user:budget can leave the current period alone', function () {
    $user = limitedUser('10');
    charge($user, 1000);

    $this->artisan('ada:user:budget', ['email' => $user->email, '--limit' => '0', '--next-period' => true])->assertSuccessful();

    expect(BudgetPeriod::query()->sole()->limit_usd->toString())->toBe('10.0000000000');
});

test('ada:user:budget validates its input', function (array $arguments) {
    $user = limitedUser('10');

    $this->artisan('ada:user:budget', ['email' => $user->email, ...$arguments])->assertFailed();
})->with([
    'nothing' => [[]],
    'both' => [['--limit' => '5', '--clear' => true]],
    'negative' => [['--limit' => '-1']],
    'too precise' => [['--limit' => '1.005']],
    'not a number' => [['--limit' => 'lots']],
]);

test('ada:user:budget needs an existing user', function () {
    $this->artisan('ada:user:budget', ['email' => 'nobody@example.edu', '--limit' => '5'])->assertFailed();
});

<?php

use App\Domain\AI\Data\InputTokenCount;
use App\Domain\AI\Data\TokenUsage;
use App\Domain\AI\Enums\InputCountMethod;
use App\Domain\Budget\Data\Settlement;
use App\Domain\Budget\Exceptions\BudgetExhausted;
use App\Domain\Budget\Exceptions\InstitutionBudgetExhausted;
use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Services\BudgetEngine;
use App\Domain\Budget\Services\Reconciler;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Models\AiModel;
use App\Models\BudgetPolicy;
use App\Models\Group;
use App\Models\InstitutionPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    updateSettings(InstitutionSettings::class, ['timezone' => 'Europe/Istanbul', 'monthly_cap_usd' => null]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));

    $this->engine = app(BudgetEngine::class);
    // $1 per million input tokens, $10 per million output tokens.
    $this->model = AiModel::factory()->create([
        'input_price_per_million' => '1',
        'output_price_per_million' => '10',
        'max_output_tokens' => 8192,
    ]);
});

function capUser(string $limit = '1'): User
{
    $policy = BudgetPolicy::factory()->create(['monthly_limit_usd' => $limit]);
    $group = Group::factory()->create(['budget_policy_id' => $policy->id, 'max_concurrent_streams' => 5]);

    return User::factory()->create(['group_id' => $group->id]);
}

function capInput(int $tokens): InputTokenCount
{
    return new InputTokenCount($tokens, InputCountMethod::ProviderEndpoint, 0.0);
}

function institutionMonth(): InstitutionPeriod
{
    return InstitutionPeriod::query()->latest('period_start')->firstOrFail();
}

function setCap(?string $cap): void
{
    updateSettings(InstitutionSettings::class, ['monthly_cap_usd' => $cap]);
}

test('the institution month follows every reservation, settlement and release', function () {
    $ada = capUser();
    $bob = capUser();

    $first = $this->engine->reserve($ada, $this->model, capInput(10000), 4000);   // 0.05
    $second = $this->engine->reserve($bob, $this->model, capInput(10000), 4000);  // 0.05

    expect(institutionMonth()->reserved_usd->toString())->toBe('0.1000000000')
        ->and(institutionMonth()->period_start->toIso8601String())->toBe('2026-09-30T21:00:00+00:00');

    $this->engine->settle($first, new Settlement(new TokenUsage(input: 10000, output: 1500)));  // 0.025
    $this->engine->release($second, 'provider_refused');

    $month = institutionMonth();
    expect($month->reserved_usd->toString())->toBe('0.0000000000')
        ->and($month->spent_usd->toString())->toBe('0.0250000000');
});

test('without a cap nothing is limited by the institution', function () {
    $user = capUser('100');

    $this->engine->reserve($user, $this->model, capInput(10000), 8000);

    expect(institutionMonth()->reserved_usd->isPositive())->toBeTrue();
});

test('the cap is shared by all users and refused with its own error', function () {
    setCap('0.06');
    $ada = capUser();
    $bob = capUser();

    $this->engine->reserve($ada, $this->model, capInput(10000), 4000);   // 0.05 of 0.06

    // Bob's own budget is untouched, but only 0.01 is left institution-wide:
    // the output is capped to fit, then refused below the useful minimum.
    $capped = $this->engine->reserve($bob, $this->model, capInput(1000), 4000);
    expect($capped->max_output_tokens)->toBe(900);

    expect(fn () => $this->engine->reserve($bob, $this->model, capInput(1000), 4000))
        ->toThrow(InstitutionBudgetExhausted::class);
});

test('the user limit still applies when it is the smaller one', function () {
    setCap('100');
    $user = capUser('0.001');

    expect(fn () => $this->engine->reserve($user, $this->model, capInput(10000), 4000))
        ->toThrow(BudgetExhausted::class);
});

test('a cap set in the middle of the month counts what was already spent', function () {
    $user = capUser('10');
    $reservation = $this->engine->reserve($user, $this->model, capInput(10000), 4000);
    $this->engine->settle($reservation, new Settlement(new TokenUsage(input: 10000, output: 4000)));  // 0.05

    setCap('0.05');

    expect(fn () => $this->engine->reserve(capUser(), $this->model, capInput(1000), 4000))
        ->toThrow(InstitutionBudgetExhausted::class);
});

test('a new month starts with a fresh institution total', function () {
    setCap('0.06');
    $this->engine->reserve(capUser(), $this->model, capInput(10000), 4000);

    $this->travelTo(CarbonImmutable::parse('2026-11-01 00:00:00', 'Europe/Istanbul'));

    $reservation = $this->engine->reserve(capUser(), $this->model, capInput(10000), 4000);

    expect($reservation->amount_usd->toString())->toBe('0.0500000000')
        ->and(InstitutionPeriod::query()->count())->toBe(2);
});

test('adjustments change the institution total too', function () {
    $user = capUser();
    $admin = User::factory()->superAdmin()->create();

    $this->engine->adjust($user, Usd::of('0.5'), 'Manual charge', $admin);
    expect(institutionMonth()->spent_usd->toString())->toBe('0.5000000000');

    $this->engine->adjust($user, Usd::of('-0.2'), 'Refund', $admin);
    expect(institutionMonth()->spent_usd->toString())->toBe('0.3000000000');
});

test('reconciliation finds and recomputes a wrong institution total', function () {
    $user = capUser();
    $reservation = $this->engine->reserve($user, $this->model, capInput(10000), 4000);
    $this->engine->settle($reservation, new Settlement(new TokenUsage(input: 10000, output: 1500)));

    InstitutionPeriod::query()->update(['spent_usd' => '9', 'reserved_usd' => '1']);

    $reconciler = app(Reconciler::class);
    expect(collect($reconciler->checkInstitution())->pluck('column')->all())->toBe(['institution_spent_usd', 'institution_reserved_usd']);

    $this->artisan('ada:budget:reconcile --fix-reserved')->assertSuccessful();

    expect($reconciler->checkInstitution())->toBe([])
        ->and(institutionMonth()->spent_usd->toString())->toBe('0.0250000000');
});

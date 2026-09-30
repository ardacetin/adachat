<?php

use App\Domain\AI\Data\InputTokenCount;
use App\Domain\AI\Data\TokenUsage;
use App\Domain\AI\Enums\InputCountMethod;
use App\Domain\Budget\Data\Settlement;
use App\Domain\Budget\Enums\ReservationStatus;
use App\Domain\Budget\Exceptions\BudgetExhausted;
use App\Domain\Budget\Exceptions\TooManyConcurrentRequests;
use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Services\BudgetEngine;
use App\Domain\Budget\Services\BudgetPeriods;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Domain\Usage\Enums\UsageEventStatus;
use App\Models\AiModel;
use App\Models\AuditLog;
use App\Models\BudgetPeriod;
use App\Models\BudgetPolicy;
use App\Models\BudgetReservation;
use App\Models\Group;
use App\Models\UsageEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    updateSettings(InstitutionSettings::class, ['timezone' => 'Europe/Istanbul']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));

    $this->engine = app(BudgetEngine::class);
    // $1 per million input tokens, $10 per million output tokens.
    $this->model = AiModel::factory()->create([
        'input_price_per_million' => '1',
        'output_price_per_million' => '10',
        'max_output_tokens' => 8192,
    ]);
});

function budgetUser(string $limit = '1'): User
{
    $policy = BudgetPolicy::factory()->create(['monthly_limit_usd' => $limit]);
    $group = Group::factory()->create(['budget_policy_id' => $policy->id, 'max_concurrent_streams' => 2]);

    return User::factory()->create(['group_id' => $group->id]);
}

function input(int $tokens, float $margin = 0.0, InputCountMethod $method = InputCountMethod::ProviderEndpoint): InputTokenCount
{
    return new InputTokenCount($tokens, $method, $margin);
}

function period(User $user): BudgetPeriod
{
    return BudgetPeriod::query()->where('user_id', $user->id)->latest('period_start')->firstOrFail();
}

test('reserve then settle moves money from reserved to spent', function () {
    $user = budgetUser('1');

    $reservation = $this->engine->reserve($user, $this->model, input(10000), 4000);

    expect($reservation->status)->toBe(ReservationStatus::Active)
        ->and($reservation->amount_usd->toString())->toBe('0.0500000000')
        ->and($reservation->max_output_tokens)->toBe(4000)
        ->and(period($user)->reserved_usd->toString())->toBe('0.0500000000');

    $event = $this->engine->settle($reservation, new Settlement(new TokenUsage(input: 10000, output: 1500), providerRequestId: 'req_1'));

    $period = period($user);
    expect($period->reserved_usd->toString())->toBe('0.0000000000')
        ->and($period->spent_usd->toString())->toBe('0.0250000000')
        ->and($event->total_cost_usd->toString())->toBe('0.0250000000')
        ->and($event->input_price_snapshot)->toBe('1.000000')
        ->and($event->reserved_input_tokens)->toBe(10000)
        ->and($event->group_id)->toBe($user->group_id)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Settled)
        ->and($reservation->settled_amount_usd->toString())->toBe('0.0250000000');
});

test('the period is created lazily with a snapshot of the limit', function () {
    $user = budgetUser('7.5');

    $period = app(BudgetPeriods::class)->current($user);
    app(BudgetPeriods::class)->current($user);

    expect(BudgetPeriod::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($period->limit_usd->toString())->toBe('7.5000000000')
        ->and($period->period_start->toDateTimeString())->toBe('2026-09-30 21:00:00')
        ->and($period->period_end->toDateTimeString())->toBe('2026-10-31 21:00:00');
});

test('a personal override replaces the group policy', function () {
    $user = budgetUser('1');
    $user->forceFill(['monthly_limit_override_usd' => '25'])->save();

    expect(app(BudgetPeriods::class)->current($user)->limit_usd->toString())->toBe('25.0000000000');
});

test('an exhausted budget refuses new requests', function () {
    $user = budgetUser('0.01');
    // 1000 in + 900 out = $0.01 exactly.
    $reservation = $this->engine->reserve($user, $this->model, input(1000), 900);
    $this->engine->settle($reservation, new Settlement(new TokenUsage(input: 1000, output: 900)));

    $this->engine->reserve($user, $this->model, input(10), 1000);
})->throws(BudgetExhausted::class);

test('the output cap shrinks to the remaining budget', function () {
    $user = budgetUser('0.005');

    $reservation = $this->engine->reserve($user, $this->model, input(1000), 8192);

    expect($reservation->max_output_tokens)->toBe(400)
        ->and(period($user)->available()->toString())->toBe('0.0000000000');
});

test('active reservations hold budget from other requests', function () {
    $user = budgetUser('0.02');

    $this->engine->reserve($user, $this->model, input(1000), 1900); // $0.02 held

    $this->engine->reserve($user, $this->model, input(1000), 1000);
})->throws(BudgetExhausted::class);

test('the concurrent request limit is enforced', function () {
    $user = budgetUser('10');

    $this->engine->reserve($user, $this->model, input(100), 500);
    $this->engine->reserve($user, $this->model, input(100), 500);

    expect(fn () => $this->engine->reserve($user, $this->model, input(100), 500))
        ->toThrow(TooManyConcurrentRequests::class);
});

test('a refused request leaves nothing reserved', function () {
    $user = budgetUser('0.001');

    try {
        $this->engine->reserve($user, $this->model, input(5000), 500);
    } catch (BudgetExhausted) {
    }

    expect(BudgetReservation::query()->count())->toBe(0)
        ->and(period($user)->reserved_usd->isZero())->toBeTrue();
});

test('release returns the money and is idempotent', function () {
    $user = budgetUser('1');
    $reservation = $this->engine->reserve($user, $this->model, input(1000), 1000);

    $this->engine->release($reservation, 'provider_error');
    $this->engine->release($reservation, 'provider_error');

    expect(period($user)->reserved_usd->isZero())->toBeTrue()
        ->and(period($user)->spent_usd->isZero())->toBeTrue()
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Released)
        ->and($reservation->status_reason)->toBe('provider_error')
        ->and(UsageEvent::query()->count())->toBe(0)
        ->and(fn () => $this->engine->settle($reservation, new Settlement(new TokenUsage(output: 1))))->toThrow(LogicException::class);
});

test('settlement is idempotent', function () {
    $user = budgetUser('1');
    $reservation = $this->engine->reserve($user, $this->model, input(1000), 1000);
    $settlement = new Settlement(new TokenUsage(input: 1000, output: 500));

    $first = $this->engine->settle($reservation, $settlement);
    $second = $this->engine->settle($reservation->id, $settlement);

    expect($second->id)->toBe($first->id)
        ->and(UsageEvent::query()->count())->toBe(1)
        ->and(period($user)->spent_usd->toString())->toBe('0.0060000000');
});

test('an aborted stream is settled with the usage so far', function () {
    $user = budgetUser('1');
    $reservation = $this->engine->reserve($user, $this->model, input(1000), 4000);

    $event = $this->engine->settle($reservation, new Settlement(
        new TokenUsage(input: 1000, output: 120),
        status: UsageEventStatus::Partial,
        isEstimated: true,
        reason: 'client_abort',
    ));

    expect($event->status)->toBe(UsageEventStatus::Partial)
        ->and($event->is_estimated)->toBeTrue()
        ->and($reservation->refresh()->status_reason)->toBe('client_abort')
        ->and(period($user)->reserved_usd->isZero())->toBeTrue();
});

test('an overshoot is recorded in full, logged and blocks the next request', function () {
    Log::spy();
    $user = budgetUser('0.02');
    $reservation = $this->engine->reserve($user, $this->model, input(1000, 0.5, InputCountMethod::Estimated), 1000);

    // The provider billed far more input than estimated.
    $event = $this->engine->settle($reservation, new Settlement(new TokenUsage(input: 20000, output: 1000)));

    expect($event->total_cost_usd->toString())->toBe('0.0300000000')
        ->and(period($user)->spent_usd->toString())->toBe('0.0300000000')
        ->and(period($user)->available()->isNegative())->toBeTrue();

    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'overshoot'))->once();

    expect(fn () => $this->engine->reserve($user, $this->model, input(1), 300))->toThrow(BudgetExhausted::class);
});

test('a new month starts a fresh period without a rollover job', function () {
    $user = budgetUser('0.01');
    $reservation = $this->engine->reserve($user, $this->model, input(1000), 900);
    $this->engine->settle($reservation, new Settlement(new TokenUsage(input: 1000, output: 900)));

    // 1 November 00:00 Istanbul.
    $this->travelTo(CarbonImmutable::parse('2026-10-31 21:00:00', 'UTC'));

    $next = $this->engine->reserve($user, $this->model, input(1000), 900);

    expect(BudgetPeriod::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and($next->budgetPeriod->period_start->toDateTimeString())->toBe('2026-10-31 21:00:00')
        ->and($next->budgetPeriod->spent_usd->isZero())->toBeTrue();
});

test('a stream crossing midnight is charged to the month it started in', function () {
    $user = budgetUser('1');
    $this->travelTo(CarbonImmutable::parse('2026-10-31 20:59:00', 'UTC'));
    $reservation = $this->engine->reserve($user, $this->model, input(1000), 1000);

    $this->travelTo(CarbonImmutable::parse('2026-10-31 21:01:00', 'UTC'));
    $event = $this->engine->settle($reservation, new Settlement(new TokenUsage(input: 1000, output: 1000)));

    expect($event->budget_period_id)->toBe($reservation->budget_period_id)
        ->and(BudgetPeriod::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('stale reservations expire and a late settlement still charges', function () {
    $user = budgetUser('1');
    $reservation = $this->engine->reserve($user, $this->model, input(1000), 1000);
    $fresh = $this->engine->reserve($user, $this->model, input(1000), 1000);
    $fresh->forceFill(['expires_at' => now()->addHour()])->save();
    $reservation->forceFill(['expires_at' => now()->subSecond()])->save();

    $this->artisan('ada:budget:expire-reservations')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Expired)
        ->and($fresh->refresh()->status)->toBe(ReservationStatus::Active)
        ->and(period($user)->reserved_usd->toString())->toBe($fresh->amount_usd->toString());

    $this->engine->settle($reservation, new Settlement(new TokenUsage(input: 1000, output: 100)));

    expect(period($user)->spent_usd->toString())->toBe('0.0020000000')
        ->and(period($user)->reserved_usd->toString())->toBe($fresh->amount_usd->toString());
});

test('the reservation deadline covers the longest possible stream', function () {
    config(['ada.providers.timeout' => 300, 'ada.budget.reservation_grace_seconds' => 120]);

    $reservation = $this->engine->reserve(budgetUser(), $this->model, input(10), 100);

    expect($reservation->expires_at->toDateTimeString())->toBe('2026-10-15 12:07:00');
});

test('price changes never alter recorded usage', function () {
    $user = budgetUser('1');
    $reservation = $this->engine->reserve($user, $this->model, input(1000), 1000);
    $event = $this->engine->settle($reservation, new Settlement(new TokenUsage(input: 1000, output: 1000)));

    $this->model->forceFill(['input_price_per_million' => '5', 'output_price_per_million' => '50'])->save();

    $event->refresh();
    expect($event->input_price_snapshot)->toBe('1.000000')
        ->and($event->output_price_snapshot)->toBe('10.000000')
        ->and($event->total_cost_usd->toString())->toBe('0.0110000000');
});

test('usage events cannot be updated or deleted', function () {
    $user = budgetUser('1');
    $event = $this->engine->settle(
        $this->engine->reserve($user, $this->model, input(10), 300),
        new Settlement(new TokenUsage(input: 10, output: 10)),
    );

    expect(fn () => $event->forceFill(['output_tokens' => 0])->save())->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});

test('a policy change can be applied to the current period', function () {
    $user = budgetUser('1');
    app(BudgetPeriods::class)->current($user);

    $user->group->budgetPolicy->forceFill(['monthly_limit_usd' => '3'])->save();
    app(BudgetPeriods::class)->applyCurrentLimit($user->fresh());

    expect(period($user)->limit_usd->toString())->toBe('3.0000000000')
        ->and(AuditLog::query()->where('action', 'budget.limit_applied')->sole()->new_values)
        ->toEqual(['limit_usd' => '3.0000000000']);
});

test('past periods keep their limit', function () {
    $user = budgetUser('1');
    app(BudgetPeriods::class)->current($user);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC'));

    $user->group->budgetPolicy->forceFill(['monthly_limit_usd' => '3'])->save();
    app(BudgetPeriods::class)->applyCurrentLimit($user->fresh());

    $limits = BudgetPeriod::query()->where('user_id', $user->id)->orderBy('period_start')->get()
        ->map(fn (BudgetPeriod $period) => $period->limit_usd->toString())->all();

    expect($limits)->toBe(['1.0000000000', '3.0000000000']);
});

test('adjustments charge or credit the current period and are audited', function () {
    $admin = User::factory()->superAdmin()->create();
    $user = budgetUser('1');
    $this->engine->settle(
        $this->engine->reserve($user, $this->model, input(1000), 1000),
        new Settlement(new TokenUsage(input: 1000, output: 1000)),
    );

    $credit = $this->engine->adjust($user, Usd::of('-0.005'), 'Refund for a failed answer', $admin);

    expect(period($user)->spent_usd->toString())->toBe('0.0060000000')
        ->and($credit->total_cost_usd->toString())->toBe('-0.0050000000')
        ->and($credit->created_by)->toBe($admin->id)
        ->and(AuditLog::query()->where('action', 'budget.adjusted')->sole()->new_values['amount_usd'])->toBe('-0.0050000000')
        ->and(fn () => $this->engine->adjust($user, Usd::of('-1'), 'Too much', $admin))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->engine->adjust($user, Usd::of('1'), ' ', $admin))->toThrow(InvalidArgumentException::class);
});

test('reconciliation reports mismatches and can recompute reserved amounts', function () {
    $user = budgetUser('1');
    $this->engine->settle(
        $this->engine->reserve($user, $this->model, input(1000), 1000),
        new Settlement(new TokenUsage(input: 1000, output: 1000)),
    );
    $this->engine->reserve($user, $this->model, input(1000), 1000);

    $this->artisan('ada:budget:reconcile')->assertSuccessful();

    // Inject drift.
    DB::table('budget_periods')->where('user_id', $user->id)->update(['reserved_usd' => '0.5', 'spent_usd' => '0.9']);

    $this->artisan('ada:budget:reconcile')->assertFailed();
    $this->artisan('ada:budget:reconcile --fix-reserved')->assertFailed(); // spent is never auto-fixed

    expect(period($user)->reserved_usd->toString())->toBe('0.0110000000')
        ->and(period($user)->spent_usd->toString())->toBe('0.9000000000')
        ->and(AuditLog::query()->where('action', 'budget.reserved_recomputed')->count())->toBe(1);
});

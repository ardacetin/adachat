<?php

use App\Domain\AI\Data\TokenUsage;
use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Money\UsdCast;
use App\Domain\Usage\CostCalculator;
use App\Domain\Usage\Pricing\PricingSnapshot;
use App\Models\AiModel;
use App\Models\BudgetPeriod;

function pricedModel(array $attributes = []): AiModel
{
    return (new AiModel)->forceFill([
        'input_price_per_million' => '1.000000',
        'output_price_per_million' => '10.000000',
        'cached_input_price_per_million' => null,
        'cache_write_price_per_million' => null,
        'context_window' => 200000,
        'max_output_tokens' => 8192,
        ...$attributes,
    ]);
}

test('usd arithmetic is exact with ten decimals', function () {
    $sum = Usd::zero();

    // 0.1 + 0.2 must be exactly 0.3; floats would drift.
    foreach (range(1, 1000) as $i) {
        $sum = $sum->plus(Usd::of('0.0000000001'));
    }

    expect(Usd::of('0.1')->plus(Usd::of('0.2'))->toString())->toBe('0.3000000000')
        ->and($sum->toString())->toBe('0.0000001000')
        ->and(Usd::of('1')->minus(Usd::of('1.5'))->isNegative())->toBeTrue();
});

test('amounts finer than ten decimals round up', function () {
    expect(Usd::of('0.00000000001')->toString())->toBe('0.0000000001')
        ->and(Usd::of('-0.00000000001')->toString())->toBe('-0.0000000001');
});

test('the cast refuses floats', function () {
    $cast = new UsdCast;

    expect($cast->set(new BudgetPeriod, 'limit_usd', '12.5', []))->toBe('12.5000000000')
        ->and(fn () => $cast->set(new BudgetPeriod, 'limit_usd', 12.5, []))->toThrow(InvalidArgumentException::class);
});

test('costs sum the disjoint token fields at their prices', function () {
    $model = pricedModel([
        'input_price_per_million' => '3',
        'cached_input_price_per_million' => '0.3',
        'cache_write_price_per_million' => '3.75',
        'output_price_per_million' => '15',
    ]);

    $cost = CostCalculator::calculate(
        new TokenUsage(input: 1000, cachedInput: 2000, cacheWrite: 400, output: 300, reasoning: 200),
        PricingSnapshot::forModel($model),
    );

    // 1000×3 + 2000×0.3 + 400×3.75 = 5100 per million; (300+200)×15 = 7500
    expect($cost->input->toString())->toBe('0.0051000000')
        ->and($cost->output->toString())->toBe('0.0075000000')
        ->and($cost->total()->toString())->toBe('0.0126000000');
});

test('missing cache prices fall back to the input price', function () {
    $cost = CostCalculator::calculate(new TokenUsage(cachedInput: 1_000_000, cacheWrite: 1_000_000), PricingSnapshot::forModel(pricedModel()));

    expect($cost->input->toString())->toBe('2.0000000000');
});

test('tiny costs round up rather than to zero', function () {
    $cost = CostCalculator::calculate(new TokenUsage(input: 1), PricingSnapshot::forModel(pricedModel(['input_price_per_million' => '0.000001'])));

    expect($cost->input->toString())->toBe('0.0000000001');
});

test('the highest tier exceeded by the input applies', function () {
    $model = pricedModel([
        'metadata' => ['pricing_tiers' => [
            ['above_input_tokens' => 200000, 'input_price_per_million' => '2', 'output_price_per_million' => '15'],
            ['above_input_tokens' => 100000, 'input_price_per_million' => '1.5', 'output_price_per_million' => '12'],
        ]],
    ]);

    expect((string) PricingSnapshot::forModel($model, 100000)->input)->toBe('1.000000')
        ->and((string) PricingSnapshot::forModel($model, 100001)->output)->toBe('12')
        ->and((string) PricingSnapshot::forModel($model, 250000)->input)->toBe('2');
});

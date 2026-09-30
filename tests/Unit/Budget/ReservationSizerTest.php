<?php

use App\Domain\AI\Data\InputTokenCount;
use App\Domain\AI\Enums\InputCountMethod;
use App\Domain\AI\Exceptions\ContextLengthExceeded;
use App\Domain\Budget\Exceptions\BudgetExhausted;
use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Services\ReservationSizer;
use App\Models\AiModel;

// $1 per million input tokens, $10 per million output tokens.
function sizerModel(array $attributes = []): AiModel
{
    return (new AiModel)->forceFill([
        'input_price_per_million' => '1',
        'output_price_per_million' => '10',
        'context_window' => 200000,
        'max_output_tokens' => 8192,
        ...$attributes,
    ]);
}

function counted(int $tokens, float $margin = 0.0, InputCountMethod $method = InputCountMethod::ProviderEndpoint): InputTokenCount
{
    return new InputTokenCount($tokens, $method, $margin);
}

test('the reservation is input cost plus maximum output cost', function () {
    $size = ReservationSizer::fit(counted(10000), sizerModel(), 4000, Usd::of('10'));

    // 10 000 × $1/M + 4 000 × $10/M
    expect($size->amount->toString())->toBe('0.0500000000')
        ->and($size->maxOutputTokens)->toBe(4000)
        ->and($size->outputCapped)->toBeFalse();
});

test('the safety margin is added to counted input', function () {
    $size = ReservationSizer::fit(counted(1000, 0.05), sizerModel(), 1000, Usd::of('10'));

    // 1050 input tokens (exactly, not 1051 from float error) + 1000 output
    expect($size->amount->toString())->toBe('0.0110500000');
});

test('output is capped to what the budget can pay for', function () {
    // $0.005 left: input costs $0.001, leaving 400 output tokens.
    $size = ReservationSizer::fit(counted(1000), sizerModel(), 8192, Usd::of('0.005'));

    expect($size->maxOutputTokens)->toBe(400)
        ->and($size->outputCapped)->toBeTrue()
        ->and($size->amount->toString())->toBe('0.0050000000');
});

test('requests are refused below a useful minimum of output', function () {
    // $0.003 left: 200 output tokens < 256.
    ReservationSizer::fit(counted(1000), sizerModel(), 8192, Usd::of('0.003'));
})->throws(BudgetExhausted::class);

test('requests are refused when the input alone does not fit', function () {
    ReservationSizer::fit(counted(1_000_000), sizerModel(['context_window' => 2_000_000]), 8192, Usd::of('0.5'));
})->throws(BudgetExhausted::class);

test('an exhausted or overdrawn budget refuses everything', function (string $available) {
    ReservationSizer::fit(counted(1), sizerModel(), 1000, Usd::of($available));
})->with(['0', '-0.25'])->throws(BudgetExhausted::class);

test('the cap never exceeds the model maximum or the context window', function () {
    $model = sizerModel(['context_window' => 10000, 'max_output_tokens' => 8192]);

    expect(ReservationSizer::fit(counted(100), $model, 50000, Usd::of('10'))->maxOutputTokens)->toBe(8192)
        ->and(ReservationSizer::fit(counted(6000), $model, 50000, Usd::of('10'))->maxOutputTokens)->toBe(4000);
});

test('input filling the context window is refused', function () {
    ReservationSizer::fit(counted(200000), sizerModel(), 1000, Usd::of('10'));
})->throws(ContextLengthExceeded::class);

test('a small alias cap is allowed below the useful minimum', function () {
    expect(ReservationSizer::fit(counted(10), sizerModel(), 100, Usd::of('10'))->maxOutputTokens)->toBe(100);
});

test('free output is limited only by the caps', function () {
    $size = ReservationSizer::fit(counted(1000), sizerModel(['output_price_per_million' => '0']), 4000, Usd::of('0.001'));

    expect($size->maxOutputTokens)->toBe(4000)
        ->and($size->amount->toString())->toBe('0.0010000000');
});

test('the higher tier is used when the margin crosses its threshold', function () {
    $model = sizerModel(['metadata' => ['pricing_tiers' => [
        ['above_input_tokens' => 100000, 'input_price_per_million' => '2', 'output_price_per_million' => '20'],
    ]]]);

    // 99 000 counted + 5 % = 103 950 → tier prices for input and output.
    $size = ReservationSizer::fit(counted(99000, 0.05), $model, 1000, Usd::of('10'));

    expect($size->amount->toString())->toBe('0.2279000000');
});

<?php

namespace App\Domain\Budget\Services;

use App\Domain\AI\Data\InputTokenCount;
use App\Domain\AI\Exceptions\ContextLengthExceeded;
use App\Domain\Budget\Data\ReservationSize;
use App\Domain\Budget\Exceptions\BudgetExhausted;
use App\Domain\Budget\Money\Usd;
use App\Domain\Usage\Pricing\PricingSnapshot;
use App\Models\AiModel;

/**
 * Measure the input, bound the output:
 *
 *   reservation = cost(counted input + margin) + cost(max output tokens)
 *               + searches allowed × (search price + cost(result tokens))
 *
 * When that does not fit the remaining budget, the output cap is lowered to
 * what the budget can pay for, down to a useful minimum. Cache discounts are
 * ignored (worst case); reasoning tokens are output.
 */
final class ReservationSizer
{
    /**
     * @param  int  $requestedMaxOutput  the alias/model output cap
     * @param  int  $webSearches  searches the provider may run (0 = none)
     *
     * @throws BudgetExhausted
     * @throws ContextLengthExceeded when the input leaves no room for output
     */
    public static function fit(InputTokenCount $input, AiModel $model, int $requestedMaxOutput, Usd $available, int $webSearches = 0): ReservationSize
    {
        $inputTokens = $input->reservedTokens();
        $pricing = PricingSnapshot::forModel($model, $inputTokens);

        // Providers reject requests whose input + output cap exceed the
        // context window, so the cap never asks for more than fits.
        $roomInContext = $model->context_window - $input->tokens;

        if ($roomInContext <= 0) {
            throw new ContextLengthExceeded('Input exceeds the model context window.');
        }

        $cap = max(1, min($requestedMaxOutput, $model->max_output_tokens, $roomInContext));

        // Search results are read as input; how much is unknown up front.
        $searchTokens = $webSearches * (int) config('ada.web_search.reserve_tokens_per_search', 4000);
        $inputCost = $pricing->inputCost($inputTokens + $searchTokens)->plus($pricing->webSearchCost($webSearches));

        if ($inputCost->isGreaterThan($available)) {
            throw new BudgetExhausted('The remaining budget does not cover the input.');
        }

        $affordable = $pricing->affordableOutputTokens($available->minus($inputCost));
        $maxOutput = $affordable === null ? $cap : min($cap, $affordable);

        $minimum = min((int) config('ada.budget.min_useful_output_tokens', 256), $cap);

        if ($maxOutput < $minimum) {
            throw new BudgetExhausted('The remaining budget does not cover a useful answer.');
        }

        return new ReservationSize(
            maxOutputTokens: $maxOutput,
            amount: $inputCost->plus($pricing->outputCost($maxOutput)),
            outputCapped: $maxOutput < $cap,
        );
    }
}

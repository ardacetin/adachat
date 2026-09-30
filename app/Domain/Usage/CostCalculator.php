<?php

namespace App\Domain\Usage;

use App\Domain\AI\Data\TokenUsage;
use App\Domain\Budget\Money\Usd;
use App\Domain\Usage\Data\Cost;
use App\Domain\Usage\Pricing\PricingSnapshot;

/**
 * Turns normalized (disjoint) token usage into money. Each part is rounded
 * up to 10 decimals, so Ada never under-charges.
 */
final class CostCalculator
{
    public static function calculate(TokenUsage $usage, PricingSnapshot $pricing): Cost
    {
        $input = PricingSnapshot::cost($usage->input, $pricing->input)
            ->plus(PricingSnapshot::cost($usage->cachedInput, $pricing->cachedInput))
            ->plus(PricingSnapshot::cost($usage->cacheWrite, $pricing->cacheWrite));

        // Reasoning/thinking tokens are billed as output by every provider.
        $output = PricingSnapshot::cost($usage->output + $usage->reasoning, $pricing->output);

        return new Cost($input, $output, Usd::zero());
    }
}

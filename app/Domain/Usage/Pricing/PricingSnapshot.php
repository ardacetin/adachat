<?php

namespace App\Domain\Usage\Pricing;

use App\Domain\Budget\Money\Usd;
use App\Models\AiModel;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The USD prices per million tokens that apply to one request. Taken from
 * the model when the request is priced and copied onto the usage event, so
 * later price changes never alter history.
 */
final readonly class PricingSnapshot
{
    private const MILLION = 1_000_000;

    public function __construct(
        public BigDecimal $input,
        public BigDecimal $cachedInput,
        public BigDecimal $cacheWrite,
        public BigDecimal $output,
        /** USD per 1,000 web searches; null when the model has no price. */
        public ?BigDecimal $webSearch = null,
    ) {}

    /**
     * Prices for a request with this many input tokens. Models may define
     * higher tiers in metadata.pricing_tiers:
     * [{"above_input_tokens": 200000, "input_price_per_million": "2.5", …}];
     * the highest tier whose threshold the input exceeds applies. Missing
     * cache prices fall back to the input price.
     */
    public static function forModel(AiModel $model, int $inputTokens = 0): self
    {
        $input = $model->input_price_per_million;
        $output = $model->output_price_per_million;
        $cachedInput = $model->cached_input_price_per_million;
        $cacheWrite = $model->cache_write_price_per_million;

        $tier = self::tierFor($model, $inputTokens);

        if ($tier !== null) {
            // Cache prices of the base tier do not carry over to a higher tier.
            $input = $tier['input_price_per_million'] ?? $input;
            $output = $tier['output_price_per_million'] ?? $output;
            $cachedInput = $tier['cached_input_price_per_million'];
            $cacheWrite = $tier['cache_write_price_per_million'];
        }

        $inputPrice = BigDecimal::of($input);

        return new self(
            input: $inputPrice,
            cachedInput: $cachedInput !== null ? BigDecimal::of($cachedInput) : $inputPrice,
            cacheWrite: $cacheWrite !== null ? BigDecimal::of($cacheWrite) : $inputPrice,
            output: BigDecimal::of($output),
            webSearch: $model->web_search_price_per_thousand !== null ? BigDecimal::of($model->web_search_price_per_thousand) : null,
        );
    }

    /**
     * Cost of input tokens at the full (uncached) input price.
     */
    public function inputCost(int $tokens): Usd
    {
        return self::cost($tokens, $this->input);
    }

    public function outputCost(int $tokens): Usd
    {
        return self::cost($tokens, $this->output);
    }

    public function webSearchCost(int $searches): Usd
    {
        if ($searches <= 0 || $this->webSearch === null) {
            return Usd::zero();
        }

        return Usd::of($this->webSearch->multipliedBy($searches)->dividedBy(1000, Usd::SCALE, RoundingMode::Up));
    }

    /**
     * The most output tokens the amount can pay for, or null when output is free.
     */
    public function affordableOutputTokens(Usd $amount): ?int
    {
        if ($this->output->isZero()) {
            return null;
        }

        if (! $amount->isPositive()) {
            return 0;
        }

        return $amount->amount
            ->multipliedBy(self::MILLION)
            ->dividedBy($this->output, 0, RoundingMode::Down)
            ->toInt();
    }

    public static function cost(int $tokens, BigDecimal $pricePerMillion): Usd
    {
        return Usd::of($pricePerMillion->multipliedBy($tokens)->dividedBy(self::MILLION, Usd::SCALE, RoundingMode::Up));
    }

    /**
     * @return array{input_price_per_million: ?string, output_price_per_million: ?string, cached_input_price_per_million: ?string, cache_write_price_per_million: ?string}|null
     */
    private static function tierFor(AiModel $model, int $inputTokens): ?array
    {
        $tiers = $model->metadata['pricing_tiers'] ?? null;

        if (! is_array($tiers)) {
            return null;
        }

        $selected = null;
        $selectedThreshold = -1;

        foreach ($tiers as $tier) {
            if (! is_array($tier) || ! is_int($tier['above_input_tokens'] ?? null)) {
                continue;
            }

            $threshold = $tier['above_input_tokens'];

            if ($inputTokens > $threshold && $threshold > $selectedThreshold) {
                $selected = $tier;
                $selectedThreshold = $threshold;
            }
        }

        if ($selected === null) {
            return null;
        }

        $price = static function (mixed $value): ?string {
            return is_string($value) || is_int($value) ? (string) $value : null;
        };

        return [
            'input_price_per_million' => $price($selected['input_price_per_million'] ?? null),
            'output_price_per_million' => $price($selected['output_price_per_million'] ?? null),
            'cached_input_price_per_million' => $price($selected['cached_input_price_per_million'] ?? null),
            'cache_write_price_per_million' => $price($selected['cache_write_price_per_million'] ?? null),
        ];
    }
}

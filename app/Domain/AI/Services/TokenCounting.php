<?php

namespace App\Domain\AI\Services;

use App\Domain\AI\Counters\EstimatedInputTokenCounter;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\InputTokenCount;
use App\Domain\AI\Enums\InputCountMethod;
use App\Domain\AI\Exceptions\ProviderException;
use App\Domain\AI\Exceptions\TokenCountUnavailable;
use App\Models\AiModel;
use Illuminate\Support\Facades\Log;

/**
 * Measures the input of a request before budget is reserved:
 * provider endpoint → (only if allowed) conservative estimate → refuse.
 */
final class TokenCounting
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly EstimatedInputTokenCounter $estimator,
    ) {}

    /**
     * @throws TokenCountUnavailable when counting failed and estimation is not allowed
     */
    public function count(AiModel $model, ChatRequest $request): InputTokenCount
    {
        $driver = $model->provider->driver->value;

        try {
            return new InputTokenCount(
                $this->providers->forModel($model)->count($request),
                InputCountMethod::ProviderEndpoint,
                $this->margin($driver),
            );
        } catch (ProviderException $exception) {
            if (config('ada.budget.on_counter_failure') !== 'estimate') {
                throw new TokenCountUnavailable("{$driver}: token counting failed", previous: $exception);
            }

            // No prompt content is logged.
            Log::warning('Token count endpoint failed; using the conservative estimate.', [
                'provider' => $driver,
                'model' => $model->provider_model_id,
                'error' => $exception->code(),
            ]);

            return new InputTokenCount(
                $this->estimator->count($request),
                InputCountMethod::Estimated,
                $this->margin('estimated'),
            );
        }
    }

    private function margin(string $key): float
    {
        return (float) config("ada.budget.input_count_margins.{$key}", 0.0);
    }
}

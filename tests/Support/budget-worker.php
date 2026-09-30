<?php

/*
 * Worker process for tests/Concurrency: hammers the budget engine for one
 * user from a separate PHP process (its own MySQL connection) and prints
 * a JSON summary.
 *
 * php tests/Support/budget-worker.php <user id> <model id> <iterations> <mode: settle|hold>
 */

use App\Domain\AI\Data\InputTokenCount;
use App\Domain\AI\Data\TokenUsage;
use App\Domain\AI\Enums\InputCountMethod;
use App\Domain\Budget\Data\Settlement;
use App\Domain\Budget\Exceptions\BudgetException;
use App\Domain\Budget\Services\BudgetEngine;
use App\Models\AiModel;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $modelId, $iterations, $mode] = $argv;

$engine = $app->make(BudgetEngine::class);
$user = User::query()->findOrFail((int) $userId);
$model = AiModel::query()->findOrFail((int) $modelId);
$result = ['reserved' => 0, 'refused' => 0, 'settled' => 0, 'released' => 0, 'errors' => []];

for ($i = 0; $i < (int) $iterations; $i++) {
    try {
        $reservation = $engine->reserve($user, $model, new InputTokenCount(1000, InputCountMethod::ProviderEndpoint, 0.0), 400);
        $result['reserved']++;
    } catch (BudgetException) {
        $result['refused']++;

        continue;
    } catch (Throwable $e) {
        $result['errors'][] = $e::class.': '.$e->getMessage();

        continue;
    }

    if ($mode === 'hold') {
        continue;
    }

    usleep(random_int(0, 20_000));

    try {
        if ($i % 4 === 0) {
            $engine->release($reservation, 'provider_error');
            $result['released']++;
        } else {
            // Never more than reserved: 1000 input + up to max output.
            $engine->settle($reservation, new Settlement(new TokenUsage(input: 1000, output: random_int(1, $reservation->max_output_tokens))));
            $result['settled']++;
        }
    } catch (Throwable $e) {
        $result['errors'][] = $e::class.': '.$e->getMessage();
    }
}

echo json_encode($result);

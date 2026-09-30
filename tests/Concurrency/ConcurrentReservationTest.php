<?php

use App\Domain\Budget\Enums\ReservationStatus;
use App\Domain\Budget\Services\Reconciler;
use App\Models\AiModel;
use App\Models\BudgetPeriod;
use App\Models\BudgetPolicy;
use App\Models\BudgetReservation;
use App\Models\Group;
use App\Models\UsageEvent;
use App\Models\User;
use Symfony\Component\Process\Process;

/*
 * Several PHP processes, each with its own MySQL connection, reserve and
 * settle against ONE user at the same time. The row lock on the user's
 * budget period must keep  spent + reserved ≤ limit  at every point.
 */

beforeEach(fn () => $this->artisan('migrate:fresh')->assertSuccessful());
afterEach(fn () => $this->artisan('migrate:fresh')->assertSuccessful());

/**
 * @return list<array{reserved: int, refused: int, settled: int, released: int, errors: list<string>}>
 */
function runWorkers(User $user, AiModel $model, int $workers, int $iterations, string $mode): array
{
    $connection = config('database.connections.mysql');
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => (string) $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => (string) $connection['database'],
        'DB_USERNAME' => (string) $connection['username'],
        'DB_PASSWORD' => (string) $connection['password'],
        'DB_URL' => '',
    ];

    $processes = [];

    foreach (range(1, $workers) as $ignored) {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/budget-worker.php'), (string) $user->id, (string) $model->id, (string) $iterations, $mode],
            base_path(),
            $env,
            timeout: 120,
        );
        $process->start();
        $processes[] = $process;
    }

    return array_map(function (Process $process): array {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }, $processes);
}

function concurrencySetup(string $limit, int $maxConcurrent): array
{
    $policy = BudgetPolicy::factory()->create(['monthly_limit_usd' => $limit]);
    $group = Group::factory()->create(['budget_policy_id' => $policy->id, 'max_concurrent_streams' => $maxConcurrent]);
    $user = User::factory()->create(['group_id' => $group->id]);
    // $1/M input, $10/M output: each reservation is 1000 in + 400 out = $0.005.
    $model = AiModel::factory()->create(['input_price_per_million' => '1', 'output_price_per_million' => '10']);

    return [$user, $model];
}

test('parallel reservations never exceed the limit', function () {
    [$user, $model] = concurrencySetup(limit: '0.05', maxConcurrent: 100);

    $results = runWorkers($user, $model, workers: 6, iterations: 12, mode: 'settle');

    $period = BudgetPeriod::query()->where('user_id', $user->id)->sole();
    $errors = array_merge(...array_column($results, 'errors'));
    $reserved = array_sum(array_column($results, 'reserved'));

    expect($errors)->toBe([])
        ->and($reserved)->toBeGreaterThan(0)
        ->and(array_sum(array_column($results, 'refused')))->toBeGreaterThan(0)
        ->and($period->spent_usd->plus($period->reserved_usd)->isGreaterThan($period->limit_usd))->toBeFalse()
        ->and($period->spent_usd->isNegative() || $period->reserved_usd->isNegative())->toBeFalse()
        ->and(BudgetReservation::query()->count())->toBe($reserved)
        ->and(UsageEvent::query()->count())->toBe(array_sum(array_column($results, 'settled')))
        ->and(app(Reconciler::class)->check())->toBe([]);
});

test('parallel requests respect the concurrent stream limit', function () {
    [$user, $model] = concurrencySetup(limit: '100', maxConcurrent: 3);

    $results = runWorkers($user, $model, workers: 6, iterations: 3, mode: 'hold');

    expect(array_merge(...array_column($results, 'errors')))->toBe([])
        ->and(array_sum(array_column($results, 'reserved')))->toBe(3)
        ->and(BudgetReservation::query()->where('status', ReservationStatus::Active)->count())->toBe(3)
        ->and(app(Reconciler::class)->check())->toBe([]);
});

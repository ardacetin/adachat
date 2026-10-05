<?php

use App\Domain\AI\Services\AliasAccess;
use App\Domain\AI\Services\CredentialVault;
use App\Domain\Conversations\Services\ChatGenerationService;
use App\Domain\Conversations\Services\InterruptedGenerations;
use App\Models\AiModel;
use App\Models\BudgetPeriod;
use App\Models\BudgetReservation;
use App\Models\Group;
use App\Models\Message;
use App\Models\ModelAlias;
use App\Models\Provider;
use App\Models\UsageEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * A stopped or broken stream ends before the provider's final usage event.
 * Anthropic reports output_tokens ≈ 1 at message_start; the settlement must
 * still charge for what the user received (security audit, run 1).
 */

function anthropicLongAnswer(int $deltas, bool $thinking = false): string
{
    $events = [
        ['message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_long', 'usage' => ['input_tokens' => 1000, 'output_tokens' => 1]]]],
    ];

    if ($thinking) {
        $events[] = ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => str_repeat('t', 4000)]]];
    }

    for ($i = 0; $i < $deltas; $i++) {
        $events[] = ['content_block_delta', ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => str_repeat('word ', 10)]]];
    }

    $events[] = ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5000]]];
    $events[] = ['message_stop', ['type' => 'message_stop']];

    return implode('', array_map(fn ($e) => "event: {$e[0]}\ndata: ".json_encode($e[1])."\n\n", $events));
}

beforeEach(function () {
    $provider = Provider::factory()->create(['driver' => 'anthropic']);
    app(CredentialVault::class)->rotate($provider, 'sk-ant-test-0000');

    // $1 per million input tokens, $10 per million output tokens.
    $model = AiModel::factory()->for($provider)->create([
        'provider_model_id' => 'claude-test',
        'input_price_per_million' => '1',
        'output_price_per_million' => '10',
        'context_window' => 200000,
        'max_output_tokens' => 8000,
    ]);
    $this->alias = ModelAlias::factory()->create(['ai_model_id' => $model->id, 'max_output_tokens' => 8000]);
    $this->alias->groups()->attach(Group::default());
    $this->user = User::factory()->create();
});

/**
 * @param  Closure(int, string): bool  $stopAfter  receives the number of deltas and the answer id
 */
function streamUntil(object $test, Closure $stopAfter, bool $thinking = false): UsageEvent
{
    Http::fake([
        '*/messages/count_tokens' => fn () => Http::response(['input_tokens' => 1000]),
        '*/messages' => fn () => Http::response(anthropicLongAnswer(400, $thinking), 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $alias = app(AliasAccess::class)->find($test->user, $test->alias->id);
    $gone = false;
    $deltas = 0;
    $answerId = null;

    foreach (app(ChatGenerationService::class)->send($test->user, null, $alias, 'Write a long essay', function () use (&$gone) {
        return $gone;
    }) as $event) {
        $answerId ??= $event->data['assistant_message_id'] ?? null;

        if ($event->name === 'delta') {
            $deltas++;
            $gone = $stopAfter($deltas, (string) $answerId);
        }
    }

    return UsageEvent::query()->sole();
}

test('a completed stream is charged what the provider reported', function () {
    $usage = streamUntil($this, fn () => false);

    expect($usage->output_tokens)->toBe(5000)
        ->and($usage->is_estimated)->toBeFalse();
});

test('a stream the client leaves is charged for the text it received', function () {
    $usage = streamUntil($this, fn (int $deltas) => $deltas >= 399);
    $answer = Message::query()->where('role', 'assistant')->sole();

    // 399 deltas of 50 bytes: 19950 / 2 output tokens, not message_start's 1,
    // capped at what the reservation allowed the provider to generate.
    expect(strlen($answer->content))->toBe(19950)
        ->and($usage->output_tokens)->toBe(min(9975, BudgetReservation::query()->sole()->max_output_tokens))
        ->and($usage->is_estimated)->toBeTrue();
});

test('a stream stopped with the Stop button is charged for the text it received', function () {
    $usage = streamUntil($this, function (int $deltas, string $answerId) {
        if ($deltas === 150) {
            Cache::put(ChatGenerationService::cancelKey($answerId), true, 60);
            usleep(600_000); // the flag is polled every half second
        }

        return false;
    });

    expect($usage->output_tokens)->toBeGreaterThanOrEqual(3750)
        ->and($usage->is_estimated)->toBeTrue();
});

test('reasoning streamed before a stop is charged as well', function () {
    $usage = streamUntil($this, fn (int $deltas) => $deltas >= 10, thinking: true);

    expect($usage->reasoning_tokens)->toBeGreaterThanOrEqual(2000)
        ->and($usage->output_tokens)->toBeGreaterThanOrEqual(250);
});

test('a stream the cleanup job took for dead still pays in full when it ends', function () {
    Http::fake([
        '*/messages/count_tokens' => fn () => Http::response(['input_tokens' => 1000]),
        '*/messages' => fn () => Http::response(anthropicLongAnswer(400), 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $alias = app(AliasAccess::class)->find($this->user, $this->alias->id);
    $deltas = 0;

    foreach (app(ChatGenerationService::class)->send($this->user, null, $alias, 'Write a long essay', fn () => false) as $event) {
        if ($event->name !== 'delta') {
            continue;
        }

        if (++$deltas === 10) {
            usleep(1_100_000); // the next delta writes the text so far to the database
        }

        // The request is still running (e.g. blocked writing to a slow
        // client) when the job decides it is past its deadline.
        if ($deltas === 20) {
            expect(app(InterruptedGenerations::class)->resolve(CarbonImmutable::now()->addHour()))->toBe(1)
                ->and(UsageEvent::query()->sole()->output_tokens)->toBeLessThan(1000);
        }
    }

    $events = UsageEvent::query()->orderBy('id')->get();
    $period = BudgetPeriod::query()->sole();

    // The estimate stays; a second charge adds what the provider reported
    // beyond it, once.
    expect($events)->toHaveCount(2)
        ->and($events->sum('output_tokens'))->toBe(5000)
        ->and($events[1]->reason)->toBe('settled_late')
        ->and($events[1]->reservation_id)->toBeNull()
        ->and($period->spent_usd->toString())->toBe($events[0]->total_cost_usd->plus($events[1]->total_cost_usd)->toString())
        ->and($period->reserved_usd->isZero())->toBeTrue()
        ->and(BudgetReservation::query()->sole()->status_reason)->toBe('settled_late');
});

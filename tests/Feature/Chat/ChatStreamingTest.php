<?php

use App\Domain\AI\Enums\InputCountMethod;
use App\Domain\AI\Services\CredentialVault;
use App\Domain\Budget\Enums\ReservationStatus;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Services\ChatGenerationService;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Models\AiModel;
use App\Models\BudgetPeriod;
use App\Models\BudgetPolicy;
use App\Models\BudgetReservation;
use App\Models\Conversation;
use App\Models\Group;
use App\Models\Message;
use App\Models\ModelAlias;
use App\Models\Provider;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $provider = Provider::factory()->create(['driver' => 'openai']);
    app(CredentialVault::class)->rotate($provider, 'sk-test-chat-0000');

    // $1/M input, $10/M output.
    $this->model = AiModel::factory()->for($provider)->create([
        'provider_model_id' => 'gpt-test',
        'input_price_per_million' => '1',
        'output_price_per_million' => '10',
        'context_window' => 200000,
        'max_output_tokens' => 8000,
    ]);

    $this->alias = ModelAlias::factory()->create(['ai_model_id' => $this->model->id, 'max_output_tokens' => 2000]);
    $this->alias->groups()->attach(Group::default());

    $this->user = User::factory()->create();
});

/**
 * Fake OpenAI: token count, then the given SSE fixture (or an HTTP error).
 */
function fakeOpenAi(string $fixture = 'stream', int $inputTokens = 1200, ?array $error = null): void
{
    // Closures: a streamed body can be read only once, so every request gets a fresh one.
    Http::fake([
        '*/responses/input_tokens' => fn () => Http::response(['input_tokens' => $inputTokens]),
        '*/responses' => fn () => $error !== null
            ? Http::response($error['body'], $error['status'])
            : Http::response(file_get_contents(base_path("tests/Fixtures/providers/openai-{$fixture}.sse")), 200, ['Content-Type' => 'text/event-stream']),
    ]);
}

/**
 * @return list<array{event: string, data: array<string, mixed>}>
 */
function sseEvents(string $body): array
{
    preg_match_all('/^event: (.+)\ndata: (.+)$/m', $body, $matches, PREG_SET_ORDER);

    return array_map(fn ($match) => ['event' => $match[1], 'data' => json_decode($match[2], true)], $matches);
}

function sendMessage(array $payload): array
{
    $response = test()->actingAs(test()->user)->post(route('messages.store'), $payload);
    $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

    return sseEvents($response->streamedContent());
}

test('a message streams, is stored and is charged', function () {
    fakeOpenAi();

    $events = sendMessage(['content' => 'Selam', 'model_alias_id' => $this->alias->id]);

    expect(array_column($events, 'event'))->toBe(['message.started', 'delta', 'delta', 'message.completed']);

    $conversation = Conversation::query()->sole();
    [$question, $answer] = $conversation->messages()->orderBy('id')->get()->all();
    $usage = UsageEvent::query()->sole();

    expect($conversation->user_id)->toBe($this->user->id)
        ->and($conversation->title)->toBe('Selam')
        ->and($events[0]['data']['conversation_id'])->toBe($conversation->id)
        ->and($question->content)->toBe('Selam')
        ->and($answer->content)->toBe('Merhaba dünya!')
        ->and($answer->status)->toBe(MessageStatus::Completed)
        ->and($answer->parent_message_id)->toBe($question->id)
        ->and($answer->finish_reason)->toBe('stop')
        ->and($usage->message_id)->toBe($answer->id)
        ->and($usage->conversation_id)->toBe($conversation->id)
        ->and($usage->model_alias_id)->toBe($this->alias->id)
        ->and($usage->is_estimated)->toBeFalse()
        ->and($events[3]['data']['usage']['cost_usd'])->toBe($usage->total_cost_usd->toString())
        ->and(BudgetReservation::query()->sole()->status)->toBe(ReservationStatus::Settled)
        ->and(BudgetPeriod::query()->sole()->reserved_usd->isZero())->toBeTrue();

    // The request carried the capped output and the history.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/responses')
        && $request['max_output_tokens'] === 2000
        && $request['input'] === [['role' => 'user', 'content' => 'Selam']]);
});

test('follow-up messages carry the conversation history', function () {
    fakeOpenAi();
    sendMessage(['content' => 'Selam', 'model_alias_id' => $this->alias->id]);
    $conversation = Conversation::query()->sole();

    fakeOpenAi();
    sendMessage(['content' => 'Nasılsın?', 'model_alias_id' => $this->alias->id, 'conversation_id' => $conversation->id]);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/responses') && $request['input'] === [
        ['role' => 'user', 'content' => 'Selam'],
        ['role' => 'assistant', 'content' => 'Merhaba dünya!'],
        ['role' => 'user', 'content' => 'Nasılsın?'],
    ]);

    expect($conversation->messages()->count())->toBe(4);
});

test('an exhausted budget refuses before anything is stored', function () {
    $policy = BudgetPolicy::factory()->create(['monthly_limit_usd' => '0']);
    $this->user->group->forceFill(['budget_policy_id' => $policy->id])->save();
    fakeOpenAi();

    $events = sendMessage(['content' => 'Selam', 'model_alias_id' => $this->alias->id]);

    expect($events)->toBe([['event' => 'error', 'data' => ['code' => 'budget_exhausted', 'retryable' => false]]])
        ->and(Conversation::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);
});

test('a reached institution cap refuses with its own error', function () {
    updateSettings(InstitutionSettings::class, ['monthly_cap_usd' => '0']);
    fakeOpenAi();

    $events = sendMessage(['content' => 'Selam', 'model_alias_id' => $this->alias->id]);

    expect($events)->toBe([['event' => 'error', 'data' => ['code' => 'institution_budget_exhausted', 'retryable' => false]]])
        ->and(Message::query()->count())->toBe(0);
});

test('a provider refusal before generating releases the budget', function () {
    fakeOpenAi(error: ['status' => 503, 'body' => ['error' => ['message' => 'overloaded']]]);

    $events = sendMessage(['content' => 'Selam', 'model_alias_id' => $this->alias->id]);
    $answer = Message::query()->where('role', 'assistant')->sole();

    expect(array_column($events, 'event'))->toBe(['message.started', 'error'])
        ->and($events[1]['data'])->toMatchArray(['code' => 'overloaded', 'retryable' => true])
        ->and($answer->status)->toBe(MessageStatus::Failed)
        ->and($answer->error_code)->toBe('overloaded')
        ->and(BudgetReservation::query()->sole()->status)->toBe(ReservationStatus::Released)
        ->and(UsageEvent::query()->count())->toBe(0);
});

test('an error after text was generated charges an estimate', function () {
    fakeOpenAi('stream-error');

    $events = sendMessage(['content' => 'Selam', 'model_alias_id' => $this->alias->id]);
    $answer = Message::query()->where('role', 'assistant')->sole();
    $usage = UsageEvent::query()->sole();

    expect(array_column($events, 'event'))->toBe(['message.started', 'delta', 'error'])
        ->and($answer->content)->toBe('Par')
        ->and($answer->status)->toBe(MessageStatus::Failed)
        ->and($usage->is_estimated)->toBeTrue()
        ->and($usage->input_tokens)->toBe(1200)
        ->and($usage->output_tokens)->toBe(2);
});

test('the stop button cancels the stream and charges what was used', function () {
    fakeOpenAi();
    // The flag is visible from the first read of the stream.
    Cache::spy();
    Cache::shouldReceive('has')->andReturn(true);

    $events = sendMessage(['content' => 'Selam', 'model_alias_id' => $this->alias->id]);
    $answer = Message::query()->where('role', 'assistant')->sole();

    expect(array_column($events, 'event'))->toBe(['message.started', 'message.completed'])
        ->and($answer->status)->toBe(MessageStatus::Cancelled)
        ->and(UsageEvent::query()->sole()->status->value)->toBe('partial');
});

test('the cancel endpoint flags only the owner\'s running answer', function () {
    $conversation = Conversation::factory()->for($this->user)->create();
    $answer = new Message;
    $answer->forceFill(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => '', 'status' => 'streaming'])->save();

    $this->actingAs(User::factory()->create())->post(route('messages.cancel', $answer))->assertForbidden();
    expect(Cache::has(ChatGenerationService::cancelKey($answer->id)))->toBeFalse();

    $this->actingAs($this->user)->post(route('messages.cancel', $answer))->assertNoContent();
    expect(Cache::has(ChatGenerationService::cancelKey($answer->id)))->toBeTrue();
});

test('aliases outside the user\'s group are refused', function () {
    $other = ModelAlias::factory()->create(['ai_model_id' => $this->model->id]);
    fakeOpenAi();

    $this->actingAs($this->user)
        ->post(route('messages.store'), ['content' => 'Selam', 'model_alias_id' => $other->id])
        ->assertForbidden();

    Http::assertNothingSent();
});

test('disabled models and providers make an alias unavailable', function () {
    $this->model->provider->forceFill(['enabled' => false])->save();

    $this->actingAs($this->user)
        ->post(route('messages.store'), ['content' => 'Selam', 'model_alias_id' => $this->alias->id])
        ->assertForbidden();
});

test('other people\'s conversations are not found or refused', function () {
    $conversation = Conversation::factory()->create();

    $this->actingAs($this->user)->get(route('conversations.show', $conversation))->assertForbidden();
    $this->actingAs($this->user)
        ->post(route('messages.store'), ['content' => 'Selam', 'model_alias_id' => $this->alias->id, 'conversation_id' => $conversation->id])
        ->assertForbidden();

    // Administrators cannot read conversations either.
    $this->actingAs(User::factory()->superAdmin()->create())->get(route('conversations.show', $conversation))->assertForbidden();
});

test('requests beyond the group rate limit are refused', function () {
    $this->user->group->forceFill(['requests_per_minute' => 1])->save();
    fakeOpenAi();
    sendMessage(['content' => 'Bir', 'model_alias_id' => $this->alias->id]);

    $events = sendMessage(['content' => 'İki', 'model_alias_id' => $this->alias->id]);

    expect($events[0]['data'])->toBe(['code' => 'rate_limited', 'retryable' => true]);
});

test('regenerating replaces the last answer in the thread', function () {
    fakeOpenAi();
    sendMessage(['content' => 'Selam', 'model_alias_id' => $this->alias->id]);
    $conversation = Conversation::query()->sole();
    $first = Message::query()->where('role', 'assistant')->sole();

    fakeOpenAi();
    $response = $this->actingAs($this->user)->post(route('messages.regenerate', $first));
    $events = sseEvents($response->streamedContent());

    $this->actingAs($this->user)->get(route('conversations.show', $conversation))
        ->assertInertia(fn ($page) => $page
            ->component('chat/show')
            ->has('messages', 2)
            ->where('messages.1.id', $events[0]['data']['assistant_message_id'])
            ->where('messages.1.content', 'Merhaba dünya!'));

    expect(Message::query()->where('role', 'assistant')->count())->toBe(2)
        ->and(UsageEvent::query()->count())->toBe(2);

    // Only the latest answer can be regenerated.
    fakeOpenAi();
    $again = sseEvents($this->actingAs($this->user)->post(route('messages.regenerate', $first))->streamedContent());
    expect($again[0]['data']['code'])->toBe('cannot_regenerate');
});

test('an answer interrupted by a dead process is charged by the cleanup job', function () {
    fakeOpenAi();
    sendMessage(['content' => 'Selam', 'model_alias_id' => $this->alias->id]);
    $answer = Message::query()->where('role', 'assistant')->sole();

    // Simulate a process that died mid-stream: active reservation, partial text.
    $reservation = BudgetReservation::query()->sole();
    UsageEvent::query()->toBase()->delete();
    $reservation->forceFill(['status' => ReservationStatus::Active, 'expires_at' => now()->subMinute()])->save();
    BudgetPeriod::query()->toBase()->update(['reserved_usd' => $reservation->amount_usd->toString(), 'spent_usd' => '0']);
    $answer->forceFill(['status' => MessageStatus::Streaming, 'content' => 'Yarım kal'])->save();

    $this->artisan('ada:budget:expire-reservations')->assertSuccessful();

    $usage = UsageEvent::query()->sole();
    expect($answer->refresh()->status)->toBe(MessageStatus::Failed)
        ->and($answer->error_code)->toBe('generation_interrupted')
        ->and($usage->is_estimated)->toBeTrue()
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Settled)
        ->and(BudgetPeriod::query()->sole()->reserved_usd->isZero())->toBeTrue();
});

test('conversations can be renamed and deleted by their owner', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $this->actingAs($this->user)->patch(route('conversations.update', $conversation), ['title' => 'Yeni ad'])->assertRedirect();
    expect($conversation->refresh()->title)->toBe('Yeni ad');

    $this->actingAs($this->user)->delete(route('conversations.destroy', $conversation))->assertRedirect(route('home'));
    expect(Conversation::query()->count())->toBe(0)
        ->and(Conversation::withTrashed()->count())->toBe(1);
});

test('the chat page lists only the user\'s allowed aliases and conversations', function () {
    ModelAlias::factory()->create(['ai_model_id' => $this->model->id]); // not assigned
    Conversation::factory()->for($this->user)->create(['title' => 'Benim']);
    Conversation::factory()->create(['title' => 'Başkasının']);

    $this->actingAs($this->user)->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->component('chat/index')
            ->has('aliases', 1)
            ->where('aliases.0.id', $this->alias->id)
            ->loadDeferredProps(fn ($reload) => $reload->has('conversations', 1)->where('conversations.0.title', 'Benim')));
});

test('the provider\'s reason for refusing a request is logged for administrators', function () {
    Log::spy();
    fakeOpenAi(error: ['status' => 400, 'body' => ['error' => ['code' => 'model_not_found', 'message' => 'The model `gpt-9` does not exist.']]]);

    $events = sendMessage(['content' => 'Selam', 'model_alias_id' => $this->alias->id]);

    expect($events[1]['data']['code'])->toBe('invalid_request');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'AI provider request failed.'
        && $context['code'] === 'invalid_request'
        && $context['status'] === 400
        && str_contains($context['provider'], 'model_not_found')
        && ! str_contains(json_encode($context), 'Selam'));
});

test('an OpenAI reasoning model cut off before its usage pays for the reasoning it may have done', function () {
    $sse = file_get_contents(base_path('tests/Fixtures/providers/openai-stream.sse'));
    $truncated = substr($sse, 0, (int) strpos($sse, 'event: response.completed'));
    Http::fake([
        '*/responses/input_tokens' => fn () => Http::response(['input_tokens' => 1200]),
        '*/responses' => fn () => Http::response($truncated, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    // Without reasoning, only the delivered text is charged.
    sendMessage(['content' => 'Hi', 'model_alias_id' => $this->alias->id]);
    expect(UsageEvent::query()->sole()->reasoning_tokens)->toBe(0);

    // A reasoning model does not stream its reasoning: up to the output cap.
    $this->model->forceFill(['supports_reasoning' => true])->save();
    sendMessage(['content' => 'Hi', 'model_alias_id' => $this->alias->id]);

    $usage = UsageEvent::query()->latest('id')->first();
    $reservation = BudgetReservation::query()->whereKey($usage->reservation_id)->sole();

    expect($usage->output_tokens + $usage->reasoning_tokens)->toBe($reservation->max_output_tokens)
        ->and($usage->is_estimated)->toBeTrue();
});

test('a stopped OpenAI-compatible reasoning model pays the input as reserved and the reasoning it may have hidden', function () {
    $provider = Provider::factory()->create(['driver' => 'openai_compatible', 'base_url' => 'http://llm.test/v1']);
    $this->model->forceFill(['provider_id' => $provider->id, 'supports_reasoning' => true])->save();

    // Cut off before the finish and usage chunks, with no reasoning streamed.
    $sse = file_get_contents(base_path('tests/Fixtures/providers/openai_compatible-stream.sse'));
    $sse = implode("\n\n", array_filter(explode("\n\n", substr($sse, 0, (int) strpos($sse, '"finish_reason":"stop"'))), fn ($chunk) => ! str_contains($chunk, 'reasoning_content') && ! str_contains($chunk, '"delta":{},')));
    Http::fake(['*/chat/completions' => fn () => Http::response($sse."\n\n", 200, ['Content-Type' => 'text/event-stream'])]);

    sendMessage(['content' => str_repeat('1234567890', 400), 'model_alias_id' => $this->alias->id]);

    $usage = UsageEvent::query()->sole();
    $reservation = BudgetReservation::query()->whereKey($usage->reservation_id)->sole();

    // The estimate has no provider count behind it: its margin is charged too.
    expect($reservation->input_count_method)->toBe(InputCountMethod::Estimated)
        ->and($usage->input_tokens)->toBe($reservation->input_tokens)
        ->and($usage->output_tokens + $usage->reasoning_tokens)->toBe($reservation->max_output_tokens)
        ->and($usage->is_estimated)->toBeTrue();
});

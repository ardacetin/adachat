<?php

use App\Domain\AI\Services\CredentialVault;
use App\Domain\Institution\Settings\PrivacySettings;
use App\Models\AiModel;
use App\Models\Assistant;
use App\Models\BudgetReservation;
use App\Models\Conversation;
use App\Models\ConversationShare;
use App\Models\Group;
use App\Models\Message;
use App\Models\ModelAlias;
use App\Models\Provider;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $provider = Provider::factory()->create(['driver' => 'openai']);
    app(CredentialVault::class)->rotate($provider, 'sk-test-search-0000');

    // $1/M input, $10/M output, $10 per 1,000 searches.
    $this->model = AiModel::factory()->for($provider)->create([
        'provider_model_id' => 'gpt-search',
        'input_price_per_million' => '1',
        'output_price_per_million' => '10',
        'context_window' => 200000,
        'max_output_tokens' => 8000,
        'supports_web_search' => true,
        'web_search_price_per_thousand' => '10',
    ]);

    $this->alias = ModelAlias::factory()->create([
        'ai_model_id' => $this->model->id,
        'max_output_tokens' => 2000,
        'web_search_enabled' => true,
        'web_search_max_uses' => 3,
    ]);
    $this->alias->groups()->attach(Group::default());

    $this->user = User::factory()->create();
});

function fakeSearchingOpenAi(string $fixture = 'web-search'): void
{
    Http::fake([
        '*/responses/input_tokens' => fn () => Http::response(['input_tokens' => 1000]),
        '*/responses' => fn () => Http::response(file_get_contents(base_path("tests/Fixtures/providers/openai-{$fixture}.sse")), 200, ['Content-Type' => 'text/event-stream']),
    ]);
}

/**
 * @return list<array{event: string, data: array<string, mixed>}>
 */
function searchEvents(array $payload): array
{
    $response = test()->actingAs(test()->user)->post(route('messages.store'), $payload);
    $response->assertOk();

    preg_match_all('/^event: (.+)\ndata: (.+)$/m', $response->streamedContent(), $matches, PREG_SET_ORDER);

    return array_map(fn ($match) => ['event' => $match[1], 'data' => json_decode($match[2], true)], $matches);
}

test('a message with web search streams the search and its sources and pays for it', function () {
    fakeSearchingOpenAi();

    $events = searchEvents(['content' => 'Ada Lovelace ne zaman doğdu?', 'model_alias_id' => $this->alias->id, 'web_search' => true]);
    $byType = fn (string $type) => array_values(array_filter($events, fn ($e) => $e['event'] === $type));

    expect(array_column($events, 'event'))->toBe(['message.started', 'search', 'delta', 'source', 'message.completed'])
        ->and($byType('search')[0]['data'])->toBe(['query' => 'ada lovelace doğum tarihi'])
        ->and($byType('source')[0]['data'])->toBe(['url' => 'https://tr.wikipedia.org/wiki/Ada_Lovelace', 'title' => 'Ada Lovelace - Vikipedi']);

    $completed = $byType('message.completed')[0]['data'];
    $usage = UsageEvent::query()->sole();
    $answer = Message::query()->where('role', 'assistant')->sole();

    // 3 000 input × $1/M + 60 output × $10/M + 1 search × $10/1000
    expect($usage->web_search_requests)->toBe(1)
        ->and($usage->web_search_price_snapshot)->toBe('10.000000')
        ->and($usage->other_cost_usd->toString())->toBe('0.0100000000')
        ->and($usage->total_cost_usd->toString())->toBe('0.0136000000')
        ->and($completed['usage']['web_searches'])->toBe(1)
        ->and($completed['sources'])->toBe([['url' => 'https://tr.wikipedia.org/wiki/Ada_Lovelace', 'title' => 'Ada Lovelace - Vikipedi']])
        ->and($answer->metadata['web_search'])->toBeTrue()
        ->and($answer->metadata['sources'])->toBe($completed['sources']);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/responses')
        && $request['tools'] === [['type' => 'web_search']]
        && $request['max_tool_calls'] === 3);
});

test('the reservation sets aside the allowed searches', function () {
    fakeSearchingOpenAi();

    searchEvents(['content' => 'Ara', 'model_alias_id' => $this->alias->id, 'web_search' => true]);

    // 1 000 input + 3 × 4 000 result tokens at $1/M, 2 000 output at $10/M, 3 searches at $10/1000
    expect(BudgetReservation::query()->sole()->amount_usd->toString())->toBe('0.0630000000');
});

test('the conversation page lists the sources of an answer', function () {
    fakeSearchingOpenAi();

    searchEvents(['content' => 'Ara', 'model_alias_id' => $this->alias->id, 'web_search' => true]);

    $this->actingAs($this->user)
        ->get(route('conversations.show', Conversation::query()->sole()))
        ->assertInertia(fn ($page) => $page
            ->where('messages.1.sources.0.url', 'https://tr.wikipedia.org/wiki/Ada_Lovelace'));
});

test('without web search the request carries no search tool and nothing is charged for it', function () {
    fakeSearchingOpenAi('stream');

    searchEvents(['content' => 'Selam', 'model_alias_id' => $this->alias->id]);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/responses') && ! isset($request['tools']));

    expect(UsageEvent::query()->sole()->web_search_requests)->toBe(0)
        ->and(UsageEvent::query()->sole()->web_search_price_snapshot)->toBeNull()
        ->and(Message::query()->where('role', 'assistant')->sole()->metadata)->toBeNull();
});

test('web search is refused where the alias does not allow it', function (array $alias, array $model) {
    Http::fake();
    $this->alias->update($alias);
    $this->model->update($model);

    $this->actingAs($this->user)
        ->postJson(route('messages.store'), ['content' => 'Ara', 'model_alias_id' => $this->alias->id, 'web_search' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('web_search');

    Http::assertNothingSent();
})->with([
    'alias off' => [['web_search_enabled' => false], []],
    'model without search' => [[], ['supports_web_search' => false]],
    'model without a search price' => [[], ['web_search_price_per_thousand' => null]],
]);

test('searches per message never exceed the configured limit', function () {
    $this->alias->forceFill(['web_search_max_uses' => 50])->save();

    expect($this->alias->refresh()->webSearchMaxUses())->toBe(5);
});

test('the chat page tells which aliases can search and what a search costs', function () {
    $this->actingAs($this->user)
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->where('aliases.0.web_search', ['max_uses' => 3, 'price_per_search' => '0.01']));

    $this->alias->update(['web_search_enabled' => false]);

    $this->actingAs($this->user)
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page->where('aliases.0.web_search', null));
});

test('an assistant searches only when it allows web search', function () {
    fakeSearchingOpenAi();
    $assistant = Assistant::factory()->create(['model_alias_id' => $this->alias->id]);
    $assistant->groups()->attach(Group::default());

    $this->actingAs($this->user)
        ->postJson(route('messages.store'), ['content' => 'Ara', 'model_alias_id' => $this->alias->id, 'assistant_id' => $assistant->id, 'web_search' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('web_search');

    $assistant->update(['web_search_enabled' => true]);

    $events = searchEvents(['content' => 'Ara', 'model_alias_id' => $this->alias->id, 'assistant_id' => $assistant->id, 'web_search' => true]);

    expect(array_column($events, 'event'))->toContain('search');
});

test('the Markdown export lists the sources of an answer', function () {
    fakeSearchingOpenAi();

    searchEvents(['content' => 'Ara', 'model_alias_id' => $this->alias->id, 'web_search' => true]);

    $this->actingAs($this->user)
        ->get(route('conversations.export', Conversation::query()->sole()))
        ->assertOk()
        ->assertSee("**Sources**\n\n1. [Ada Lovelace - Vikipedi](<https://tr.wikipedia.org/wiki/Ada_Lovelace>)", false);
});

test('reports count the web searches', function () {
    fakeSearchingOpenAi();

    searchEvents(['content' => 'Ara', 'model_alias_id' => $this->alias->id, 'web_search' => true]);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.reports.index', ['by' => 'model']))
        ->assertInertia(fn ($page) => $page
            ->where('totals.web_searches', 1)
            ->where('totals.web_search_usd', '0.01')
            ->where('breakdown.0.web_searches', 1));
});

test('the doctor warns about an alias that allows search on a model that cannot', function () {
    $this->model->update(['supports_web_search' => false]);

    $this->artisan('ada:doctor')->expectsOutputToContain('does not support web search or has no search price');
});

test('a stream broken after its searches still pays for them', function () {
    $provider = Provider::factory()->create(['driver' => 'anthropic']);
    app(CredentialVault::class)->rotate($provider, 'sk-ant-search-0000');
    $this->model->forceFill(['provider_id' => $provider->id, 'provider_model_id' => 'claude-search'])->save();

    // message_start (500 input, 1 output), one search, some text, then the
    // connection drops before message_delta reports the searches.
    $sse = file_get_contents(base_path('tests/Fixtures/providers/anthropic-web-search.sse'));
    Http::fake([
        '*/messages/count_tokens' => fn () => Http::response(['input_tokens' => 1000]),
        '*/messages' => fn () => Http::response(substr($sse, 0, (int) strpos($sse, 'event: message_delta')), 200, ['Content-Type' => 'text/event-stream']),
    ]);

    searchEvents(['content' => 'Ada Lovelace ne zaman doğdu?', 'model_alias_id' => $this->alias->id, 'web_search' => true]);

    $usage = UsageEvent::query()->sole();

    expect($usage->web_search_requests)->toBe(1)
        ->and($usage->other_cost_usd->toString())->toBe('0.0100000000')
        // The search results count as input, as the reservation assumed.
        ->and($usage->input_tokens)->toBe(500 + (int) config('ada.web_search.reserve_tokens_per_search'))
        // The delivered text, not message_start's single output token.
        ->and($usage->output_tokens)->toBe((int) ceil(strlen("Ada Lovelace 1815'te doğdu.") / 2))
        ->and($usage->is_estimated)->toBeTrue();
});

test('an Anthropic turn paused during its searches is continued and paid in full', function () {
    $provider = Provider::factory()->create(['driver' => 'anthropic']);
    app(CredentialVault::class)->rotate($provider, 'sk-ant-search-0000');
    $this->model->forceFill(['provider_id' => $provider->id, 'provider_model_id' => 'claude-search'])->save();

    $paused = file_get_contents(base_path('tests/Fixtures/providers/anthropic-pause-turn.sse'));
    $continued = file_get_contents(base_path('tests/Fixtures/providers/anthropic-pause-continued.sse'));
    Http::fake([
        '*/messages/count_tokens' => fn () => Http::response(['input_tokens' => 1000]),
        '*/messages' => Http::sequence()
            ->push($paused, 200, ['Content-Type' => 'text/event-stream'])
            ->push($continued, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $events = searchEvents(['content' => 'Ada Lovelace ne zaman doğdu?', 'model_alias_id' => $this->alias->id, 'web_search' => true]);

    expect(end($events)['event'])->toBe('message.completed')
        ->and(Message::query()->where('role', 'assistant')->sole()->content)->toBe("Arıyorum. Ada Lovelace 1815'te doğdu.");

    // The second request sends the paused turn back, as streamed.
    $requests = Http::recorded(fn ($request) => str_ends_with($request->url(), '/messages'))->values();
    expect($requests)->toHaveCount(2);
    $body = $requests[1][0]->data();
    $turn = end($body['messages']);

    expect($turn['role'])->toBe('assistant')
        ->and(array_column($turn['content'], 'type'))->toBe(['text', 'server_tool_use', 'web_search_tool_result', 'server_tool_use'])
        ->and($turn['content'][1]['input'])->toBe(['query' => 'ada lovelace doğum tarihi'])
        ->and($turn['content'][2]['content'][0]['encrypted_content'])->toBe('abc')
        // What is left of the output cap and of the searches.
        ->and($body['max_tokens'])->toBe($requests[0][0]->data()['max_tokens'] - 30)
        ->and($body['tools'][0]['max_uses'])->toBe(2);

    // Both requests are charged.
    $usage = UsageEvent::query()->sole();
    expect($usage->input_tokens)->toBe(4500 + 6000)
        ->and($usage->output_tokens)->toBe(30 + 40)
        ->and($usage->web_search_requests)->toBe(2)
        ->and($usage->is_estimated)->toBeFalse();
});

test('a paused turn ends as cut off once the continuations are used up', function () {
    config(['ada.providers.max_continuations' => 0]);
    $provider = Provider::factory()->create(['driver' => 'anthropic']);
    app(CredentialVault::class)->rotate($provider, 'sk-ant-search-0000');
    $this->model->forceFill(['provider_id' => $provider->id, 'provider_model_id' => 'claude-search'])->save();

    Http::fake([
        '*/messages/count_tokens' => fn () => Http::response(['input_tokens' => 1000]),
        '*/messages' => fn () => Http::response(file_get_contents(base_path('tests/Fixtures/providers/anthropic-pause-turn.sse')), 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $events = searchEvents(['content' => 'Ada Lovelace ne zaman doğdu?', 'model_alias_id' => $this->alias->id, 'web_search' => true]);

    expect(Http::recorded(fn ($request) => str_ends_with($request->url(), '/messages')))->toHaveCount(1)
        ->and(end($events)['data']['finish_reason'] ?? null)->toBe('length');
});

test('a Gemini answer grounded in Google Search keeps Google\'s Search Suggestions and is shown only to its author', function () {
    $provider = Provider::factory()->create(['driver' => 'gemini']);
    app(CredentialVault::class)->rotate($provider, 'gm-search-0000');
    $this->model->forceFill(['provider_id' => $provider->id, 'provider_model_id' => 'gemini-search'])->save();

    Http::fake([
        '*:countTokens' => fn () => Http::response(['totalTokens' => 1000]),
        '*:streamGenerateContent*' => fn () => Http::response(file_get_contents(base_path('tests/Fixtures/providers/gemini-web-search.sse')), 200, ['Content-Type' => 'text/event-stream']),
    ]);

    searchEvents(['content' => 'Ada Lovelace ne zaman doğdu?', 'model_alias_id' => $this->alias->id, 'web_search' => true]);

    $answer = Message::query()->where('role', 'assistant')->sole();
    expect($answer->metadata['search_suggestions'])->toStartWith('<style>.container')
        ->toContain('https://www.google.com/search?q=ada+lovelace');

    // The author sees them with the answer, unmodified.
    $this->actingAs($this->user)->get(route('conversations.show', $answer->conversation_id))
        ->assertInertia(fn ($page) => $page->where('messages.1.search_suggestions', $answer->metadata['search_suggestions']));

    // A shared link and its copy leave the grounded answer out.
    $url = $this->actingAs($this->user)->postJson(route('conversations.shares.store', $answer->conversation_id))->assertCreated()->json('url');
    $path = (string) parse_url($url, PHP_URL_PATH);
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->get($path)->assertInertia(fn ($page) => $page
        ->where('messages.1.content', __('chat.share.withheld'))
        ->where('messages.1.sources', []));
    expect(json_encode(ConversationShare::query()->sole()->snapshot))->not->toContain('1815')->not->toContain('google.com/search');

    $this->actingAs($viewer)->post($path.'/copy')->assertRedirect();
    $copy = Conversation::query()->where('user_id', $viewer->id)->sole();
    expect($copy->messages()->where('role', 'assistant')->sole()->content)->toBe(__('chat.share.withheld'));
});

test('the doctor warns when Gemini search is on and answers are kept over two years', function () {
    $provider = Provider::factory()->create(['driver' => 'gemini']);
    $this->model->forceFill(['provider_id' => $provider->id])->save();

    updateSettings(PrivacySettings::class, ['conversation_retention_days' => null]);
    $this->artisan('ada:doctor')->expectsOutputToContain('set conversation retention to 730 days or less');

    updateSettings(PrivacySettings::class, ['conversation_retention_days' => 365]);
    $this->artisan('ada:doctor')->doesntExpectOutputToContain('set conversation retention to 730 days or less');
});

<?php

use App\Domain\AI\Services\CredentialVault;
use App\Models\AiModel;
use App\Models\Assistant;
use App\Models\BudgetReservation;
use App\Models\Conversation;
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

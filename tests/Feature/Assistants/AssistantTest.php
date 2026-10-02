<?php

use App\Domain\AI\Services\CredentialVault;
use App\Domain\Conversations\Services\ContextBuilder;
use App\Models\AiModel;
use App\Models\Assistant;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Group;
use App\Models\ModelAlias;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $provider = Provider::factory()->create(['driver' => 'openai']);
    app(CredentialVault::class)->rotate($provider, 'sk-test-assistant-0000');
    $model = AiModel::factory()->for($provider)->create(['provider_model_id' => 'gpt-test', 'context_window' => 200000, 'max_output_tokens' => 8000]);

    $this->alias = ModelAlias::factory()->create(['ai_model_id' => $model->id, 'system_prompt' => 'Follow the university rules.']);
    $this->alias->groups()->attach(Group::default());
    $this->otherAlias = ModelAlias::factory()->create(['ai_model_id' => $model->id]);
    $this->otherAlias->groups()->attach(Group::default());

    $this->assistant = Assistant::factory()->create([
        'slug' => 'thesis-helper',
        'name' => ['en' => 'Thesis helper', 'tr' => 'Tez yardımcısı'],
        'instructions' => 'You help with theses. Cite the guide.',
        'model_alias_id' => $this->alias->id,
        'starter_prompts' => ['How do I structure chapter 1?'],
    ]);
    $this->assistant->groups()->attach(Group::default());

    $this->user = User::factory()->create();
});

function fakeOpenAiForAssistant(): void
{
    Http::fake([
        '*/responses/input_tokens' => fn () => Http::response(['input_tokens' => 100]),
        '*/responses' => fn () => Http::response(file_get_contents(base_path('tests/Fixtures/providers/openai-stream.sse')), 200, ['Content-Type' => 'text/event-stream']),
    ]);
}

test('super administrators create and edit assistants, audited', function () {
    $admin = User::factory()->superAdmin()->create();
    $group = Group::factory()->create();

    $this->actingAs($admin)->post(route('admin.assistants.store'), [
        'slug' => 'writing-coach',
        'name' => ['en' => 'Writing coach', 'tr' => 'Yazma koçu'],
        'description' => ['en' => 'Feedback on drafts', 'tr' => ''],
        'instructions' => 'Give feedback, never write the text.',
        'model_alias_id' => $this->alias->id,
        'starter_prompts' => ['Check my introduction', '', '  '],
        'web_search_enabled' => true,
        'icon' => 'pen-line',
        'sort_order' => 5,
        'enabled' => true,
        'group_ids' => [$group->id],
    ])->assertRedirect(route('admin.assistants.index'));

    $coach = Assistant::query()->where('slug', 'writing-coach')->sole();
    expect($coach->starter_prompts)->toBe(['Check my introduction'])
        ->and($coach->web_search_enabled)->toBeTrue()
        ->and($coach->description)->toBe(['en' => 'Feedback on drafts'])
        ->and($coach->groups()->pluck('groups.id')->all())->toBe([$group->id]);

    $log = AuditLog::query()->where('action', 'assistant.created')->sole();
    expect($log->new_values)->toHaveKey('instructions_sha256')->not->toHaveKey('instructions');

    $this->put(route('admin.assistants.update', $coach), [
        ...$coach->only(['slug', 'name', 'model_alias_id', 'icon', 'sort_order']),
        'instructions' => 'Changed.',
        'enabled' => false,
        'group_ids' => [],
    ])->assertRedirect();

    expect(AuditLog::query()->where('action', 'assistant.updated')->sole()->new_values)
        ->toHaveKeys(['instructions_sha256', 'enabled', 'group_ids']);
});

test('only super administrators manage assistants', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.assistants.index'))
        ->assertForbidden();
});

test('the gallery shows assistants of the user\'s group whose model the user may use', function () {
    $hidden = Assistant::factory()->create(['model_alias_id' => $this->alias->id]);
    $hidden->groups()->attach(Group::factory()->create());

    $noAlias = Assistant::factory()->create(['model_alias_id' => ModelAlias::factory()->create(['ai_model_id' => $this->alias->ai_model_id])->id]);
    $noAlias->groups()->attach(Group::default());

    $disabled = Assistant::factory()->create(['model_alias_id' => $this->alias->id, 'enabled' => false]);
    $disabled->groups()->attach(Group::default());

    $this->actingAs($this->user)->get(route('assistants.index'))
        ->assertInertia(fn ($page) => $page
            ->component('chat/assistants')
            ->has('assistants', 1)
            ->where('assistants.0.slug', 'thesis-helper')
            ->where('assistants.0.starter_prompts', ['How do I structure chapter 1?']));

    $this->get(route('assistants.show', 'thesis-helper'))
        ->assertInertia(fn ($page) => $page->component('chat/index')->where('assistant.name', 'Thesis helper'));
    $this->get(route('assistants.show', $hidden->slug))->assertNotFound();
});

test('a conversation with an assistant sends its instructions after the alias rules', function () {
    fakeOpenAiForAssistant();

    $this->actingAs($this->user)
        ->post(route('messages.store'), ['content' => 'Hello', 'model_alias_id' => $this->alias->id, 'assistant_id' => $this->assistant->id])
        ->assertOk()->streamedContent();

    $conversation = Conversation::query()->sole();
    expect($conversation->assistant_id)->toBe($this->assistant->id);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/responses')
        && $request['instructions'] === "Follow the university rules.\n\nYou help with theses. Cite the guide.");

    // Follow-ups keep the assistant, without sending its id again.
    $this->post(route('messages.store'), ['content' => 'More', 'model_alias_id' => $this->alias->id, 'conversation_id' => $conversation->id])
        ->assertOk()->streamedContent();

    Http::assertSentCount(4);
    $this->get(route('conversations.show', $conversation))
        ->assertInertia(fn ($page) => $page->where('assistant.slug', 'thesis-helper'));
});

test('an assistant conversation keeps the assistant\'s model', function () {
    $this->actingAs($this->user)
        ->postJson(route('messages.store'), ['content' => 'Hello', 'model_alias_id' => $this->otherAlias->id, 'assistant_id' => $this->assistant->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['model_alias_id' => __('chat.assistant_model_locked')]);
});

test('users outside the assistant\'s groups cannot use it', function () {
    $outsider = User::factory()->create(['group_id' => Group::factory()->create()->id]);
    $this->alias->groups()->attach($outsider->group_id);

    $this->actingAs($outsider)
        ->post(route('messages.store'), ['content' => 'Hello', 'model_alias_id' => $this->alias->id, 'assistant_id' => $this->assistant->id])
        ->assertForbidden();
});

test('a conversation whose assistant was disabled cannot continue', function () {
    $conversation = Conversation::factory()->for($this->user)->create(['assistant_id' => $this->assistant->id, 'model_alias_id' => $this->alias->id]);
    $this->assistant->update(['enabled' => false]);

    $this->actingAs($this->user)
        ->post(route('messages.store'), ['content' => 'Hello', 'model_alias_id' => $this->alias->id, 'conversation_id' => $conversation->id])
        ->assertForbidden();
});

test('the system prompt joins alias rules and instructions', function () {
    expect(ContextBuilder::systemPrompt($this->alias, 'Be brief.'))->toBe("Follow the university rules.\n\nBe brief.")
        ->and(ContextBuilder::systemPrompt($this->otherAlias, null))->toBeNull()
        ->and(ContextBuilder::systemPrompt($this->otherAlias, '  Be brief. '))->toBe('Be brief.');
});

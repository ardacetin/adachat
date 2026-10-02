<?php

use App\Http\Controllers\Chat\SearchController;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ModelAlias;
use App\Models\User;

function chatMessage(Conversation $conversation, string $role, string $content, ?Message $parent = null, ?ModelAlias $alias = null): Message
{
    $message = new Message;
    $message->forceFill([
        'conversation_id' => $conversation->id,
        'parent_message_id' => $parent?->id,
        'role' => $role,
        'content' => $content,
        'status' => 'completed',
        'model_alias_id' => $alias?->id,
    ])->save();

    return $message;
}

function attach(Message $message, string $name): void
{
    (new MessageAttachment)->forceFill([
        'user_id' => $message->conversation->user_id,
        'message_id' => $message->id,
        'kind' => 'text',
        'original_name' => $name,
        'mime' => 'text/plain',
        'size' => 10,
        'sha256' => str_repeat('a', 64),
        'path' => 'attachments/x/'.$name,
        'token_estimate' => 3,
    ])->save();
}

test('a conversation can be pinned and unpinned by its owner only', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $this->actingAs(User::factory()->create())
        ->patch(route('conversations.update', $conversation), ['pinned' => true])
        ->assertForbidden();

    $this->actingAs($user)->patch(route('conversations.update', $conversation), ['pinned' => true])->assertRedirect();
    expect($conversation->refresh()->pinned_at)->not->toBeNull()
        ->and($conversation->title)->not->toBeNull();

    $this->patch(route('conversations.update', $conversation), ['pinned' => false]);
    expect($conversation->refresh()->pinned_at)->toBeNull();
});

test('pinned conversations come first in the sidebar', function () {
    $user = User::factory()->create();
    $old = Conversation::factory()->for($user)->create(['title' => 'Old but pinned', 'last_message_at' => now()->subYear(), 'pinned_at' => now()]);
    $new = Conversation::factory()->for($user)->create(['title' => 'Newest', 'last_message_at' => now()]);

    $this->actingAs($user)->get(route('home'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($page) => $page
            ->where('conversations.0.id', $old->id)
            ->where('conversations.0.pinned', true)
            ->where('conversations.1.id', $new->id)
            ->where('conversations.1.pinned', false)));
});

test('search finds the user\'s own conversations by title, message and file name', function () {
    $user = User::factory()->create();
    $byTitle = Conversation::factory()->for($user)->create(['title' => 'Thesis outline']);
    $byMessage = Conversation::factory()->for($user)->create(['title' => 'Something else']);
    chatMessage($byMessage, 'user', 'Please summarise the thesis chapter about Lovelace for me.');
    $byFile = Conversation::factory()->for($user)->create(['title' => 'Files']);
    attach(chatMessage($byFile, 'user', 'see attached'), 'thesis-notes.txt');
    Conversation::factory()->for($user)->create(['title' => 'Unrelated']);

    $deleted = Conversation::factory()->for($user)->create(['title' => 'Deleted thesis']);
    $deleted->delete();

    $foreign = Conversation::factory()->create(['title' => 'Someone else\'s thesis']);
    chatMessage($foreign, 'user', 'thesis');

    $this->actingAs($user)->get(route('search', ['q' => 'THESIS']))
        ->assertInertia(fn ($page) => $page
            ->component('chat/search')
            ->where('results', fn ($results) => collect($results)->pluck('id')->sort()->values()->all()
                === collect([$byTitle->id, $byMessage->id, $byFile->id])->sort()->values()->all())
            ->where('results', fn ($results) => collect($results)->firstWhere('id', $byMessage->id)['snippet']['match'] === 'thesis'));
});

test('administrators cannot search other people\'s conversations', function () {
    $conversation = Conversation::factory()->create(['title' => 'Private matter']);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('search', ['q' => 'Private']))
        ->assertInertia(fn ($page) => $page->where('results', []));
});

test('search escapes LIKE wildcards and needs two characters', function () {
    $user = User::factory()->create();
    Conversation::factory()->for($user)->create(['title' => 'Budget 100% done']);
    Conversation::factory()->for($user)->create(['title' => 'Budget 1000 done']);

    $this->actingAs($user)->get(route('search', ['q' => '100%']))
        ->assertInertia(fn ($page) => $page->has('results', 1)->where('results.0.title', 'Budget 100% done'));

    $this->get(route('search', ['q' => '_']))->assertInertia(fn ($page) => $page->where('results', null));
    $this->get(route('search', ['q' => 'x_y']))->assertInertia(fn ($page) => $page->where('results', []));
});

test('snippets show the text around the match', function () {
    $snippet = SearchController::snippet(str_repeat('a ', 60)."the KEY word\n\nand ".str_repeat('b ', 60), 'key');

    expect($snippet['match'])->toBe('KEY')
        ->and($snippet['before'])->toStartWith('…')->toEndWith('the ')
        ->and($snippet['after'])->toStartWith(' word and')->toEndWith('…');
});

test('a conversation exports as Markdown for its owner', function () {
    $user = User::factory()->create();
    $alias = ModelAlias::factory()->create(['name' => ['en' => 'Smart', 'tr' => 'Akıllı']]);
    $conversation = Conversation::factory()->for($user)->create(['title' => 'Ada & Babbage: notes']);
    $question = chatMessage($conversation, 'user', 'Who was Ada Lovelace?');
    attach($question, 'notes.txt');
    chatMessage($conversation, 'assistant', "A **mathematician**.\n\n```php\necho 1;\n```", $question, $alias);

    $this->actingAs(User::factory()->create())->get(route('conversations.export', $conversation))->assertForbidden();

    $response = $this->actingAs($user)->get(route('conversations.export', $conversation))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/markdown; charset=UTF-8')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="ada-ada-babbage-notes-'.now()->toDateString().'.md"')
        ->and($response->getContent())
        ->toStartWith("# Ada & Babbage: notes\n")
        ->toContain("## You\n\nWho was Ada Lovelace?\n\n_Attachments: notes.txt_")
        ->toContain("## Assistant (Smart)\n\nA **mathematician**.\n\n```php\necho 1;\n```");
});

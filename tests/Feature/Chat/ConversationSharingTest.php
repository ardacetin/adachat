<?php

use App\Domain\Institution\Settings\InstitutionSettings;
use App\Domain\Retention\RetentionPruner;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\ConversationShare;
use App\Models\Group;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ModelAlias;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Ada Owner']);
    $this->viewer = User::factory()->create();
    $this->alias = ModelAlias::factory()->create(['name' => ['en' => 'Smart', 'tr' => 'Akıllı']]);
    $this->alias->groups()->attach(Group::default());

    $this->conversation = Conversation::factory()->for($this->owner)->create([
        'title' => 'Bir sohbet',
        'model_alias_id' => $this->alias->id,
    ]);
    $question = sharedThreadMessage($this->conversation, 'user', 'Ada Lovelace kimdir?');
    (new MessageAttachment)->forceFill([
        'user_id' => $this->owner->id,
        'message_id' => $question->id,
        'kind' => 'text',
        'original_name' => 'notlar.txt',
        'mime' => 'text/plain',
        'size' => 10,
        'sha256' => str_repeat('a', 64),
        'path' => 'attachments/x/notlar.txt',
        'token_estimate' => 3,
    ])->save();
    $this->attachment = MessageAttachment::query()->sole();
    $this->answer = sharedThreadMessage($this->conversation, 'assistant', 'İlk programcı.', $question, $this->alias, [
        'sources' => [['url' => 'https://tr.wikipedia.org/wiki/Ada_Lovelace', 'title' => 'Vikipedi']],
    ]);
});

/**
 * @param  array<string, mixed>|null  $metadata
 */
function sharedThreadMessage(Conversation $conversation, string $role, string $content, ?Message $parent = null, ?ModelAlias $alias = null, ?array $metadata = null, string $status = 'completed'): Message
{
    $message = new Message;
    $message->forceFill([
        'conversation_id' => $conversation->id,
        'parent_message_id' => $parent?->id,
        'role' => $role,
        'content' => $content,
        'status' => $status,
        'model_alias_id' => $alias?->id,
        'metadata' => $metadata,
    ])->save();

    return $message;
}

/**
 * @return string the share URL's path
 */
function shareConversation(Conversation $conversation, User $owner): string
{
    $url = test()->actingAs($owner)
        ->postJson(route('conversations.shares.store', $conversation))
        ->assertCreated()
        ->json('url');

    return (string) parse_url($url, PHP_URL_PATH);
}

test('the owner shares a frozen copy that signed-in users can read', function () {
    $path = shareConversation($this->conversation, $this->owner);

    // Only the hash of the 32-byte token is stored.
    $token = basename($path);
    $share = ConversationShare::query()->sole();
    expect(strlen($token))->toBe(43)
        ->and($share->token_hash)->toBe(hash('sha256', $token))
        ->and(ConversationShare::query()->where('token_hash', $token)->exists())->toBeFalse();

    // Written after sharing: not part of the snapshot.
    sharedThreadMessage($this->conversation, 'user', 'Sonradan yazılan', $this->answer);

    $response = $this->actingAs($this->viewer)->get($path);

    $response->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn ($page) => $page
            ->component('shares/show')
            ->where('share.title', 'Bir sohbet')
            ->where('share.by', 'Ada Owner')
            ->where('share.own', false)
            ->has('messages', 2)
            ->where('messages.0.content', 'Ada Lovelace kimdir?')
            ->where('messages.0.attachments', ['notlar.txt'])
            ->where('messages.1.model', 'Smart')
            ->where('messages.1.sources.0.url', 'https://tr.wikipedia.org/wiki/Ada_Lovelace'));

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->content())->not->toContain('Sonradan yazılan')
        ->and($share->refresh()->view_count)->toBe(1);
});

test('the owner sees the live links on the conversation and views by the owner are not counted', function () {
    $path = shareConversation($this->conversation, $this->owner);

    $this->actingAs($this->owner)->get($path)->assertInertia(fn ($page) => $page->where('share.own', true));

    $this->actingAs($this->owner)
        ->get(route('conversations.show', $this->conversation))
        ->assertInertia(fn ($page) => $page
            ->has('sharing.links', 1)
            ->where('sharing.links.0.view_count', 0)
            ->missing('sharing.links.0.token_hash'));
});

test('guests are sent to sign in and disabled users are turned away', function () {
    $path = shareConversation($this->conversation, $this->owner);
    auth()->logout();

    $this->get($path)->assertRedirect(route('login'));

    $this->actingAs(User::factory()->disabled()->create())->get($path)->assertRedirect();
    expect(ConversationShare::query()->sole()->view_count)->toBe(0);
});

test('a share never opens the conversation itself or its files', function () {
    $path = shareConversation($this->conversation, $this->owner);

    $this->actingAs($this->viewer)->get($path)->assertOk();
    $this->actingAs($this->viewer)->get(route('conversations.show', $this->conversation))->assertForbidden();
    $this->actingAs($this->viewer)->get(route('attachments.show', $this->attachment))->assertNotFound();
});

test('a revoked link, a deleted conversation and turned-off sharing all answer 404', function () {
    $path = shareConversation($this->conversation, $this->owner);
    $share = ConversationShare::query()->sole();

    $this->actingAs($this->viewer)->delete(route('shares.destroy', $share))->assertNotFound();
    $this->actingAs($this->owner)->delete(route('shares.destroy', $share))->assertRedirect();
    $this->actingAs($this->viewer)->get($path)->assertNotFound();

    $second = shareConversation($this->conversation, $this->owner);
    $settings = app(InstitutionSettings::class);
    $settings->conversation_sharing = false;
    $settings->save();

    $this->actingAs($this->viewer)->get($second)->assertNotFound();
    $this->actingAs($this->owner)->postJson(route('conversations.shares.store', $this->conversation))->assertNotFound();
    $this->actingAs($this->owner)
        ->get(route('conversations.show', $this->conversation))
        ->assertInertia(fn ($page) => $page->where('sharing', null));

    $settings->conversation_sharing = true;
    $settings->save();
    $this->actingAs($this->viewer)->get($second)->assertOk();

    $this->actingAs($this->owner)->delete(route('conversations.destroy', $this->conversation));
    $this->actingAs($this->viewer)->get($second)->assertNotFound();
});

test('only the owner can share a conversation', function () {
    $this->actingAs($this->viewer)
        ->postJson(route('conversations.shares.store', $this->conversation))
        ->assertForbidden();

    expect(ConversationShare::query()->count())->toBe(0);
});

test('shares are audited without content', function () {
    shareConversation($this->conversation, $this->owner);
    $this->actingAs($this->owner)->delete(route('shares.destroy', ConversationShare::query()->sole()));

    $logs = AuditLog::query()->whereIn('action', ['conversation.shared', 'conversation.share_revoked'])->orderBy('id')->get();

    expect($logs->pluck('action')->all())->toBe(['conversation.shared', 'conversation.share_revoked'])
        ->and(json_encode($logs->toArray()))->not->toContain('Ada Lovelace')->not->toContain('notlar.txt');
});

test('a viewer copies the conversation and keeps the model only with access to it', function () {
    $path = shareConversation($this->conversation, $this->owner);

    $this->actingAs($this->viewer)->post($path.'/copy')->assertRedirect();

    $copy = Conversation::query()->where('user_id', $this->viewer->id)->sole();
    $messages = $copy->messages()->orderBy('id')->get();

    expect($copy->title)->toBe('Bir sohbet')
        ->and($copy->model_alias_id)->toBe($this->alias->id)
        ->and($copy->assistant_id)->toBeNull()
        ->and($messages->pluck('content')->all())->toBe(['Ada Lovelace kimdir?', 'İlk programcı.'])
        ->and($messages[1]->parent_message_id)->toBe($messages[0]->id)
        ->and($messages[1]->metadata['sources'][0]['title'])->toBe('Vikipedi')
        ->and(MessageAttachment::query()->count())->toBe(1);

    // A viewer in a group without the alias gets the messages, not the model.
    $outsider = User::factory()->create(['group_id' => Group::factory()->create()->id]);
    $this->actingAs($outsider)->post($path.'/copy')->assertRedirect();

    $other = Conversation::query()->where('user_id', $outsider->id)->sole();
    expect($other->model_alias_id)->toBeNull()
        ->and($other->messages()->whereNotNull('model_alias_id')->count())->toBe(0);
});

test('shares go when retention removes their conversation', function () {
    shareConversation($this->conversation, $this->owner);
    $this->conversation->delete();
    $this->travelTo(CarbonImmutable::now()->addYear());

    app(RetentionPruner::class)->prune();

    expect(ConversationShare::query()->count())->toBe(0);
});

test('links are bounded per conversation and per day, and a revoked link drops its copy', function () {
    config(['ada.sharing.max_links_per_conversation' => 2, 'ada.sharing.daily_limit' => 3]);
    $store = fn () => $this->actingAs($this->owner)->postJson(route('conversations.shares.store', $this->conversation));

    $store()->assertCreated();
    $store()->assertCreated();

    // A third live link on the same conversation is refused.
    $store()->assertUnprocessable()->assertJsonPath('message', __('chat.share.too_many_links', ['max' => 2]));

    // Revoking frees a place and drops the stored copy.
    $share = ConversationShare::query()->oldest('id')->first();
    $this->actingAs($this->owner)->delete(route('shares.destroy', $share));
    expect($share->refresh()->snapshot['messages'])->toBe([]);

    $store()->assertCreated();

    // Three links today: the next one waits for tomorrow.
    $this->actingAs($this->owner)->delete(route('shares.destroy', ConversationShare::query()->whereNull('revoked_at')->oldest('id')->first()));
    $store()->assertTooManyRequests()->assertJsonPath('message', __('chat.share.daily_limit'));

    expect(AuditLog::query()->where('action', 'conversation.shared')->count())->toBe(3);
});

test('copies count against the daily allowance and are audited', function () {
    config(['ada.sharing.daily_limit' => 2]);
    $path = shareConversation($this->conversation, $this->owner);

    $this->actingAs($this->viewer)->post($path.'/copy')->assertRedirect();
    $this->actingAs($this->viewer)->post($path.'/copy')->assertRedirect();
    $this->actingAs($this->viewer)->post($path.'/copy');

    expect(Conversation::query()->where('user_id', $this->viewer->id)->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'conversation.share_copied')->count())->toBe(2);
});

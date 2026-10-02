<?php

use App\Domain\AI\Enums\MessageRole;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Models\Assistant;
use App\Models\Conversation;
use App\Models\Group;
use App\Models\Message;
use App\Models\MessageFeedback;
use App\Models\ModelAlias;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->alias = ModelAlias::factory()->create(['name' => ['en' => 'Smart', 'tr' => 'Akıllı']]);
    $this->alias->groups()->attach(Group::default());
    $this->conversation = Conversation::factory()->for($this->user)->create();
});

function answer(Conversation $conversation, ModelAlias $alias, MessageStatus $status = MessageStatus::Completed): Message
{
    $question = new Message;
    $question->forceFill(['conversation_id' => $conversation->id, 'role' => MessageRole::User, 'content' => 'Soru', 'status' => MessageStatus::Completed])->save();

    $message = new Message;
    $message->forceFill([
        'conversation_id' => $conversation->id,
        'parent_message_id' => $question->id,
        'role' => MessageRole::Assistant,
        'content' => 'Yanıt',
        'status' => $status,
        'model_alias_id' => $alias->id,
        'ai_model_id' => $alias->ai_model_id,
    ])->save();

    return $message;
}

test('the owner rates an answer, changes the vote and takes it back', function () {
    $message = answer($this->conversation, $this->alias);

    $this->actingAs($this->user)->put(route('messages.feedback', $message), ['rating' => 'up'])->assertRedirect();

    $vote = MessageFeedback::query()->sole();
    expect($vote->rating)->toBe('up')
        ->and($vote->reason)->toBeNull()
        ->and($vote->model_alias_id)->toBe($this->alias->id)
        ->and($vote->ai_model_id)->toBe($this->alias->ai_model_id);

    $this->actingAs($this->user)->put(route('messages.feedback', $message), ['rating' => 'down', 'reason' => 'too_long']);
    expect(MessageFeedback::query()->sole()->only(['rating', 'reason']))->toBe(['rating' => 'down', 'reason' => 'too_long']);

    $this->actingAs($this->user)
        ->get(route('conversations.show', $this->conversation))
        ->assertInertia(fn ($page) => $page->where('messages.1.feedback', ['rating' => 'down', 'reason' => 'too_long']));

    $this->actingAs($this->user)->put(route('messages.feedback', $message), ['rating' => null]);
    expect(MessageFeedback::query()->count())->toBe(0);
});

test('a thumbs down needs a known reason', function (array $payload) {
    $message = answer($this->conversation, $this->alias);

    $this->actingAs($this->user)->put(route('messages.feedback', $message), $payload)->assertSessionHasErrors();

    expect(MessageFeedback::query()->count())->toBe(0);
})->with([
    'no reason' => [['rating' => 'down']],
    'free text' => [['rating' => 'down', 'reason' => 'Bu yanıt saçma']],
    'unknown rating' => [['rating' => 'meh']],
]);

test('only the owner can rate, and only finished answers', function () {
    $message = answer($this->conversation, $this->alias);

    $this->actingAs(User::factory()->create())->put(route('messages.feedback', $message), ['rating' => 'up'])->assertForbidden();
    $this->actingAs(User::factory()->superAdmin()->create())->put(route('messages.feedback', $message), ['rating' => 'up'])->assertForbidden();

    $failed = answer($this->conversation, $this->alias, MessageStatus::Failed);
    $this->actingAs($this->user)->put(route('messages.feedback', $failed), ['rating' => 'up'])->assertStatus(422);

    $question = Message::query()->where('role', 'user')->firstOrFail();
    $this->actingAs($this->user)->put(route('messages.feedback', $question), ['rating' => 'up'])->assertStatus(422);

    expect(MessageFeedback::query()->count())->toBe(0);
});

test('votes outlive their conversation, without the message', function () {
    $message = answer($this->conversation, $this->alias);
    $this->actingAs($this->user)->put(route('messages.feedback', $message), ['rating' => 'up']);

    $this->conversation->forceDelete();

    $vote = MessageFeedback::query()->sole();
    expect($vote->message_id)->toBeNull()
        ->and($vote->model_alias_id)->toBe($this->alias->id);
});

test('administrators see satisfaction by alias, model and assistant, without content', function () {
    $assistant = Assistant::factory()->create(['model_alias_id' => $this->alias->id, 'name' => ['en' => 'Coach', 'tr' => 'Koç']]);
    $this->conversation->forceFill(['assistant_id' => $assistant->id])->save();

    foreach (['up', 'up', 'up', 'up', 'down'] as $rating) {
        $this->actingAs($this->user)->put(route('messages.feedback', answer($this->conversation, $this->alias)), [
            'rating' => $rating,
            'reason' => $rating === 'down' ? 'inaccurate' : null,
        ]);
    }

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.feedback.index'));
    $response->assertInertia(fn ($page) => $page
        ->component('admin/feedback')
        ->where('totals.up', 4)
        ->where('totals.down', 1)
        ->where('totals.reasons.inaccurate', 1)
        ->where('rows', [[
            'id' => $this->alias->id, 'label' => 'Smart', 'up' => 4, 'down' => 1, 'rate' => 80, 'reasons' => ['inaccurate' => 1],
        ]]));

    // Nothing on the page leads back to a message or a person.
    expect($response->content())->not->toContain('Yanıt')->not->toContain($this->user->email)->not->toContain($this->conversation->id);

    $this->actingAs($admin)
        ->get(route('admin.feedback.index', ['per' => 'assistant']))
        ->assertInertia(fn ($page) => $page->where('rows.0.label', 'Coach'));

    // Names follow the administrator's language.
    $admin->forceFill(['locale' => 'tr'])->save();

    $this->actingAs($admin)
        ->get(route('admin.feedback.index'))
        ->assertInertia(fn ($page) => $page->where('rows.0.label', 'Akıllı'));
});

test('a rate needs enough votes', function () {
    $this->actingAs($this->user)->put(route('messages.feedback', answer($this->conversation, $this->alias)), ['rating' => 'up']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.feedback.index', ['per' => 'model']))
        ->assertInertia(fn ($page) => $page->where('rows.0.up', 1)->where('rows.0.rate', null));
});

test('regular users cannot open the satisfaction report', function () {
    $this->actingAs($this->user)->get(route('admin.feedback.index'))->assertForbidden();
});

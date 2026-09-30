<?php

use App\Domain\AI\Enums\MessageRole;
use App\Domain\AI\Exceptions\ContextLengthExceeded;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Services\ContextBuilder;
use App\Models\AiModel;
use App\Models\Message;
use App\Models\ModelAlias;

/*
 * Window 400 tokens, answer cap 100: 300 tokens of input. The builder
 * estimates 3 characters per token plus 4 per message, so every 300-character
 * message costs 104 and only two of them fit.
 */
function contextAlias(): ModelAlias
{
    $model = new AiModel(['provider_model_id' => 'gpt-test', 'context_window' => 400, 'max_output_tokens' => 100]);
    $alias = new ModelAlias(['system_prompt' => null, 'max_output_tokens' => null, 'temperature' => null]);

    return $alias->setRelation('aiModel', $model);
}

function turn(MessageRole $role, string $content, MessageStatus $status = MessageStatus::Completed): Message
{
    return (new Message)->forceFill(['role' => $role, 'content' => $content, 'status' => $status]);
}

test('the oldest turns are dropped and the context starts with a user turn', function () {
    $history = collect([
        turn(MessageRole::User, str_repeat('a', 300)),
        turn(MessageRole::Assistant, str_repeat('b', 300)),
        turn(MessageRole::User, str_repeat('c', 300)),
        turn(MessageRole::Assistant, str_repeat('d', 300)),
        turn(MessageRole::User, str_repeat('e', 300)),
    ]);

    $request = (new ContextBuilder)->build(contextAlias(), $history);

    // "d" and "e" fit, but a conversation cannot start with an answer.
    expect($request->messages)->toHaveCount(1)
        ->and($request->messages[0]->text)->toBe(str_repeat('e', 300))
        ->and($request->maxOutputTokens)->toBe(100);
});

test('short conversations are sent whole, without empty answers', function () {
    $history = collect([
        turn(MessageRole::User, 'Hello'),
        turn(MessageRole::Assistant, '', MessageStatus::Failed),
        turn(MessageRole::User, 'Hello again'),
    ]);

    $request = (new ContextBuilder)->build(contextAlias(), $history);

    expect(array_map(fn ($message) => $message->text, $request->messages))->toBe(['Hello', 'Hello again']);
});

test('a message that alone exceeds the window is refused', function () {
    (new ContextBuilder)->build(contextAlias(), collect([turn(MessageRole::User, str_repeat('x', 1000))]));
})->throws(ContextLengthExceeded::class);

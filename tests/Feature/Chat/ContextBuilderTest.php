<?php

use App\Domain\AI\Enums\MessageRole;
use App\Domain\AI\Exceptions\ContextLengthExceeded;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Services\ContextBuilder;
use App\Models\AiModel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ModelAlias;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

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

    $request = app(ContextBuilder::class)->build(contextAlias(), $history);

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

    $request = app(ContextBuilder::class)->build(contextAlias(), $history);

    expect(array_map(fn ($message) => $message->text, $request->messages))->toBe(['Hello', 'Hello again']);
});

test('a message that alone exceeds the window is refused', function () {
    app(ContextBuilder::class)->build(contextAlias(), collect([turn(MessageRole::User, str_repeat('x', 1000))]));
})->throws(ContextLengthExceeded::class);

function withFiles(Message $message, array $files): Message
{
    return $message->setRelation('attachments', new Collection($files));
}

function imageFile(string $name, int $bytes): MessageAttachment
{
    $path = "attachments/1/{$name}";
    Storage::disk('local')->put($path, str_repeat('x', $bytes));

    return (new MessageAttachment)->forceFill([
        'kind' => 'image', 'original_name' => $name, 'mime' => 'image/png', 'size' => $bytes, 'path' => $path, 'token_estimate' => 10,
    ]);
}

test('text files become fenced blocks that cannot be closed from inside', function () {
    $file = (new MessageAttachment)->forceFill([
        'kind' => 'text', 'original_name' => 'notes.md', 'extracted_text' => "a\n```\nb\n", 'token_estimate' => 5,
    ]);

    $request = app(ContextBuilder::class)->build(contextAlias(), collect([withFiles(turn(MessageRole::User, 'Özetle'), [$file])]));

    expect($request->messages[0]->text)->toBe("Özetle\n\n````notes.md\na\n```\nb\n````")
        ->and($request->messages[0]->parts)->toBe([]);
});

test('older images beyond the request limit are replaced by a note', function () {
    Storage::fake('local');
    config(['ada.attachments.max_request_mb' => 1]);
    $mb = 1024 * 1024;

    $history = collect([
        withFiles(turn(MessageRole::User, 'eski'), [imageFile('old.png', (int) (0.6 * $mb))]),
        turn(MessageRole::Assistant, 'tamam'),
        withFiles(turn(MessageRole::User, 'yeni'), [imageFile('new.png', (int) (0.6 * $mb))]),
    ]);

    $alias = contextAlias();
    $alias->aiModel->context_window = 100000;
    $request = app(ContextBuilder::class)->build($alias, $history);

    expect($request->messages[0]->parts)->toBe([])
        ->and($request->messages[0]->text)->toBe("eski\n\n[File not sent again with this request: old.png]")
        ->and($request->messages[2]->parts)->toHaveCount(1)
        ->and($request->messages[2]->parts[0]->base64)->toBe(base64_encode(str_repeat('x', (int) (0.6 * $mb))));
});

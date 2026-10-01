<?php

use App\Domain\AI\Services\CredentialVault;
use App\Domain\Budget\Enums\ReservationStatus;
use App\Models\AiModel;
use App\Models\BudgetReservation;
use App\Models\Conversation;
use App\Models\Group;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ModelAlias;
use App\Models\Provider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $provider = Provider::factory()->create(['driver' => 'openai']);
    app(CredentialVault::class)->rotate($provider, 'sk-test-chat-0000');

    $this->model = AiModel::factory()->for($provider)->create([
        'provider_model_id' => 'gpt-vision',
        'input_price_per_million' => '1',
        'output_price_per_million' => '10',
        'context_window' => 200000,
        'max_output_tokens' => 8000,
        'supports_vision' => true,
    ]);
    $this->alias = ModelAlias::factory()->create(['ai_model_id' => $this->model->id, 'max_output_tokens' => 2000]);
    $this->alias->groups()->attach(Group::default());

    $this->user = User::factory()->create();
});

function fakeVisionProvider(int $inputTokens = 1800): void
{
    Http::fake([
        '*/responses/input_tokens' => fn () => Http::response(['input_tokens' => $inputTokens]),
        '*/responses' => fn () => Http::response(file_get_contents(base_path('tests/Fixtures/providers/openai-stream.sse')), 200, ['Content-Type' => 'text/event-stream']),
    ]);
}

function uploadAs(User $user, string $name, string $contents): MessageAttachment
{
    test()->actingAs($user)->post(route('attachments.store'), ['file' => uploadedFile($name, $contents)])->assertCreated();

    return MessageAttachment::query()->latest('id')->firstOrFail();
}

function sendWith(array $payload)
{
    return test()->actingAs(test()->user)->post(route('messages.store'), $payload, ['Accept' => 'application/json']);
}

test('attachments are sent with the message and linked to it', function () {
    fakeVisionProvider();
    $image = uploadAs($this->user, 'grafik.png', pngBytes(4, 4));
    $code = uploadAs($this->user, 'main.py', "print('hi')\n");

    $response = sendWith(['content' => 'Bunlara bak', 'model_alias_id' => $this->alias->id, 'attachment_ids' => [$image->id, $code->id]])->assertOk();
    $response->streamedContent();

    $question = Message::query()->where('role', 'user')->sole();

    expect($image->fresh()->message_id)->toBe($question->id)
        ->and($code->fresh()->message_id)->toBe($question->id)
        ->and($question->content)->toBe('Bunlara bak');

    // The same body is counted and generated: the image inline, the code as a fenced block.
    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/responses')) {
            return false;
        }

        $content = $request->data()['input'][0]['content'];

        return $content[0]['type'] === 'input_image'
            && str_starts_with($content[0]['image_url'], 'data:image/png;base64,')
            && $content[1] === ['type' => 'input_text', 'text' => "Bunlara bak\n\n```main.py\nprint('hi')\n```"];
    });

    // The conversation page lists them (without paths or contents).
    $props = $this->get(route('conversations.show', $question->conversation_id))->inertiaProps('messages');
    expect($props[0]['attachments'])->toHaveCount(2)
        ->and($props[0]['attachments'][0])->toMatchArray(['id' => $image->id, 'kind' => 'image', 'name' => 'grafik.png'])
        ->and($props[0]['attachments'][0])->not->toHaveKey('path');
});

test('a message may consist of attachments only and is titled after the first', function () {
    fakeVisionProvider();
    $image = uploadAs($this->user, 'ekran.png', pngBytes());

    sendWith(['model_alias_id' => $this->alias->id, 'attachment_ids' => [$image->id]])->assertOk()->streamedContent();

    expect(Conversation::query()->sole()->title)->toBe('ekran.png');
    sendWith(['content' => '  ', 'model_alias_id' => $this->alias->id])->assertUnprocessable();
});

test('regenerating sends the attachments again', function () {
    fakeVisionProvider();
    $image = uploadAs($this->user, 'a.png', pngBytes());
    sendWith(['content' => 'Bu ne?', 'model_alias_id' => $this->alias->id, 'attachment_ids' => [$image->id]])->streamedContent();
    $answer = Message::query()->where('role', 'assistant')->sole();

    $this->post(route('messages.regenerate', $answer))->assertOk()->streamedContent();

    $generations = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/responses'));
    expect($generations)->toHaveCount(2)
        ->and($generations[1][0]->data()['input'][0]['content'][0]['type'])->toBe('input_image');
});

test('attachments must be the sender\'s own unsent uploads', function () {
    fakeVisionProvider();
    $foreign = uploadAs(User::factory()->create(), 'x.png', pngBytes());

    sendWith(['content' => 'Hi', 'model_alias_id' => $this->alias->id, 'attachment_ids' => [$foreign->id]])
        ->assertUnprocessable()->assertJsonValidationErrors(['attachment_ids' => 'no longer available']);

    $mine = uploadAs($this->user, 'y.png', pngBytes());
    sendWith(['content' => 'Hi', 'model_alias_id' => $this->alias->id, 'attachment_ids' => [$mine->id]])->assertOk()->streamedContent();
    sendWith(['content' => 'Again', 'model_alias_id' => $this->alias->id, 'attachment_ids' => [$mine->id]])
        ->assertJsonValidationErrors(['attachment_ids' => 'no longer available']);

    sendWith(['content' => 'Hi', 'model_alias_id' => $this->alias->id, 'attachment_ids' => array_fill(0, 6, $mine->id)])
        ->assertJsonValidationErrors(['attachment_ids']);
});

test('images need a model that can read them', function () {
    $this->model->forceFill(['supports_vision' => false])->save();
    $image = uploadAs($this->user, 'a.png', pngBytes());
    $text = uploadAs($this->user, 'a.txt', 'metin');

    sendWith(['content' => 'Hi', 'model_alias_id' => $this->alias->id, 'attachment_ids' => [$image->id]])
        ->assertJsonValidationErrors(['attachment_ids' => 'cannot read images']);

    fakeVisionProvider();
    sendWith(['content' => 'Hi', 'model_alias_id' => $this->alias->id, 'attachment_ids' => [$text->id]])->assertOk()->streamedContent();
    expect($text->fresh()->isPending())->toBeFalse();
});

test('attachments stay unsent when the budget refuses the request', function () {
    fakeVisionProvider();
    $this->user->forceFill(['monthly_limit_override_usd' => '0.000001'])->save();
    $image = uploadAs($this->user, 'a.png', pngBytes());

    $body = sendWith(['content' => 'Hi', 'model_alias_id' => $this->alias->id, 'attachment_ids' => [$image->id]])->streamedContent();

    expect($body)->toContain('budget_exhausted')
        ->and($image->fresh()->isPending())->toBeTrue()
        ->and(Message::query()->count())->toBe(0);
});

test('an attachment sent meanwhile releases the reservation', function () {
    Http::fake([
        '*/responses/input_tokens' => function () {
            // A parallel request sends the same upload first.
            MessageAttachment::query()->update(['message_id' => Message::query()->forceCreate([
                'conversation_id' => Conversation::factory()->create(['user_id' => test()->user->id])->id,
                'role' => 'user', 'content' => 'other', 'status' => 'completed',
            ])->id]);

            return Http::response(['input_tokens' => 100]);
        },
    ]);
    $image = uploadAs($this->user, 'a.png', pngBytes());

    $body = sendWith(['content' => 'Hi', 'model_alias_id' => $this->alias->id, 'attachment_ids' => [$image->id]])->streamedContent();

    expect($body)->toContain('attachments_invalid')
        ->and(BudgetReservation::query()->sole()->status)->toBe(ReservationStatus::Released);
});

test('retention deletes attachment files with their conversation, and unsent and orphaned files', function () {
    fakeVisionProvider();
    $sent = uploadAs($this->user, 'sent.png', pngBytes());
    sendWith(['content' => 'Hi', 'model_alias_id' => $this->alias->id, 'attachment_ids' => [$sent->id]])->streamedContent();
    $unsent = uploadAs($this->user, 'unsent.txt', 'x');
    Storage::disk('local')->put('attachments/999/orphan', 'x');

    Conversation::query()->update(['deleted_at' => now()->subDays(60)]);
    $this->travelTo(CarbonImmutable::now()->addDays(2));

    $this->artisan('ada:retention:prune')->assertSuccessful();

    expect(MessageAttachment::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing([$sent->path, $unsent->path, 'attachments/999/orphan']);
});

test('fresh orphan files and recent uploads are kept', function () {
    $recent = uploadAs($this->user, 'recent.txt', 'x');
    Storage::disk('local')->put('attachments/999/just-written', 'x');

    $this->artisan('ada:retention:prune')->assertSuccessful();

    Storage::disk('local')->assertExists([$recent->path, 'attachments/999/just-written']);
});

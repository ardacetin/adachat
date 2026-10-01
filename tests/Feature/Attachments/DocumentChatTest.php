<?php

use App\Domain\AI\Services\CredentialVault;
use App\Models\AiModel;
use App\Models\Group;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ModelAlias;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
});

function documentAlias(string $driver, bool $files): ModelAlias
{
    $provider = Provider::factory()->create(['driver' => $driver, 'base_url' => $driver === 'openai_compatible' ? 'http://llm.test/v1' : null]);
    app(CredentialVault::class)->rotate($provider, 'sk-test-docs-0000');

    $model = AiModel::factory()->for($provider)->create([
        'provider_model_id' => 'doc-model',
        'input_price_per_million' => '1',
        'output_price_per_million' => '10',
        'context_window' => 200000,
        'max_output_tokens' => 8000,
        'supports_files' => $files,
    ]);
    $alias = ModelAlias::factory()->create(['ai_model_id' => $model->id, 'max_output_tokens' => 1000]);
    $alias->groups()->attach(Group::default());

    return $alias;
}

function fakeProviders(): void
{
    Http::fake([
        '*/responses/input_tokens' => fn () => Http::response(['input_tokens' => 3100]),
        '*/responses' => fn () => Http::response(file_get_contents(base_path('tests/Fixtures/providers/openai-stream.sse')), 200, ['Content-Type' => 'text/event-stream']),
        '*/chat/completions' => fn () => Http::response(file_get_contents(base_path('tests/Fixtures/providers/openai_compatible-stream.sse')), 200, ['Content-Type' => 'text/event-stream']),
    ]);
}

function attachFixture(string $name): MessageAttachment
{
    test()->actingAs(test()->user)
        ->post(route('attachments.store'), ['file' => uploadedFile($name, file_get_contents(base_path("tests/Fixtures/attachments/{$name}")))])
        ->assertCreated();

    return MessageAttachment::query()->latest('id')->firstOrFail();
}

function sendDocument(ModelAlias $alias, MessageAttachment $file)
{
    return test()->actingAs(test()->user)->post(
        route('messages.store'),
        ['content' => 'Özetle', 'model_alias_id' => $alias->id, 'attachment_ids' => [$file->id]],
        ['Accept' => 'application/json'],
    );
}

/**
 * @return array<string, mixed> the body of the generation request
 */
function generationBody(string $path): array
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), $path))->first()[0]->data();
}

test('a PDF goes to a model that reads files as the file itself', function () {
    fakeProviders();
    $alias = documentAlias('openai', files: true);
    $pdf = attachFixture('sample.pdf');

    sendDocument($alias, $pdf)->assertOk()->streamedContent();

    $content = generationBody('/responses')['input'][0]['content'];

    expect($content[0])->toMatchArray(['type' => 'input_file', 'filename' => 'sample.pdf'])
        ->and($content[0]['file_data'])->toStartWith('data:application/pdf;base64,')
        ->and($content[1])->toBe(['type' => 'input_text', 'text' => 'Özetle'])
        ->and($pdf->fresh()->message_id)->toBe(Message::query()->where('role', 'user')->value('id'));
});

test('other models get the PDF text', function (string $driver, bool $files, string $path) {
    fakeProviders();
    $alias = documentAlias($driver, $files);

    sendDocument($alias, attachFixture('sample.pdf'))->assertOk()->streamedContent();

    $body = json_encode(generationBody($path), JSON_UNESCAPED_UNICODE);

    expect($body)->toContain('```sample.pdf (2 pages)\nAda belge testi: bütçe raporu')
        ->not->toContain('application/pdf');
})->with([
    'files off' => ['openai', false, '/responses'],
    'OpenAI-compatible' => ['openai_compatible', true, '/chat/completions'],
]);

test('Office documents always go as text', function () {
    fakeProviders();
    $alias = documentAlias('openai', files: true);

    sendDocument($alias, attachFixture('sample.xlsx'))->assertOk()->streamedContent();

    expect(generationBody('/responses')['input'][0]['content'])
        ->toBe("Özetle\n\n```sample.xlsx\n## Bütçe\nBirim\tTutar\nFizik\t1250.5\nKimya\t\tnot\n\n## Özet\nToplam\t1250.5\n```");
});

test('a scanned PDF needs a model that reads files', function () {
    fakeProviders();
    $scanned = attachFixture('scanned.pdf');

    sendDocument(documentAlias('openai', files: false), $scanned)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attachment_ids' => 'contains no text']);

    sendDocument(documentAlias('openai', files: true), $scanned)->assertOk()->streamedContent();
    expect($scanned->fresh()->isPending())->toBeFalse();
});

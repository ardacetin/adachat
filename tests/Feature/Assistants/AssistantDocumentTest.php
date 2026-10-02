<?php

use App\Domain\AI\Data\ChatMessage;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Enums\MessageRole;
use App\Domain\AI\Services\CredentialVault;
use App\Domain\AI\Services\ProviderManager;
use App\Domain\Assistants\AssistantDocumentStore;
use App\Domain\Retention\RetentionPruner;
use App\Models\AiModel;
use App\Models\Assistant;
use App\Models\AssistantDocument;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\ModelAlias;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $provider = Provider::factory()->create(['driver' => 'openai']);
    app(CredentialVault::class)->rotate($provider, 'sk-test-docs-0000');
    $this->model = AiModel::factory()->for($provider)->create(['provider_model_id' => 'gpt-test', 'context_window' => 200000, 'max_output_tokens' => 8000, 'input_price_per_million' => '2']);
    $this->alias = ModelAlias::factory()->create(['ai_model_id' => $this->model->id]);
    $this->alias->groups()->attach(Group::default());

    $this->assistant = Assistant::factory()->create(['model_alias_id' => $this->alias->id, 'instructions' => 'Answer from the guide.']);
    $this->assistant->groups()->attach(Group::default());
    $this->admin = User::factory()->superAdmin()->create();
});

function textUpload(string $name, string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

test('super administrators add a document; its text follows the instructions', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.assistants.documents.store', $this->assistant), ['file' => textUpload('guide.md', "# Thesis guide\nChapters: 5.\n```\ncode\n```")])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $document = AssistantDocument::query()->sole();
    Storage::disk('local')->assertExists($document->path);
    expect($document->path)->toStartWith("assistants/{$this->assistant->id}/")
        ->and($document->token_estimate)->toBeGreaterThan(0)
        ->and(AuditLog::query()->where('action', 'assistant.document_added')->sole()->new_values)
        ->toMatchArray(['document' => 'guide.md', 'sha256' => $document->sha256]);

    expect($this->assistant->refresh()->systemInstructions())->toBe(
        "Answer from the guide.\n\n# Documents\n\nUse these documents provided by the institution when they are relevant.\n\n````guide.md\n# Thesis guide\nChapters: 5.\n```\ncode\n```\n````",
    );

    $this->get(route('admin.assistants.edit', $this->assistant))
        ->assertInertia(fn ($page) => $page
            ->has('assistant.documents', 1)
            ->where('assistant.documents.0.name', 'guide.md')
            ->where('maxDocumentTokens', 50000));
});

test('the documents reach the provider with every message', function () {
    app(AssistantDocumentStore::class)->store($this->assistant, textUpload('rules.txt', 'Deadline is May 1.'));
    Http::fake([
        '*/responses/input_tokens' => fn () => Http::response(['input_tokens' => 100]),
        '*/responses' => fn () => Http::response(file_get_contents(base_path('tests/Fixtures/providers/openai-stream.sse')), 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('messages.store'), ['content' => 'When?', 'model_alias_id' => $this->alias->id, 'assistant_id' => $this->assistant->id])
        ->assertOk()->streamedContent();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/responses')
        && str_contains((string) $request['instructions'], "```rules.txt\nDeadline is May 1.\n```"));
});

test('Anthropic caches a system prompt marked for caching', function () {
    $provider = Provider::factory()->create(['driver' => 'anthropic']);
    app(CredentialVault::class)->rotate($provider, 'sk-ant-test-0000');
    $chat = app(ProviderManager::class)->forProvider($provider);

    Http::fake(['*/messages' => fn () => Http::response(file_get_contents(base_path('tests/Fixtures/providers/anthropic-stream.sse')), 200, ['Content-Type' => 'text/event-stream'])]);

    $request = new ChatRequest('claude-test', [new ChatMessage(MessageRole::User, 'Hi')], 100, 'Long instructions', cacheSystemPrompt: true);
    iterator_to_array($chat->stream($request));

    Http::assertSent(fn (Request $sent) => $sent['system'] === [['type' => 'text', 'text' => 'Long instructions', 'cache_control' => ['type' => 'ephemeral']]]);
});

test('documents without text, images and oversized totals are refused', function () {
    $store = app(AssistantDocumentStore::class);
    $this->actingAs($this->admin);

    $this->post(route('admin.assistants.documents.store', $this->assistant), ['file' => UploadedFile::fake()->image('photo.png', 20, 20)])
        ->assertSessionHasErrors(['file' => __('chat.attachments.unsupported_type')]);

    config(['ada.assistants.max_document_tokens' => 10]);
    $this->post(route('admin.assistants.documents.store', $this->assistant), ['file' => textUpload('long.txt', str_repeat('word ', 50))])
        ->assertSessionHasErrors(['file' => __('chat.attachments.document_limit', ['max' => '10'])]);

    config(['ada.assistants.max_document_tokens' => 50000]);
    $this->model->update(['context_window' => 100]);
    $this->post(route('admin.assistants.documents.store', $this->assistant->refresh()), ['file' => textUpload('mid.txt', str_repeat('word ', 50))])
        ->assertSessionHasErrors(['file' => __('chat.attachments.context_limit')]);

    expect(AssistantDocument::query()->count())->toBe(0);
});

test('only super administrators manage documents', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.assistants.documents.store', $this->assistant), ['file' => textUpload('a.txt', 'x')])
        ->assertForbidden();
});

test('a removed document is deleted with its file, and orphans are swept', function () {
    $document = app(AssistantDocumentStore::class)->store($this->assistant, textUpload('old.txt', 'Old rules.'));
    $other = Assistant::factory()->create(['model_alias_id' => $this->alias->id]);

    $this->actingAs($this->admin)
        ->delete(route('admin.assistants.documents.destroy', [$other, $document]))
        ->assertNotFound();

    $this->delete(route('admin.assistants.documents.destroy', [$this->assistant, $document]))->assertRedirect();

    Storage::disk('local')->assertMissing($document->path);
    expect(AssistantDocument::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'assistant.document_removed')->sole()->old_values)->toMatchArray(['document' => 'old.txt']);

    // A file without a row (e.g. a failed upload) is removed after an hour.
    Storage::disk('local')->put("assistants/{$this->assistant->id}/stray", 'x');
    touch(Storage::disk('local')->path("assistants/{$this->assistant->id}/stray"), now()->subHours(2)->getTimestamp());

    expect(app(RetentionPruner::class)->prune()['orphan_files'])->toBe(1);
    Storage::disk('local')->assertMissing("assistants/{$this->assistant->id}/stray");
});

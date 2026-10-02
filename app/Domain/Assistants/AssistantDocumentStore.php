<?php

namespace App\Domain\Assistants;

use App\Domain\Attachments\AttachmentStore;
use App\Domain\Attachments\Enums\AttachmentKind;
use App\Domain\Attachments\Exceptions\AttachmentRejected;
use App\Domain\Attachments\FileInspector;
use App\Domain\Conversations\Services\ContextBuilder;
use App\Models\Assistant;
use App\Models\AssistantDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Stores an assistant's fixed documents on the private disk under
 * assistants/{assistant}/{uuid}. Only their text is used (PDFs are not sent
 * as files): it is added to the instructions of every request, so the total
 * is limited by ada.assistants.max_document_tokens and must leave most of
 * the model's context window to the conversation.
 */
final class AssistantDocumentStore
{
    public const DIRECTORY = 'assistants';

    public function __construct(private readonly FileInspector $inspector) {}

    /**
     * @throws AttachmentRejected
     */
    public function store(Assistant $assistant, UploadedFile $file): AssistantDocument
    {
        $path = (string) $file->getRealPath();
        $size = (int) $file->getSize();
        $attributes = $this->inspector->inspect($path, $size, allowImages: false);
        $text = trim((string) ($attributes['extracted_text'] ?? ''));

        if ($text === '') {
            throw new AttachmentRejected('no_text');
        }

        $tokens = FileInspector::textTokens($text);
        $total = (int) $assistant->documents()->sum('token_estimate') + $tokens;
        $limit = (int) config('ada.assistants.max_document_tokens');

        if ($total > $limit) {
            throw new AttachmentRejected('document_limit', ['max' => number_format($limit)]);
        }

        // The fixed part of every request may use at most half the window.
        $window = $assistant->modelAlias->aiModel->context_window;
        $fixed = ContextBuilder::estimateTokens((string) ContextBuilder::systemPrompt($assistant->modelAlias, $assistant->systemInstructions())) + $tokens;

        if ($fixed > intdiv($window, 2)) {
            throw new AttachmentRejected('context_limit');
        }

        $document = new AssistantDocument;
        $document->id = (string) Str::uuid7();
        $stored = self::DIRECTORY."/{$assistant->id}/{$document->id}";

        AttachmentStore::disk()->putFileAs(dirname($stored), $file, basename($stored));

        $document->forceFill([
            'assistant_id' => $assistant->id,
            'kind' => $attributes['kind'] instanceof AttachmentKind ? $attributes['kind'] : AttachmentKind::Text,
            'original_name' => FileInspector::cleanName($file->getClientOriginalName()),
            'mime' => (string) $attributes['mime'],
            'size' => $size,
            'sha256' => (string) hash_file('sha256', $path),
            'path' => $stored,
            'extracted_text' => $text,
            'page_count' => $attributes['page_count'] ?? null,
            'token_estimate' => $tokens,
            'sort_order' => (int) $assistant->documents()->max('sort_order') + 1,
        ])->save();

        return $document;
    }

    public function delete(AssistantDocument $document): void
    {
        AttachmentStore::disk()->delete($document->path);
        $document->delete();
    }
}

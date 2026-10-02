<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Assistants\AssistantDocumentStore;
use App\Domain\Attachments\Exceptions\AttachmentRejected;
use App\Domain\Audit\AuditLogger;
use App\Domain\Conversations\Services\ContextBuilder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssistantRequest;
use App\Models\Assistant;
use App\Models\AssistantDocument;
use App\Models\Group;
use App\Models\ModelAlias;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Institutional assistants (super administrators). Assistants are disabled,
 * never deleted: conversations refer to them.
 */
class AssistantController extends Controller
{
    private const FIELDS = [
        'slug', 'name', 'description', 'instructions', 'model_alias_id',
        'starter_prompts', 'icon', 'sort_order', 'enabled',
    ];

    public function index(): Response
    {
        $locale = app()->getLocale();

        return Inertia::render('admin/assistants/index', [
            'assistants' => Assistant::query()
                ->with('modelAlias')
                ->withCount(['groups'])
                ->orderBy('sort_order')
                ->orderBy('slug')
                ->get()
                ->map(fn (Assistant $assistant) => [
                    'id' => $assistant->id,
                    'slug' => $assistant->slug,
                    'name' => $assistant->localizedName($locale),
                    'icon' => $assistant->icon,
                    'alias' => $assistant->modelAlias->localizedName($locale),
                    'groups_count' => (int) $assistant->getAttribute('groups_count'),
                    'enabled' => $assistant->enabled,
                ]),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(Assistant $assistant): Response
    {
        return $this->form($assistant);
    }

    public function store(AssistantRequest $request, AuditLogger $audit): RedirectResponse
    {
        $assistant = Assistant::query()->create($this->values($request));
        $assistant->groups()->sync($this->groupIds($request));

        $audit->record('assistant.created', $assistant, [], $this->snapshot($assistant));

        return $this->saved();
    }

    public function update(AssistantRequest $request, Assistant $assistant, AuditLogger $audit): RedirectResponse
    {
        $before = $this->snapshot($assistant);

        $assistant->fill($this->values($request))->save();
        $assistant->groups()->sync($this->groupIds($request));

        [$old, $new] = AuditLogger::diff($before, $this->snapshot($assistant->refresh()));

        if ($new !== []) {
            $audit->record('assistant.updated', $assistant, $old, $new);
        }

        return $this->saved();
    }

    /**
     * Adds a fixed document; its text is sent with the instructions.
     */
    public function storeDocument(Request $request, Assistant $assistant, AssistantDocumentStore $documents, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-system');

        $request->validate(['file' => ['required', 'file', 'max:'.(int) ceil((float) config('ada.attachments.max_document_mb') * 1024)]]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        try {
            $document = $documents->store($assistant, $file);
        } catch (AttachmentRejected $rejected) {
            throw ValidationException::withMessages(['file' => $rejected->userMessage()]);
        }

        $audit->record('assistant.document_added', $assistant, [], ['document' => $document->original_name, 'sha256' => $document->sha256, 'tokens' => $document->token_estimate]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.saved')]);

        return back();
    }

    public function destroyDocument(Assistant $assistant, AssistantDocument $document, AssistantDocumentStore $documents, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-system');
        abort_unless($document->assistant_id === $assistant->id, 404);

        $documents->delete($document);
        $audit->record('assistant.document_removed', $assistant, ['document' => $document->original_name, 'sha256' => $document->sha256], []);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.saved')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function values(AssistantRequest $request): array
    {
        $values = $request->safe()->only(self::FIELDS);
        $values['description'] = array_filter((array) ($values['description'] ?? [])) ?: null;
        $values['starter_prompts'] = $values['starter_prompts'] ?: null;

        return $values;
    }

    /**
     * @return list<int>
     */
    private function groupIds(AssistantRequest $request): array
    {
        return array_values(array_map('intval', (array) $request->validated('group_ids', [])));
    }

    /**
     * What the audit log keeps. The instructions are kept as a hash: they
     * can be long, and the assistant's form shows the current text.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Assistant $assistant): array
    {
        return [
            ...$assistant->only(array_diff(self::FIELDS, ['instructions'])),
            'instructions_sha256' => hash('sha256', $assistant->instructions),
            'group_ids' => array_values(array_map(intval(...), $assistant->groups()->orderBy('groups.id')->pluck('groups.id')->all())),
        ];
    }

    private function form(?Assistant $assistant): Response
    {
        $locale = app()->getLocale();

        return Inertia::render('admin/assistants/form', [
            'assistant' => $assistant === null ? null : [
                ...$assistant->only(['id', ...self::FIELDS]),
                'starter_prompts' => $assistant->starter_prompts ?? [],
                'group_ids' => $assistant->groups()->pluck('groups.id')->map(intval(...))->all(),
                'documents' => $assistant->documents()->get()->map(fn (AssistantDocument $document) => $document->toAdmin())->values(),
                'fixed_tokens' => ContextBuilder::estimateTokens((string) ContextBuilder::systemPrompt($assistant->modelAlias, $assistant->systemInstructions())),
                'input_price_per_million' => $assistant->modelAlias->aiModel->input_price_per_million,
            ],
            'maxDocumentTokens' => (int) config('ada.assistants.max_document_tokens'),
            'aliases' => ModelAlias::query()->orderBy('sort_order')->orderBy('slug')->get()
                ->map(fn (ModelAlias $alias) => ['id' => $alias->id, 'name' => $alias->localizedName($locale), 'enabled' => $alias->enabled]),
            'groups' => Group::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'icons' => Assistant::ICONS,
        ]);
    }

    private function saved(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.saved')]);

        return to_route('admin.assistants.index');
    }
}

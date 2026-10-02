<?php

namespace App\Http\Controllers\Chat;

use App\Domain\Assistants\AssistantAccess;
use App\Domain\Conversations\Services\ChatPageProps;
use App\Http\Controllers\Controller;
use App\Models\Assistant;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The assistants a user may use, and a new conversation with one of them.
 */
class AssistantGalleryController extends Controller
{
    public function __construct(
        private readonly AssistantAccess $assistants,
        private readonly ChatPageProps $page,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('chat/assistants', [
            ...$this->page->for($user),
            'assistants' => $this->assistants->availableFor($user)
                ->map(fn (Assistant $assistant) => self::summary($assistant))
                ->values(),
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $user = $this->user($request);
        $assistant = $this->assistants->availableFor($user)->firstWhere('slug', $slug);
        abort_if($assistant === null, 404);

        return Inertia::render('chat/index', [
            ...$this->page->for($user),
            'assistant' => self::summary($assistant),
        ]);
    }

    /**
     * @return array{id: int, slug: string, name: string, description: string|null, icon: string, model_alias_id: int, starter_prompts: list<string>}
     */
    public static function summary(Assistant $assistant): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $assistant->id,
            'slug' => $assistant->slug,
            'name' => $assistant->localizedName($locale),
            'description' => $assistant->localizedDescription($locale),
            'icon' => $assistant->icon,
            'model_alias_id' => $assistant->model_alias_id,
            'starter_prompts' => $assistant->starter_prompts ?? [],
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}

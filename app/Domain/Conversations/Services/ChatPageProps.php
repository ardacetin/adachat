<?php

namespace App\Domain\Conversations\Services;

use App\Domain\AI\Services\AliasAccess;
use App\Models\Conversation;
use App\Models\ModelAlias;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Inertia\Inertia;

/**
 * Props every chat page needs: the conversation list in the sidebar and the
 * model selector.
 */
final class ChatPageProps
{
    /** Pinned conversations shown in the sidebar. */
    public const MAX_PINNED = 20;

    public function __construct(private readonly AliasAccess $aliases) {}

    /**
     * What turning on web search allows and costs with this alias, or null
     * when it cannot search.
     *
     * @return array{max_uses: int, price_per_search: string}|null
     */
    public static function webSearch(ModelAlias $alias): ?array
    {
        $maxUses = $alias->webSearchMaxUses();

        if ($maxUses === null) {
            return null;
        }

        $price = (string) BigDecimal::of((string) $alias->aiModel->web_search_price_per_thousand)
            ->dividedBy(1000, 6, RoundingMode::Up);

        return [
            'max_uses' => $maxUses,
            // 0.010000 → 0.01
            'price_per_search' => rtrim(rtrim($price, '0'), '.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $locale = app()->getLocale();

        return [
            'aliases' => $this->aliases->availableFor($user)->map(fn (ModelAlias $alias) => [
                'id' => $alias->id,
                'name' => $alias->localizedName($locale),
                'description' => $alias->description[$locale] ?? $alias->description['en'] ?? null,
                'supports_vision' => $alias->aiModel->supports_vision,
                'web_search' => self::webSearch($alias),
                // The underlying model is shown only when the admin allows it.
                'details' => $alias->show_model_details
                    ? "{$alias->aiModel->display_name} · {$alias->aiModel->provider->name}"
                    : null,
            ])->values(),
            // Pinned first (most recently pinned on top), then the latest others.
            'conversations' => Inertia::defer(fn () => $user->conversations()
                ->whereNotNull('pinned_at')
                ->orderByDesc('pinned_at')
                ->limit(self::MAX_PINNED)
                ->get(['id', 'title', 'pinned_at'])
                ->concat($user->conversations()
                    ->whereNull('pinned_at')
                    ->orderByDesc('last_message_at')
                    ->limit(50)
                    ->get(['id', 'title', 'pinned_at']))
                ->map(fn (Conversation $conversation) => [
                    'id' => $conversation->id,
                    'title' => $conversation->title,
                    'pinned' => $conversation->pinned_at !== null,
                ])
                ->values()),
        ];
    }
}

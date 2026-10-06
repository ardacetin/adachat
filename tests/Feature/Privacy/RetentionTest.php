<?php

use App\Domain\AI\Data\InputTokenCount;
use App\Domain\AI\Data\TokenUsage;
use App\Domain\AI\Enums\InputCountMethod;
use App\Domain\Budget\Data\Settlement;
use App\Domain\Budget\Services\BudgetEngine;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Domain\Institution\Settings\PrivacySettings;
use App\Models\AiModel;
use App\Models\AuditLog;
use App\Models\BudgetPeriod;
use App\Models\BudgetReservation;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\UsageEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule as Scheduler;

beforeEach(function () {
    updateSettings(InstitutionSettings::class, ['timezone' => 'Europe/Istanbul']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

function conversationAt(string $lastMessage, ?string $deleted = null): Conversation
{
    $conversation = Conversation::factory()->create(['last_message_at' => $lastMessage, 'deleted_at' => $deleted]);
    Message::query()->forceCreate(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Hi', 'status' => 'completed']);

    return $conversation;
}

function chargeAt(User $user, string $when): void
{
    test()->travelTo(CarbonImmutable::parse($when, 'UTC'));
    $engine = app(BudgetEngine::class);
    $reservation = $engine->reserve($user, AiModel::factory()->create(), new InputTokenCount(100, InputCountMethod::ProviderEndpoint, 0.0), 10);
    $engine->settle($reservation, new Settlement(new TokenUsage(input: 100, output: 10)));
    test()->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
}

test('deleted conversations are removed after the grace period, others are kept', function () {
    $recentlyDeleted = conversationAt('2026-10-01', deleted: '2026-10-01');
    $longDeleted = conversationAt('2026-08-01', deleted: '2026-09-01');
    $old = conversationAt('2020-01-01');

    $this->artisan('ada:retention:prune')->assertSuccessful();

    expect(Conversation::withTrashed()->pluck('id')->sort()->values()->all())->toBe(collect([$recentlyDeleted->id, $old->id])->sort()->values()->all())
        ->and(Message::query()->where('conversation_id', $longDeleted->id)->exists())->toBeFalse();
});

test('long threads are removed too', function () {
    // Each message points at the previous one; MySQL cascades at most 15
    // levels deep, so a cascade through the thread would refuse this.
    $conversation = Conversation::factory()->create(['last_message_at' => '2026-08-01', 'deleted_at' => '2026-09-01']);
    $parent = null;

    foreach (range(1, 40) as $i) {
        $parent = Message::query()->forceCreate([
            'conversation_id' => $conversation->id,
            'parent_message_id' => $parent?->id,
            'role' => $i % 2 === 1 ? 'user' : 'assistant',
            'content' => "Message {$i}",
            'status' => 'completed',
        ]);
    }

    $this->artisan('ada:retention:prune')->assertSuccessful();

    expect(Conversation::withTrashed()->whereKey($conversation->id)->exists())->toBeFalse()
        ->and(Message::query()->where('conversation_id', $conversation->id)->exists())->toBeFalse();
});

test('conversations expire after the retention period when one is set', function () {
    updateSettings(PrivacySettings::class, ['conversation_retention_days' => 90]);
    $expired = conversationAt('2026-07-01');
    $kept = conversationAt('2026-08-01');

    $this->artisan('ada:retention:prune')->assertSuccessful();

    expect(Conversation::withTrashed()->pluck('id')->all())->toBe([$kept->id])
        ->and(Conversation::withTrashed()->whereKey($expired->id)->exists())->toBeFalse();
});

test('usage older than the retention months is pruned in whole months, content untouched', function () {
    $user = User::factory()->create();
    chargeAt($user, '2024-09-10 12:00:00');   // before October 2024: pruned (24 months before October 2026)
    chargeAt($user, '2024-10-10 12:00:00');   // kept
    chargeAt($user, '2026-10-10 12:00:00');   // kept
    $conversation = conversationAt('2024-01-01');

    $this->artisan('ada:retention:prune')->assertSuccessful();

    expect(UsageEvent::query()->count())->toBe(2)
        ->and(BudgetReservation::query()->count())->toBe(2)
        ->and(BudgetPeriod::query()->orderBy('period_start')->pluck('period_start')->map->toDateString()->all())->toBe(['2024-09-30', '2026-09-30'])
        // Conversations follow their own setting (none here: kept).
        ->and(Conversation::query()->whereKey($conversation->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'retention.pruned')->sole()->new_values)->toMatchArray(['usage_events' => 1, 'reservations' => 1, 'periods' => 1]);
});

test('a dry run only counts', function () {
    conversationAt('2026-08-01', deleted: '2026-08-01');

    $this->artisan('ada:retention:prune', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(Conversation::withTrashed()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('pruning is scheduled nightly', function () {
    $events = collect(app(Scheduler::class)->events())->map->command->filter()->implode(' ');

    expect($events)->toContain('ada:retention:prune');
});

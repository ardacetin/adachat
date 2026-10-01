<?php

namespace App\Domain\Retention;

use App\Domain\Audit\AuditLogger;
use App\Domain\Budget\Enums\ReservationStatus;
use App\Domain\Budget\Services\PeriodCalculator;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Domain\Institution\Settings\PrivacySettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes what the retention settings no longer allow to keep
 * (database-design.md §7). Conversation content and the usage ledger are
 * separate: pruning conversations never touches usage records, and the
 * ledger is pruned in whole months so reports stay complete.
 */
final class RetentionPruner
{
    private const CHUNK = 500;

    public function __construct(
        private readonly PrivacySettings $privacy,
        private readonly InstitutionSettings $institution,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{deleted_conversations: int, expired_conversations: int, usage_events: int, reservations: int, periods: int, institution_periods: int}
     */
    public function prune(bool $dryRun = false, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $counts = [
            // Deleted by their owner (soft-deleted) a while ago.
            'deleted_conversations' => $this->deleteConversations(
                DB::table('conversations')->whereNotNull('deleted_at')->where('deleted_at', '<', $now->subDays($this->privacy->deleted_conversation_days)),
                $dryRun,
            ),
            'expired_conversations' => 0,
            'usage_events' => 0,
            'reservations' => 0,
            'periods' => 0,
            'institution_periods' => 0,
        ];

        if ($this->privacy->conversation_retention_days !== null) {
            $cutoff = $now->subDays($this->privacy->conversation_retention_days);

            $counts['expired_conversations'] = $this->deleteConversations(
                DB::table('conversations')->where('last_message_at', '<', $cutoff),
                $dryRun,
            );
        }

        // Whole months in the institution's time zone: keep the current month
        // and the configured number of months before it.
        [$currentMonth] = PeriodCalculator::monthContaining($now, $this->institution->timezone);
        $usageCutoff = $currentMonth->setTimezone($this->institution->timezone)->subMonthsNoOverflow($this->privacy->usage_retention_months)->utc();

        $events = DB::table('usage_events')->where('created_at', '<', $usageCutoff);
        $reservations = DB::table('budget_reservations')
            ->where('created_at', '<', $usageCutoff)
            ->where('status', '!=', ReservationStatus::Active->value);
        $periods = DB::table('budget_periods')->where('period_end', '<=', $usageCutoff);
        $institutionPeriods = DB::table('institution_periods')->where('period_end', '<=', $usageCutoff);

        if ($dryRun) {
            $counts['usage_events'] = $events->count();
            $counts['reservations'] = $reservations->count();
            $counts['periods'] = $periods->count();
            $counts['institution_periods'] = $institutionPeriods->count();
        } else {
            // Children first: events reference reservations and periods.
            $counts['usage_events'] = $this->deleteInChunks($events);
            $counts['reservations'] = $this->deleteInChunks($reservations->whereNotExists(fn ($query) => $query
                ->from('usage_events')->whereColumn('usage_events.reservation_id', 'budget_reservations.id')));
            $counts['periods'] = $this->deleteInChunks($periods
                ->whereNotExists(fn ($query) => $query->from('usage_events')->whereColumn('usage_events.budget_period_id', 'budget_periods.id'))
                ->whereNotExists(fn ($query) => $query->from('budget_reservations')->whereColumn('budget_reservations.budget_period_id', 'budget_periods.id')));
            $counts['institution_periods'] = $this->deleteInChunks($institutionPeriods);
        }

        if (! $dryRun && array_sum($counts) > 0) {
            $this->audit->record('retention.pruned', null, [], [...$counts, 'usage_before' => $usageCutoff->toIso8601String()]);
        }

        return $counts;
    }

    /**
     * Messages go with their conversation (foreign key cascade).
     */
    private function deleteConversations(Builder $query, bool $dryRun): int
    {
        return $dryRun ? $query->count() : $this->deleteInChunks($query);
    }

    private function deleteInChunks(Builder $query): int
    {
        $deleted = 0;

        do {
            $batch = (clone $query)->limit(self::CHUNK)->pluck('id');
            $table = $query->from;

            if ($batch->isEmpty() || ! is_string($table)) {
                break;
            }

            $deleted += DB::table($table)->whereIn('id', $batch->all())->delete();
        } while ($batch->count() === self::CHUNK);

        return $deleted;
    }
}

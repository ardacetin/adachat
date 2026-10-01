<?php

namespace App\Domain\Retention;

use App\Domain\Attachments\AttachmentStore;
use App\Domain\Audit\AuditLogger;
use App\Domain\Budget\Enums\ReservationStatus;
use App\Domain\Budget\Services\PeriodCalculator;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Domain\Institution\Settings\PrivacySettings;
use App\Models\MessageAttachment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes what the retention settings no longer allow to keep
 * (database-design.md §7). Conversation content and the usage ledger are
 * separate: pruning conversations never touches usage records, and the
 * ledger is pruned in whole months so reports stay complete.
 *
 * Attachment files go with their conversation. The database cascade
 * removes their rows when a user or conversation is deleted by other means;
 * the files left behind are found by the orphan sweep.
 */
final class RetentionPruner
{
    private const CHUNK = 500;

    public function __construct(
        private readonly PrivacySettings $privacy,
        private readonly InstitutionSettings $institution,
        private readonly AuditLogger $audit,
        private readonly AttachmentStore $attachments,
    ) {}

    /**
     * @return array{deleted_conversations: int, expired_conversations: int, unsent_attachments: int, orphan_files: int, usage_events: int, reservations: int, periods: int, institution_periods: int}
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
            'unsent_attachments' => 0,
            'orphan_files' => 0,
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

        // Uploads never sent with a message.
        $unsent = MessageAttachment::query()->pending()
            ->where('created_at', '<', $now->subHours((int) config('ada.attachments.pending_hours')));
        $counts['unsent_attachments'] = $dryRun ? $unsent->count() : $this->deleteAttachments($unsent);
        $counts['orphan_files'] = $this->sweepOrphanFiles($dryRun, $now);

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
     * Messages and attachment rows go with their conversation (foreign key
     * cascade); the attachment files are deleted first.
     */
    private function deleteConversations(Builder $query, bool $dryRun): int
    {
        return $dryRun ? $query->count() : $this->deleteInChunks($query, function (array $ids): void {
            $this->attachments->deleteFiles(MessageAttachment::query()
                ->whereIn('message_id', fn ($messages) => $messages->select('id')->from('messages')->whereIn('conversation_id', $ids)));
        });
    }

    /**
     * @param  EloquentBuilder<MessageAttachment>  $query
     */
    private function deleteAttachments(EloquentBuilder $query): int
    {
        $this->attachments->deleteFiles(clone $query);

        return $query->delete();
    }

    /**
     * Files under attachments/ without a row (left by a database cascade or
     * an interrupted upload). Files younger than an hour are left alone: an
     * upload writes the file just before its row.
     */
    private function sweepOrphanFiles(bool $dryRun, CarbonImmutable $now): int
    {
        $disk = AttachmentStore::disk();
        $count = 0;

        foreach (array_chunk($disk->allFiles(AttachmentStore::DIRECTORY), self::CHUNK) as $paths) {
            $known = MessageAttachment::query()->whereIn('path', $paths)->pluck('path')->all();
            $orphans = array_filter(
                array_diff($paths, $known),
                fn (string $path) => $disk->lastModified($path) < $now->subHour()->getTimestamp(),
            );

            if (! $dryRun) {
                $disk->delete(array_values($orphans));
            }

            $count += count($orphans);
        }

        return $count;
    }

    /**
     * @param  (callable(array<mixed>): void)|null  $beforeDelete
     */
    private function deleteInChunks(Builder $query, ?callable $beforeDelete = null): int
    {
        $deleted = 0;

        do {
            $batch = (clone $query)->limit(self::CHUNK)->pluck('id');
            $table = $query->from;

            if ($batch->isEmpty() || ! is_string($table)) {
                break;
            }

            if ($beforeDelete !== null) {
                $beforeDelete($batch->all());
            }

            $deleted += DB::table($table)->whereIn('id', $batch->all())->delete();
        } while ($batch->count() === self::CHUNK);

        return $deleted;
    }
}

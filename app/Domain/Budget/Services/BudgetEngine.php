<?php

namespace App\Domain\Budget\Services;

use App\Domain\AI\Data\InputTokenCount;
use App\Domain\Audit\AuditLogger;
use App\Domain\Budget\Data\Settlement;
use App\Domain\Budget\Enums\ReservationStatus;
use App\Domain\Budget\Exceptions\BudgetExhausted;
use App\Domain\Budget\Exceptions\TooManyConcurrentRequests;
use App\Domain\Budget\Money\Usd;
use App\Domain\Usage\CostCalculator;
use App\Domain\Usage\Enums\UsageEventStatus;
use App\Domain\Usage\Enums\UsageEventType;
use App\Domain\Usage\Pricing\PricingSnapshot;
use App\Models\AiModel;
use App\Models\BudgetPeriod;
use App\Models\BudgetReservation;
use App\Models\UsageEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * Hard budget enforcement:  available = limit − spent − reserved.
 *
 * Every change to a user's money happens in a short transaction holding a
 * row lock on the user's budget_periods row (SELECT … FOR UPDATE). Locks
 * are always taken in the order budget_periods → budget_reservations, no
 * lock is held during network calls, and deadlocks are retried.
 */
final class BudgetEngine
{
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly BudgetPeriods $periods,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Hold money for one request. The input must already be counted (the
     * provider call happens before, outside any transaction).
     *
     * @param  int  $maxOutputTokens  the alias/model output cap; may be lowered to fit the budget
     *
     * @throws BudgetExhausted
     * @throws TooManyConcurrentRequests
     */
    public function reserve(User $user, AiModel $model, InputTokenCount $input, int $maxOutputTokens, ?CarbonImmutable $now = null): BudgetReservation
    {
        $now ??= CarbonImmutable::now();
        $period = $this->periods->current($user, $now);
        $maxConcurrent = $user->group->max_concurrent_streams;

        return $this->locked(function () use ($user, $model, $input, $maxOutputTokens, $now, $period, $maxConcurrent): BudgetReservation {
            $period = $this->lockPeriod($period->id);

            // Evaluated under the period lock, so parallel requests of the
            // same user are serialized here.
            $active = BudgetReservation::query()
                ->where('user_id', $user->id)
                ->where('status', ReservationStatus::Active)
                ->count();

            if ($active >= $maxConcurrent) {
                throw new TooManyConcurrentRequests("{$active} requests are already running.");
            }

            $size = ReservationSizer::fit($input, $model, $maxOutputTokens, $period->available());

            $period->reserved_usd = $period->reserved_usd->plus($size->amount);
            $period->save();

            $reservation = new BudgetReservation;
            $reservation->forceFill([
                'budget_period_id' => $period->id,
                'user_id' => $user->id,
                'ai_model_id' => $model->id,
                'amount_usd' => $size->amount,
                'input_tokens' => $input->reservedTokens(),
                'input_count_method' => $input->method,
                'input_safety_margin' => (string) $input->margin(),
                'max_output_tokens' => $size->maxOutputTokens,
                'status' => ReservationStatus::Active,
                'expires_at' => $now->addSeconds(
                    (int) config('ada.providers.timeout', 300) + (int) config('ada.budget.reservation_grace_seconds', 120),
                ),
            ])->save();

            return $reservation;
        });
    }

    /**
     * Charge what the request actually consumed. Idempotent: settling a
     * reservation twice returns the first usage event. The charge goes to
     * the reservation's period even if the stream crossed into a new month.
     */
    public function settle(BudgetReservation|string $reservation, Settlement $settlement): UsageEvent
    {
        $id = $reservation instanceof BudgetReservation ? $reservation->id : $reservation;
        $periodId = BudgetReservation::query()->whereKey($id)->value('budget_period_id');

        if (! is_numeric($periodId)) {
            throw new InvalidArgumentException("Unknown reservation {$id}.");
        }

        $periodId = (int) $periodId;

        return $this->locked(function () use ($id, $periodId, $settlement): UsageEvent {
            $period = $this->lockPeriod($periodId);
            $reservation = $this->lockReservation($id);

            if ($reservation->status === ReservationStatus::Settled) {
                return UsageEvent::query()->where('reservation_id', $id)->firstOrFail();
            }

            if ($reservation->status === ReservationStatus::Released) {
                throw new LogicException("Reservation {$id} was released; nothing can be charged.");
            }

            $model = $reservation->aiModel;
            $usage = $settlement->usage;
            $pricing = PricingSnapshot::forModel($model, $usage->totalInput());
            $cost = CostCalculator::calculate($usage, $pricing);
            $total = $cost->total();

            // An expired reservation was already removed from reserved_usd
            // by the cleanup job; a late settlement only adds the charge.
            if ($reservation->status === ReservationStatus::Active) {
                $period->reserved_usd = $period->reserved_usd->minus($reservation->amount_usd);
            }

            $period->spent_usd = $period->spent_usd->plus($total);
            $period->save();

            if ($total->isGreaterThan($reservation->amount_usd)) {
                // Accounting never lies: the full cost is recorded. The margin
                // for this provider may need to be raised.
                Log::warning('Budget overshoot: billed cost exceeds the reservation.', [
                    'reservation_id' => $id,
                    'ai_model_id' => $model->id,
                    'input_count_method' => $reservation->input_count_method->value,
                    'reserved_usd' => $reservation->amount_usd->toString(),
                    'charged_usd' => $total->toString(),
                    'reserved_input_tokens' => $reservation->input_tokens,
                    'billed_input_tokens' => $usage->totalInput(),
                ]);
            }

            $reservation->forceFill([
                'status' => ReservationStatus::Settled,
                'status_reason' => $settlement->reason ?? $reservation->status_reason,
                'settled_amount_usd' => $total,
                'settled_at' => CarbonImmutable::now(),
            ])->save();

            $event = new UsageEvent;
            $event->forceFill([
                'type' => UsageEventType::Charge,
                'user_id' => $reservation->user_id,
                'group_id' => $reservation->user->group_id,
                'budget_period_id' => $period->id,
                'reservation_id' => $reservation->id,
                'conversation_id' => $settlement->conversationId,
                'message_id' => $settlement->messageId,
                'provider_id' => $model->provider_id,
                'ai_model_id' => $model->id,
                'model_alias_id' => $settlement->modelAliasId,
                'source' => 'chat',
                'input_tokens' => $usage->input,
                'cached_input_tokens' => $usage->cachedInput,
                'cache_write_tokens' => $usage->cacheWrite,
                'output_tokens' => $usage->output,
                'reasoning_tokens' => $usage->reasoning,
                'input_price_snapshot' => (string) $pricing->input,
                'cached_input_price_snapshot' => (string) $pricing->cachedInput,
                'cache_write_price_snapshot' => (string) $pricing->cacheWrite,
                'output_price_snapshot' => (string) $pricing->output,
                'input_cost_usd' => $cost->input,
                'output_cost_usd' => $cost->output,
                'other_cost_usd' => $cost->other,
                'total_cost_usd' => $total,
                'is_estimated' => $settlement->isEstimated,
                'input_count_method' => $reservation->input_count_method,
                'reserved_input_tokens' => $reservation->input_tokens,
                'provider_request_id' => $settlement->providerRequestId,
                'status' => $settlement->status,
            ])->save();

            return $event;
        });
    }

    /**
     * Return the money of a request that consumed nothing billable (e.g. the
     * provider refused it before generating). Idempotent.
     */
    public function release(BudgetReservation|string $reservation, string $reason): void
    {
        $this->close($reservation, ReservationStatus::Released, $reason);
    }

    /**
     * Free a reservation whose request never finished (process killed).
     * Idempotent; a later settlement is still accepted.
     */
    public function expire(BudgetReservation|string $reservation): void
    {
        $this->close($reservation, ReservationStatus::Expired, 'expired');
    }

    /**
     * Expire active reservations past their deadline.
     *
     * @return int number of reservations expired
     */
    public function expireStale(?CarbonImmutable $now = null, int $batch = 500): int
    {
        $ids = BudgetReservation::query()
            ->where('status', ReservationStatus::Active)
            ->where('expires_at', '<', $now ?? CarbonImmutable::now())
            ->orderBy('expires_at')
            ->limit($batch)
            ->pluck('id');

        foreach ($ids as $id) {
            $this->expire($id);
        }

        return $ids->count();
    }

    /**
     * Manual correction of a user's current period by a super admin: a
     * positive amount charges, a negative amount credits. Recorded as an
     * adjustment usage event and in the audit log.
     */
    public function adjust(User $user, Usd $amount, string $reason, User $actor, ?CarbonImmutable $now = null): UsageEvent
    {
        if ($amount->isZero() || trim($reason) === '') {
            throw new InvalidArgumentException('An adjustment needs a non-zero amount and a reason.');
        }

        $period = $this->periods->current($user, $now);

        $event = $this->locked(function () use ($user, $amount, $reason, $actor, $period): UsageEvent {
            $period = $this->lockPeriod($period->id);
            $spent = $period->spent_usd->plus($amount);

            if ($spent->isNegative()) {
                throw new InvalidArgumentException('A credit cannot exceed what was spent in the period.');
            }

            $period->spent_usd = $spent;
            $period->save();

            $event = new UsageEvent;
            $event->forceFill([
                'type' => UsageEventType::Adjustment,
                'user_id' => $user->id,
                'group_id' => $user->group_id,
                'budget_period_id' => $period->id,
                'source' => 'admin',
                'total_cost_usd' => $amount,
                'status' => UsageEventStatus::Completed,
                'reason' => mb_substr(trim($reason), 0, 255),
                'created_by' => $actor->id,
            ])->save();

            return $event;
        });

        $this->audit->record('budget.adjusted', $user, [], [
            'amount_usd' => $amount->toString(),
            'reason' => $event->reason,
            'usage_event_id' => $event->id,
        ]);

        return $event;
    }

    private function close(BudgetReservation|string $reservation, ReservationStatus $status, string $reason): void
    {
        $id = $reservation instanceof BudgetReservation ? $reservation->id : $reservation;
        $periodId = BudgetReservation::query()->whereKey($id)->value('budget_period_id');

        if (! is_numeric($periodId)) {
            throw new InvalidArgumentException("Unknown reservation {$id}.");
        }

        $periodId = (int) $periodId;

        $this->locked(function () use ($id, $periodId, $status, $reason): void {
            $period = $this->lockPeriod($periodId);
            $reservation = $this->lockReservation($id);

            if ($reservation->status !== ReservationStatus::Active) {
                return;
            }

            $period->reserved_usd = $period->reserved_usd->minus($reservation->amount_usd);
            $period->save();

            $reservation->forceFill(['status' => $status, 'status_reason' => $reason])->save();
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function locked(Closure $callback): mixed
    {
        // Bounded lock waits for budget transactions; deadlocks are retried.
        DB::statement('SET SESSION innodb_lock_wait_timeout = '.(int) config('ada.budget.lock_wait_timeout', 5));

        return DB::transaction($callback, self::ATTEMPTS);
    }

    private function lockPeriod(int $id): BudgetPeriod
    {
        return BudgetPeriod::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function lockReservation(string $id): BudgetReservation
    {
        return BudgetReservation::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }
}

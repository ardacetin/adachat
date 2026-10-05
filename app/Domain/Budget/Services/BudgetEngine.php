<?php

namespace App\Domain\Budget\Services;

use App\Domain\AI\Data\InputTokenCount;
use App\Domain\AI\Data\TokenUsage;
use App\Domain\Audit\AuditLogger;
use App\Domain\Budget\Data\Settlement;
use App\Domain\Budget\Enums\ReservationStatus;
use App\Domain\Budget\Exceptions\BudgetExhausted;
use App\Domain\Budget\Exceptions\InstitutionBudgetExhausted;
use App\Domain\Budget\Exceptions\TooManyConcurrentRequests;
use App\Domain\Budget\Money\Usd;
use App\Domain\Usage\CostCalculator;
use App\Domain\Usage\Data\Cost;
use App\Domain\Usage\Enums\UsageEventStatus;
use App\Domain\Usage\Enums\UsageEventType;
use App\Domain\Usage\Pricing\PricingSnapshot;
use App\Models\AiModel;
use App\Models\BudgetPeriod;
use App\Models\BudgetReservation;
use App\Models\InstitutionPeriod;
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
 * row lock on the user's budget_periods row (SELECT … FOR UPDATE). The
 * institution's month (institution_periods) is kept in the same
 * transactions, so an institution-wide cap holds as strictly as a user's
 * limit. Locks are always taken in the order budget_periods →
 * institution_periods → budget_reservations, no lock is held during
 * network calls, and deadlocks are retried.
 */
final class BudgetEngine
{
    private const ATTEMPTS = 3;

    /** Status reason of a reservation the cleanup job settled with an estimate. */
    public const INTERRUPTED = 'generation_interrupted';

    /** Status reason once the still-running request charged the rest. */
    private const SETTLED_LATE = 'settled_late';

    public function __construct(
        private readonly BudgetPeriods $periods,
        private readonly InstitutionPeriods $institutionPeriods,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Hold money for one request. The input must already be counted (the
     * provider call happens before, outside any transaction).
     *
     * @param  int  $maxOutputTokens  the alias/model output cap; may be lowered to fit the budget
     * @param  int  $webSearches  web searches the provider may run for the request
     *
     * @throws BudgetExhausted
     * @throws InstitutionBudgetExhausted when the institution cap, not the user's budget, is the limit
     * @throws TooManyConcurrentRequests
     */
    public function reserve(User $user, AiModel $model, InputTokenCount $input, int $maxOutputTokens, ?CarbonImmutable $now = null, int $webSearches = 0): BudgetReservation
    {
        $now ??= CarbonImmutable::now();
        $period = $this->periods->current($user, $now);
        $institution = $this->institutionPeriods->forMonth($period->period_start, $period->period_end);
        $cap = $this->institutionPeriods->cap();
        $maxConcurrent = $user->group->max_concurrent_streams;

        return $this->locked(function () use ($user, $model, $input, $maxOutputTokens, $now, $period, $institution, $cap, $maxConcurrent, $webSearches): BudgetReservation {
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

            $institution = $this->lockInstitution($institution->id);

            // The smaller of what the user and the institution have left.
            $available = $period->available();
            $institutionAvailable = $cap === null ? null : $institution->available($cap);
            $institutionLimits = false;

            if ($institutionAvailable !== null && $institutionAvailable->isLessThan($available)) {
                $available = $institutionAvailable;
                $institutionLimits = true;
            }

            try {
                $size = ReservationSizer::fit($input, $model, $maxOutputTokens, $available, $webSearches);
            } catch (BudgetExhausted $exhausted) {
                throw $institutionLimits
                    ? new InstitutionBudgetExhausted('The institution\'s monthly cap is reached.', previous: $exhausted)
                    : $exhausted;
            }

            $period->reserved_usd = $period->reserved_usd->plus($size->amount);
            $period->save();

            $institution->reserved_usd = $institution->reserved_usd->plus($size->amount);
            $institution->save();

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
            $institution = $this->lockInstitutionOf($period);
            $reservation = $this->lockReservation($id);

            if ($reservation->status === ReservationStatus::Settled) {
                $first = UsageEvent::query()->where('reservation_id', $id)->firstOrFail();

                // The cleanup job settled a request it took for dead with an
                // estimate, but the request was still running and now reports
                // what it really consumed: charge what the estimate missed.
                if ($reservation->status_reason === self::INTERRUPTED && $settlement->reason !== self::INTERRUPTED) {
                    $this->chargeRemainder($period, $institution, $reservation, $first, $settlement);
                }

                return $first;
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

            if ($institution !== null) {
                if ($reservation->status === ReservationStatus::Active) {
                    $institution->reserved_usd = $institution->reserved_usd->minus($reservation->amount_usd)->max(Usd::zero());
                }

                $institution->spent_usd = $institution->spent_usd->plus($total);
                $institution->save();
            }

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

            return $this->recordCharge($reservation, $settlement, $usage, $pricing, $cost, $period->id, $reservation->id);
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
        $this->institutionPeriods->forMonth($period->period_start, $period->period_end);

        $event = $this->locked(function () use ($user, $amount, $reason, $actor, $period): UsageEvent {
            $period = $this->lockPeriod($period->id);
            $spent = $period->spent_usd->plus($amount);

            if ($spent->isNegative()) {
                throw new InvalidArgumentException('A credit cannot exceed what was spent in the period.');
            }

            $period->spent_usd = $spent;
            $period->save();

            $institution = $this->lockInstitutionOf($period);

            if ($institution !== null) {
                $institution->spent_usd = $institution->spent_usd->plus($amount)->max(Usd::zero());
                $institution->save();
            }

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
            $institution = $this->lockInstitutionOf($period);
            $reservation = $this->lockReservation($id);

            if ($reservation->status !== ReservationStatus::Active) {
                return;
            }

            $period->reserved_usd = $period->reserved_usd->minus($reservation->amount_usd);
            $period->save();

            if ($institution !== null) {
                $institution->reserved_usd = $institution->reserved_usd->minus($reservation->amount_usd)->max(Usd::zero());
                $institution->save();
            }

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

    private function lockInstitution(int $id): InstitutionPeriod
    {
        return InstitutionPeriod::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * The institution month of a user period. It exists for every period
     * created since the table was introduced (reserve creates it, the
     * migration backfilled older ones); null only if it was removed.
     */
    private function lockInstitutionOf(BudgetPeriod $period): ?InstitutionPeriod
    {
        return InstitutionPeriod::query()->where('period_start', $period->period_start)->lockForUpdate()->first();
    }

    /**
     * A late settlement after the cleanup job's estimate: the tokens and
     * cost above what the estimate charged, as a second charge event (the
     * ledger is append-only, and the first event keeps the reservation).
     * Nothing is refunded when the estimate was higher. Charged once.
     */
    private function chargeRemainder(BudgetPeriod $period, ?InstitutionPeriod $institution, BudgetReservation $reservation, UsageEvent $first, Settlement $settlement): void
    {
        $live = $settlement->usage;
        $remainder = new TokenUsage(
            input: max(0, $live->input - $first->input_tokens),
            cachedInput: max(0, $live->cachedInput - $first->cached_input_tokens),
            cacheWrite: max(0, $live->cacheWrite - $first->cache_write_tokens),
            output: max(0, $live->output - $first->output_tokens),
            reasoning: max(0, $live->reasoning - $first->reasoning_tokens),
            webSearches: max(0, $live->webSearches - $first->web_search_requests),
        );

        $reservation->forceFill(['status_reason' => self::SETTLED_LATE])->save();

        if ($remainder->totalInput() + $remainder->totalOutput() + $remainder->webSearches === 0) {
            return;
        }

        $pricing = PricingSnapshot::forModel($reservation->aiModel, $live->totalInput());
        $cost = CostCalculator::calculate($remainder, $pricing);

        $period->spent_usd = $period->spent_usd->plus($cost->total());
        $period->save();

        if ($institution !== null) {
            $institution->spent_usd = $institution->spent_usd->plus($cost->total());
            $institution->save();
        }

        $this->recordCharge($reservation, $settlement, $remainder, $pricing, $cost, $period->id, null, self::SETTLED_LATE);
    }

    private function recordCharge(BudgetReservation $reservation, Settlement $settlement, TokenUsage $usage, PricingSnapshot $pricing, Cost $cost, int $periodId, ?string $reservationId, ?string $reason = null): UsageEvent
    {
        $model = $reservation->aiModel;

        $event = new UsageEvent;
        $event->forceFill([
            'type' => UsageEventType::Charge,
            'user_id' => $reservation->user_id,
            'group_id' => $reservation->user->group_id,
            'budget_period_id' => $periodId,
            'reservation_id' => $reservationId,
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
            'web_search_requests' => $usage->webSearches,
            'input_price_snapshot' => (string) $pricing->input,
            'cached_input_price_snapshot' => (string) $pricing->cachedInput,
            'cache_write_price_snapshot' => (string) $pricing->cacheWrite,
            'output_price_snapshot' => (string) $pricing->output,
            'web_search_price_snapshot' => $usage->webSearches > 0 && $pricing->webSearch !== null ? (string) $pricing->webSearch : null,
            'input_cost_usd' => $cost->input,
            'output_cost_usd' => $cost->output,
            'other_cost_usd' => $cost->other,
            'total_cost_usd' => $cost->total(),
            'is_estimated' => $settlement->isEstimated,
            'input_count_method' => $reservation->input_count_method,
            // Only the reservation's own event compares counted with billed input.
            'reserved_input_tokens' => $reservationId === null ? 0 : $reservation->input_tokens,
            'provider_request_id' => $settlement->providerRequestId,
            'status' => $settlement->status,
            'reason' => $reason,
        ])->save();

        return $event;
    }

    private function lockReservation(string $id): BudgetReservation
    {
        return BudgetReservation::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }
}

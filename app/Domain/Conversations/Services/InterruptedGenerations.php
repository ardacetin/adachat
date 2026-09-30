<?php

namespace App\Domain\Conversations\Services;

use App\Domain\AI\Data\TokenUsage;
use App\Domain\Budget\Data\Settlement;
use App\Domain\Budget\Enums\ReservationStatus;
use App\Domain\Budget\Services\BudgetEngine;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Usage\Enums\UsageEventStatus;
use App\Models\BudgetReservation;
use App\Models\Message;
use Carbon\CarbonImmutable;

/**
 * Cleans up after a PHP process that died while streaming (OOM, deploy,
 * restart): its reservation passed the deadline without being settled.
 * Text that reached the database was generated, so it is charged with an
 * estimate; otherwise the reservation simply expires (budget-engine.md §11).
 */
final class InterruptedGenerations
{
    public function __construct(private readonly BudgetEngine $budget) {}

    /**
     * @return int reservations resolved
     */
    public function resolve(?CarbonImmutable $now = null, int $batch = 500): int
    {
        $reservations = BudgetReservation::query()
            ->where('status', ReservationStatus::Active)
            ->where('expires_at', '<', $now ?? CarbonImmutable::now())
            ->orderBy('expires_at')
            ->limit($batch)
            ->get();

        foreach ($reservations as $reservation) {
            $message = Message::query()
                ->where('reservation_id', $reservation->id)
                ->where('status', MessageStatus::Streaming)
                ->first();

            if ($message !== null && $message->content !== '') {
                $this->budget->settle($reservation, new Settlement(
                    usage: new TokenUsage(
                        input: $reservation->input_tokens,
                        output: min((int) ceil(strlen($message->content) / 2), $reservation->max_output_tokens),
                    ),
                    status: UsageEventStatus::Partial,
                    isEstimated: true,
                    reason: 'generation_interrupted',
                    modelAliasId: $message->model_alias_id,
                    conversationId: $message->conversation_id,
                    messageId: $message->id,
                ));
            } else {
                $this->budget->expire($reservation);
            }

            $message?->forceFill(['status' => MessageStatus::Failed, 'error_code' => 'generation_interrupted'])->save();
        }

        return $reservations->count();
    }
}

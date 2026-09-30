<?php

namespace App\Domain\Budget\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Budget periods are calendar months in the institution's time zone,
 * stored as half-open UTC intervals [start, end).
 */
final class PeriodCalculator
{
    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC start and end
     */
    public static function monthContaining(DateTimeInterface $moment, string $timezone): array
    {
        $local = CarbonImmutable::instance($moment)->setTimezone($timezone);
        $start = $local->startOfMonth();

        return [
            $start->utc(),
            $start->addMonthNoOverflow()->utc(),
        ];
    }
}

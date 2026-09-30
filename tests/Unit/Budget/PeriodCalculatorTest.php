<?php

use App\Domain\Budget\Services\PeriodCalculator;
use Carbon\CarbonImmutable;

test('periods are calendar months in the institution time zone', function (string $moment, string $tz, string $start, string $end) {
    [$from, $to] = PeriodCalculator::monthContaining(CarbonImmutable::parse($moment, 'UTC'), $tz);

    expect($from->toDateTimeString())->toBe($start)
        ->and($to->toDateTimeString())->toBe($end)
        ->and($from->getTimezone()->getName())->toBe('UTC');
})->with([
    // 1 October 00:00 in Istanbul is 30 September 21:00 UTC.
    'istanbul, first minute' => ['2026-09-30 21:00:00', 'Europe/Istanbul', '2026-09-30 21:00:00', '2026-10-31 21:00:00'],
    'istanbul, last second of september' => ['2026-09-30 20:59:59', 'Europe/Istanbul', '2026-08-31 21:00:00', '2026-09-30 21:00:00'],
    'utc' => ['2026-02-15 12:00:00', 'UTC', '2026-02-01 00:00:00', '2026-03-01 00:00:00'],
    // Daylight saving changes inside the month.
    'new york, march' => ['2026-03-20 12:00:00', 'America/New_York', '2026-03-01 05:00:00', '2026-04-01 04:00:00'],
    'december to january' => ['2026-12-31 23:00:00', 'Europe/Berlin', '2026-12-31 23:00:00', '2027-01-31 23:00:00'],
]);

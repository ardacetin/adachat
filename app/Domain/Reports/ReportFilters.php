<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;

/**
 * What a report covers: a range of calendar days in the institution's time
 * zone (both ends included) and optional dimensions.
 */
final readonly class ReportFilters
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $timezone,
        public ?int $userId = null,
        public ?int $groupId = null,
        public ?int $providerId = null,
        public ?int $aiModelId = null,
    ) {}

    /** Start of the first day, in UTC. */
    public function startUtc(): CarbonImmutable
    {
        return $this->from->setTimezone($this->timezone)->startOfDay()->utc();
    }

    /** Start of the day after the last one, in UTC (exclusive). */
    public function endUtc(): CarbonImmutable
    {
        return $this->to->setTimezone($this->timezone)->startOfDay()->addDay()->utc();
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'user_id' => $this->userId,
            'group_id' => $this->groupId,
            'provider_id' => $this->providerId,
            'ai_model_id' => $this->aiModelId,
        ];
    }
}

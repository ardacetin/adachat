<?php

namespace App\Domain\Reports;

use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Services\PeriodCalculator;
use App\Domain\Usage\Enums\UsageEventType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * Spending and usage for the admin dashboard and reports, straight from the
 * usage_events ledger (charges only; admin adjustments are reported
 * separately). Days and months are those of the institution's time zone:
 * the database aggregates in 15-minute UTC buckets (fine enough for every
 * real UTC offset) and the buckets are assigned to local days here, so no
 * MySQL time zone tables are needed.
 *
 * Decision (roadmap M9): raw queries over the indexed ledger are fast
 * enough at university scale (docs/architecture.md §8). A daily aggregate
 * table would only be added when reports become slow.
 */
final class UsageStatistics
{
    private const BUCKET_SECONDS = 900;

    public const TOP_ROWS = 100;

    /** @var list<string> */
    public const DIMENSIONS = ['user', 'group', 'provider', 'model'];

    /**
     * @return array{requests: int, users: int, input_tokens: int, output_tokens: int, cost_usd: string, estimated: int, adjustments_usd: string}
     */
    public function totals(ReportFilters $filters): array
    {
        $row = $this->charges($filters)
            ->selectRaw('COUNT(*) AS requests, COUNT(DISTINCT e.user_id) AS users, COALESCE(SUM(e.input_tokens + e.cached_input_tokens + e.cache_write_tokens), 0) AS input_tokens, COALESCE(SUM(e.output_tokens), 0) AS output_tokens, COALESCE(SUM(e.total_cost_usd), 0) AS cost, COALESCE(SUM(e.is_estimated), 0) AS estimated')
            ->first();

        $adjustments = $this->events($filters)
            ->where('e.type', UsageEventType::Adjustment->value)
            ->sum('e.total_cost_usd');

        return [
            'requests' => (int) ($row->requests ?? 0),
            'users' => (int) ($row->users ?? 0),
            'input_tokens' => (int) ($row->input_tokens ?? 0),
            'output_tokens' => (int) ($row->output_tokens ?? 0),
            'cost_usd' => self::money($row->cost ?? '0'),
            'estimated' => (int) ($row->estimated ?? 0),
            'adjustments_usd' => self::money($adjustments),
        ];
    }

    /**
     * The biggest spenders by user, group, provider or model.
     *
     * @return list<array{id: int|null, label: string, detail: string|null, requests: int, input_tokens: int, output_tokens: int, cost_usd: string}>
     */
    public function breakdown(ReportFilters $filters, string $dimension): array
    {
        $query = $this->charges($filters);

        [$key, $label, $detail] = match ($dimension) {
            'user' => (function () use ($query) {
                $query->leftJoin('users as d', 'd.id', '=', 'e.user_id');

                return ['e.user_id', 'd.name', 'd.email'];
            })(),
            'group' => (function () use ($query) {
                $query->leftJoin('groups as d', 'd.id', '=', 'e.group_id');

                return ['e.group_id', 'd.name', 'NULL'];
            })(),
            'provider' => (function () use ($query) {
                $query->leftJoin('providers as d', 'd.id', '=', 'e.provider_id');

                return ['e.provider_id', 'd.name', 'd.driver'];
            })(),
            'model' => (function () use ($query) {
                $query->leftJoin('ai_models as d', 'd.id', '=', 'e.ai_model_id')
                    ->leftJoin('providers as p', 'p.id', '=', 'd.provider_id');

                return ['e.ai_model_id', 'd.display_name', 'p.name'];
            })(),
            default => throw new InvalidArgumentException("Unknown dimension {$dimension}."),
        };

        return array_values($query
            ->selectRaw("{$key} AS id, MAX({$label}) AS label, MAX({$detail}) AS detail, COUNT(*) AS requests, SUM(e.input_tokens + e.cached_input_tokens + e.cache_write_tokens) AS input_tokens, SUM(e.output_tokens) AS output_tokens, SUM(e.total_cost_usd) AS cost")
            ->groupBy($key)
            ->orderByDesc('cost')
            ->orderBy($key)
            ->limit(self::TOP_ROWS)
            ->get()
            ->map(fn (stdClass $row) => [
                'id' => $row->id === null ? null : (int) $row->id,
                'label' => is_string($row->label) ? $row->label : '—',
                'detail' => is_string($row->detail) ? $row->detail : null,
                'requests' => (int) $row->requests,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'cost_usd' => self::money($row->cost),
            ])
            ->all());
    }

    /**
     * Spending per local day (every day of the range, zeros included) or per
     * local month.
     *
     * @return list<array{date: string, requests: int, cost_usd: string}>
     */
    public function timeline(ReportFilters $filters, string $interval = 'day'): array
    {
        $format = $interval === 'month' ? 'Y-m' : 'Y-m-d';
        $slots = [];

        foreach (CarbonPeriod::create($filters->from->toDateString(), $filters->to->toDateString()) as $day) {
            $slots[$day->format($format)] ??= ['requests' => 0, 'cost' => Usd::zero()];
        }

        $buckets = $this->charges($filters)
            ->selectRaw('FLOOR(UNIX_TIMESTAMP(e.created_at) / ?) AS bucket, COUNT(*) AS requests, SUM(e.total_cost_usd) AS cost', [self::BUCKET_SECONDS])
            ->groupBy('bucket')
            ->get();

        foreach ($buckets as $bucket) {
            $slot = CarbonImmutable::createFromTimestampUTC((int) $bucket->bucket * self::BUCKET_SECONDS)
                ->setTimezone($filters->timezone)
                ->format($format);

            $slots[$slot] ??= ['requests' => 0, 'cost' => Usd::zero()];
            $slots[$slot]['requests'] += (int) $bucket->requests;
            $slots[$slot]['cost'] = $slots[$slot]['cost']->plus(Usd::of((string) $bucket->cost));
        }

        ksort($slots);

        return array_map(
            fn (string $date, array $slot) => ['date' => $date, 'requests' => $slot['requests'], 'cost_usd' => self::money($slot['cost'])],
            array_keys($slots),
            $slots,
        );
    }

    /**
     * Headline figures for this month so far, with last month for comparison.
     *
     * @return array<string, mixed>
     */
    public function kpis(string $timezone, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        [$start] = PeriodCalculator::monthContaining($now, $timezone);
        $local = $start->setTimezone($timezone);

        $current = $this->totals(new ReportFilters($local, $now->setTimezone($timezone), $timezone));
        $previous = $this->totals(new ReportFilters($local->subMonthNoOverflow(), $local->subDay(), $timezone));

        $atLimit = DB::table('budget_periods')
            ->where('period_start', $start)
            ->whereColumn('spent_usd', '>=', 'limit_usd')
            ->count();

        return [
            'month' => $local->format('Y-m'),
            'cost_usd' => $current['cost_usd'],
            'previous_cost_usd' => $previous['cost_usd'],
            'requests' => $current['requests'],
            'previous_requests' => $previous['requests'],
            'active_users' => $current['users'],
            'previous_active_users' => $previous['users'],
            'average_per_user_usd' => $current['users'] === 0
                ? '0.00'
                : (string) BigDecimal::of($current['cost_usd'])->dividedBy($current['users'], 2, RoundingMode::HalfUp),
            'users_at_limit' => $atLimit,
        ];
    }

    /**
     * Charges above their reservation (budget-engine.md §5.6): the counter
     * or the fallback estimate was too low.
     *
     * @return array{count: int, excess_usd: string, latest: list<array<string, mixed>>}
     */
    public function overshoots(ReportFilters $filters): array
    {
        $query = fn () => $this->charges($filters)
            ->join('budget_reservations as r', 'r.id', '=', 'e.reservation_id')
            ->whereColumn('e.total_cost_usd', '>', 'r.amount_usd');

        $summary = $query()->selectRaw('COUNT(*) AS n, COALESCE(SUM(e.total_cost_usd - r.amount_usd), 0) AS excess')->first();

        $latest = $query()
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('ai_models as m', 'm.id', '=', 'e.ai_model_id')
            ->select(['e.created_at', 'u.email', 'm.display_name', 'e.input_count_method', 'r.amount_usd', 'e.total_cost_usd', 'e.input_tokens', 'e.cached_input_tokens', 'e.cache_write_tokens', 'e.reserved_input_tokens'])
            ->orderByDesc('e.created_at')
            ->limit(10)
            ->get()
            ->map(fn (stdClass $row) => [
                'created_at' => CarbonImmutable::parse((string) $row->created_at, 'UTC')->toIso8601String(),
                'user' => $row->email,
                'model' => $row->display_name,
                'input_count_method' => $row->input_count_method,
                'reserved_usd' => self::money($row->amount_usd, 4),
                'charged_usd' => self::money($row->total_cost_usd, 4),
                'reserved_input_tokens' => $row->reserved_input_tokens === null ? null : (int) $row->reserved_input_tokens,
                'billed_input_tokens' => (int) $row->input_tokens + (int) $row->cached_input_tokens + (int) $row->cache_write_tokens,
            ])
            ->values()
            ->all();

        return [
            'count' => (int) ($summary->n ?? 0),
            'excess_usd' => self::money($summary->excess ?? '0', 4),
            'latest' => array_values($latest),
        ];
    }

    /**
     * Billed input tokens against the counted (reserved, margin included)
     * input per provider, model and counting method, to tune the margins
     * (budget-engine.md §5.2). Estimated usage is left out.
     *
     * @return list<array<string, mixed>>
     */
    public function counterDeviation(ReportFilters $filters): array
    {
        $billed = 'e.input_tokens + e.cached_input_tokens + e.cache_write_tokens';

        return array_values($this->charges($filters)
            ->leftJoin('ai_models as m', 'm.id', '=', 'e.ai_model_id')
            ->leftJoin('providers as p', 'p.id', '=', 'e.provider_id')
            ->where('e.is_estimated', false)
            ->where('e.reserved_input_tokens', '>', 0)
            ->selectRaw("MAX(p.name) AS provider, MAX(m.display_name) AS model, e.input_count_method AS method, COUNT(*) AS requests, SUM({$billed}) AS billed, SUM(e.reserved_input_tokens) AS reserved, MAX(({$billed}) / e.reserved_input_tokens) AS worst, SUM(CASE WHEN ({$billed}) > e.reserved_input_tokens THEN 1 ELSE 0 END) AS above")
            ->groupBy('e.ai_model_id', 'e.input_count_method')
            ->orderByDesc('worst')
            ->limit(50)
            ->get()
            ->map(fn (stdClass $row) => [
                'provider' => $row->provider,
                'model' => $row->model,
                'method' => $row->method,
                'requests' => (int) $row->requests,
                'billed_input_tokens' => (int) $row->billed,
                'reserved_input_tokens' => (int) $row->reserved,
                // Billed relative to counted: -2.5 means 2.5 % fewer tokens were billed.
                'deviation_percent' => (int) $row->reserved === 0 ? 0.0 : round(((int) $row->billed / (int) $row->reserved - 1) * 100, 1),
                'worst_percent' => round(((float) $row->worst - 1) * 100, 1),
                'above_count' => (int) $row->above,
            ])
            ->all());
    }

    private function events(ReportFilters $filters): Builder
    {
        return DB::table('usage_events as e')
            ->where('e.created_at', '>=', $filters->startUtc())
            ->where('e.created_at', '<', $filters->endUtc())
            ->when($filters->userId !== null, fn (Builder $query) => $query->where('e.user_id', $filters->userId))
            ->when($filters->groupId !== null, fn (Builder $query) => $query->where('e.group_id', $filters->groupId))
            ->when($filters->providerId !== null, fn (Builder $query) => $query->where('e.provider_id', $filters->providerId))
            ->when($filters->aiModelId !== null, fn (Builder $query) => $query->where('e.ai_model_id', $filters->aiModelId));
    }

    private function charges(ReportFilters $filters): Builder
    {
        return $this->events($filters)->where('e.type', UsageEventType::Charge->value);
    }

    /**
     * @param  int<0, max>  $scale
     */
    private static function money(mixed $value, int $scale = 2): string
    {
        $usd = $value instanceof Usd ? $value : Usd::of(is_numeric($value) ? (string) $value : '0');

        return (string) $usd->amount->toScale($scale, RoundingMode::HalfUp);
    }
}

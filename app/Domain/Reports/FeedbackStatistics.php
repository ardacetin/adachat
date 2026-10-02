<?php

namespace App\Domain\Reports;

use App\Models\MessageFeedback;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * How satisfied users are, by model alias, model or assistant: counts of
 * thumbs up and down and the reasons given. Built from message_feedback
 * only, which holds no users and no text, so nothing here leads back to a
 * conversation.
 */
final class FeedbackStatistics
{
    /** @var list<string> */
    public const DIMENSIONS = ['alias', 'model', 'assistant'];

    /** Rows with fewer votes show no rate: too few to mean anything. */
    public const MIN_VOTES = 5;

    /**
     * @return array{up: int, down: int, reasons: array<string, int>}
     */
    public function totals(ReportFilters $filters): array
    {
        $row = $this->votes($filters)
            ->selectRaw("COALESCE(SUM(f.rating = 'up'), 0) AS up, COALESCE(SUM(f.rating = 'down'), 0) AS down")
            ->first();

        return [
            'up' => (int) ($row->up ?? 0),
            'down' => (int) ($row->down ?? 0),
            'reasons' => $this->reasons($filters),
        ];
    }

    /**
     * @return list<array{id: int|null, label: string, up: int, down: int, rate: int|null, reasons: array<string, int>}>
     */
    public function breakdown(ReportFilters $filters, string $dimension): array
    {
        [$key, $table, $label] = match ($dimension) {
            'alias' => ['f.model_alias_id', 'model_aliases', 'd.name'],
            'model' => ['f.ai_model_id', 'ai_models', 'd.display_name'],
            'assistant' => ['f.assistant_id', 'assistants', 'd.name'],
            default => throw new InvalidArgumentException("Unknown dimension {$dimension}."),
        };

        $rows = $this->votes($filters)
            ->leftJoin("{$table} as d", 'd.id', '=', $key)
            ->when($dimension === 'assistant', fn ($query) => $query->whereNotNull('f.assistant_id'))
            ->selectRaw("{$key} AS id, ANY_VALUE({$label}) AS label, SUM(f.rating = 'up') AS up, SUM(f.rating = 'down') AS down")
            ->groupBy($key)
            ->orderByRaw('COUNT(*) DESC')
            ->limit(100)
            ->get();

        $reasons = $this->votes($filters)
            ->where('f.rating', 'down')
            ->whereNotNull('f.reason')
            ->selectRaw("{$key} AS id, f.reason, COUNT(*) AS n")
            ->groupBy($key, 'f.reason')
            ->get()
            ->groupBy(fn (stdClass $row) => (string) $row->id);

        $locale = app()->getLocale();

        return array_values($rows->map(function (stdClass $row) use ($reasons, $dimension, $locale): array {
            $up = (int) $row->up;
            $down = (int) $row->down;
            $label = is_string($row->label) && $dimension !== 'model' ? $this->localized($row->label, $locale) : $row->label;

            return [
                'id' => $row->id === null ? null : (int) $row->id,
                'label' => is_string($label) && $label !== '' ? $label : '—',
                'up' => $up,
                'down' => $down,
                'rate' => $up + $down >= self::MIN_VOTES ? (int) round($up * 100 / ($up + $down)) : null,
                'reasons' => ($reasons->get((string) $row->id) ?? collect())
                    ->mapWithKeys(fn (stdClass $reason) => [(string) $reason->reason => (int) $reason->n])
                    ->all(),
            ];
        })->all());
    }

    /**
     * @return array<string, int>
     */
    private function reasons(ReportFilters $filters): array
    {
        $counts = $this->votes($filters)
            ->where('f.rating', 'down')
            ->whereNotNull('f.reason')
            ->selectRaw('f.reason, COUNT(*) AS n')
            ->groupBy('f.reason')
            ->pluck('n', 'reason');

        return collect(MessageFeedback::REASONS)
            ->mapWithKeys(fn (string $reason) => [$reason => (int) ($counts[$reason] ?? 0)])
            ->all();
    }

    private function votes(ReportFilters $filters): Builder
    {
        return DB::table('message_feedback as f')
            ->where('f.created_at', '>=', $filters->startUtc())
            ->where('f.created_at', '<', $filters->endUtc());
    }

    /**
     * A JSON name ({"en": …, "tr": …}) in the given language, else in English.
     */
    private function localized(string $json, string $locale): ?string
    {
        $names = json_decode($json, true);

        if (! is_array($names)) {
            return null;
        }

        $name = $names[$locale] ?? $names['en'] ?? null;

        return is_string($name) ? $name : null;
    }
}

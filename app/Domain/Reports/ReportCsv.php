<?php

namespace App\Domain\Reports;

/**
 * Report data as CSV that spreadsheets open correctly: UTF-8 with a byte
 * order mark (Turkish characters in Excel), and in Turkish the separator
 * and decimal mark Turkish Excel expects (";" and ","); in English "," and
 * ".". Dates are ISO (YYYY-MM-DD). Never contains message content.
 */
final class ReportCsv
{
    private const BOM = "\u{FEFF}";

    public function __construct(private readonly UsageStatistics $statistics) {}

    /**
     * Every row of a breakdown, not only the top rows shown on the page.
     *
     * @param  resource  $out
     */
    public function breakdown($out, ReportFilters $filters, string $dimension, string $locale): void
    {
        $headers = [__("admin.report_csv.{$dimension}", [], $locale)];

        if ($dimension === 'user') {
            $headers[] = __('admin.report_csv.email', [], $locale);
        } elseif ($dimension !== 'group') {
            $headers[] = __('admin.report_csv.detail_'.$dimension, [], $locale);
        }

        $rows = array_map(function (array $row) use ($dimension, $locale): array {
            $cells = [$row['label']];

            if ($dimension !== 'group') {
                $cells[] = $row['detail'] ?? '';
            }

            return [...$cells, $row['requests'], $row['input_tokens'], $row['output_tokens'], $row['web_searches'], self::number($row['cost_usd'], $locale)];
        }, $this->statistics->breakdown($filters, $dimension, limit: null));

        $this->write($out, [...$headers, ...$this->amountHeaders($locale)], $rows, $locale);
    }

    /**
     * Spending per local day or month.
     *
     * @param  resource  $out
     */
    public function timeline($out, ReportFilters $filters, string $interval, string $locale): void
    {
        $rows = array_map(
            fn (array $point) => [$point['date'], $point['requests'], self::number($point['cost_usd'], $locale)],
            $this->statistics->timeline($filters, $interval),
        );

        $this->write($out, [
            __('admin.report_csv.'.$interval, [], $locale),
            __('admin.report_csv.requests', [], $locale),
            __('admin.report_csv.cost', [], $locale),
        ], $rows, $locale);
    }

    /**
     * The same as a string, e.g. for an e-mail attachment.
     *
     * @param  callable(resource): void  $writer
     */
    public static function toString(callable $writer): string
    {
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            return '';
        }

        $writer($stream);
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    public static function separator(string $locale): string
    {
        return $locale === 'tr' ? ';' : ',';
    }

    /**
     * @return list<string>
     */
    private function amountHeaders(string $locale): array
    {
        return [
            __('admin.report_csv.requests', [], $locale),
            __('admin.report_csv.input_tokens', [], $locale),
            __('admin.report_csv.output_tokens', [], $locale),
            __('admin.report_csv.web_searches', [], $locale),
            __('admin.report_csv.cost', [], $locale),
        ];
    }

    /**
     * @param  resource  $out
     * @param  list<string>  $headers
     * @param  list<list<int|string>>  $rows
     */
    private function write($out, array $headers, array $rows, string $locale): void
    {
        $separator = self::separator($locale);
        fwrite($out, self::BOM);
        fputcsv($out, $headers, $separator, escape: '');

        foreach ($rows as $row) {
            // Cells starting with = + - @ are read as formulas by spreadsheets.
            fputcsv($out, array_map(self::defuse(...), $row), $separator, escape: '');
        }
    }

    private static function defuse(int|string $cell): int|string
    {
        return is_string($cell) && $cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'".$cell
            : $cell;
    }

    /** A dot-decimal amount in the locale's decimal mark. */
    private static function number(string $amount, string $locale): string
    {
        return $locale === 'tr' ? str_replace('.', ',', $amount) : $amount;
    }
}

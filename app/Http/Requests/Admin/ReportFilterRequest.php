<?php

namespace App\Http\Requests\Admin;

use App\Domain\Institution\Settings\InstitutionSettings;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\UsageStatistics;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The report filters, shared by the reports page and the CSV export: a range
 * of local days (this month by default, at most a year) and optional
 * dimensions.
 */
class ReportFilterRequest extends FormRequest
{
    public const MAX_DAYS = 366;

    public function authorize(): bool
    {
        return $this->user()?->can('access-admin') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'user_id' => ['nullable', 'integer'],
            'group_id' => ['nullable', 'integer'],
            'provider_id' => ['nullable', 'integer'],
            'ai_model_id' => ['nullable', 'integer'],
            'by' => ['nullable', Rule::in(UsageStatistics::DIMENSIONS)],
            'interval' => ['nullable', Rule::in(['day', 'month'])],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$from, $to] = $this->range();

            if ($to->lessThan($from)) {
                $validator->errors()->add('to', __('admin.report_range_order'));
            } elseif ($from->diffInDays($to) >= self::MAX_DAYS) {
                $validator->errors()->add('from', __('admin.report_range_too_long', ['days' => self::MAX_DAYS]));
            }
        }];
    }

    public function filters(): ReportFilters
    {
        [$from, $to] = $this->range();

        return new ReportFilters(
            $from,
            $to,
            $this->timezone(),
            userId: $this->optionalId('user_id'),
            groupId: $this->optionalId('group_id'),
            providerId: $this->optionalId('provider_id'),
            aiModelId: $this->optionalId('ai_model_id'),
        );
    }

    public function dimension(): string
    {
        $by = $this->validated('by');

        return is_string($by) ? $by : 'model';
    }

    public function timelineInterval(): string
    {
        $interval = $this->validated('interval');

        if (is_string($interval)) {
            return $interval;
        }

        [$from, $to] = $this->range();

        // Long ranges read better per month.
        return $from->diffInDays($to) > 62 ? 'month' : 'day';
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(): array
    {
        $timezone = $this->timezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $from = $this->input('from');
        $to = $this->input('to');

        return [
            is_string($from) && $from !== '' ? CarbonImmutable::parse($from, $timezone) : $today->startOfMonth(),
            is_string($to) && $to !== '' ? CarbonImmutable::parse($to, $timezone) : $today,
        ];
    }

    private function timezone(): string
    {
        return app(InstitutionSettings::class)->timezone;
    }

    private function optionalId(string $key): ?int
    {
        $value = $this->validated($key);

        return is_numeric($value) ? (int) $value : null;
    }
}

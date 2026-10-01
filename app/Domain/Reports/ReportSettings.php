<?php

namespace App\Domain\Reports;

use Spatie\LaravelSettings\Settings;

class ReportSettings extends Settings
{
    /** The last month (YYYY-MM) the monthly report was sent for. */
    public ?string $last_monthly_sent;

    public static function group(): string
    {
        return 'reports';
    }
}

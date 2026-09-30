<?php

use Illuminate\Support\Facades\Schedule;

// Budget correctness does not depend on these jobs running on time: expired
// reservations only hold money longer, and reconciliation only reports.
Schedule::command('ada:budget:expire-reservations')->everyMinute()->withoutOverlapping();
Schedule::command('ada:budget:reconcile')->dailyAt('03:17');

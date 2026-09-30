<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Budget correctness does not depend on these jobs running on time: expired
// reservations only hold money longer, and reconciliation only reports.
Schedule::command('ada:budget:expire-reservations')->everyMinute()->withoutOverlapping();
Schedule::command('ada:budget:reconcile')->dailyAt('03:17');
Schedule::command('ada:retention:prune')->dailyAt('03:41')->withoutOverlapping();

// Lets `ada:doctor` tell whether the scheduler runs at all.
Schedule::call(fn () => Cache::forever('ada:scheduler:heartbeat', now()->toIso8601String()))->everyMinute()->name('ada:scheduler-heartbeat');

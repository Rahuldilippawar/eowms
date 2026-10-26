<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hostinger cron runs `php artisan schedule:run` every minute (see erp/docs/WORK-AND-TASKS.md).
Schedule::command('erp:task-reminders')->hourly()->withoutOverlapping();
// Daily attendance jobs; each is idempotent, so the hourly runs only fill gaps.
Schedule::command('erp:mark-absent')->hourly()->withoutOverlapping();
Schedule::command('erp:attendance-alerts')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('erp:celebrations')->hourly()->withoutOverlapping();
// Correspondence: files that have not moved (09:00 India time).
Schedule::command('erp:letter-reminders')->dailyAt('03:30')->withoutOverlapping();
// Renewals: 08:00 India time.
Schedule::command('erp:renewal-reminders')->dailyAt('02:30')->withoutOverlapping();

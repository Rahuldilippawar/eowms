<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hostinger cron runs `php artisan schedule:run` every minute (see erp/docs/WORK-AND-TASKS.md).
Schedule::command('erp:task-reminders')->hourly()->withoutOverlapping();

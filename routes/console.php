<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('taskflow:send-task-reminders')->daily();
Schedule::command('taskflow:send-daily-briefing')->dailyAt('08:00');
Schedule::command('taskflow:send-evening-checkin')->dailyAt('20:00');
Schedule::command('miriam:run-automations')->everyFifteenMinutes();
Schedule::command('miriam:sync-google-calendar')->hourly()->when(fn () => (bool) config('services.google_calendar.enabled'));
Schedule::command('miriam:send-medication-reminders')->everyMinute();

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('payments:sync-converge')
    ->hourly()
    ->withoutOverlapping()
    ->when(fn () => (bool) config('services.converge.payment_sync_enabled'));

$outlookInterval = max(1, (int) config('services.outlook.sync_interval_minutes', 15));

Schedule::command('outlook:sync-consultations')
    ->cron('*/'.$outlookInterval.' * * * *')
    ->withoutOverlapping()
    ->when(fn () => (bool) config('services.outlook.enabled'));

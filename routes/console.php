<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$interval = max(1, (int) config('services.converge.payment_sync_interval_minutes', 15));

Schedule::command('payments:sync-converge')
    ->cron('*/'.$interval.' * * * *')
    ->withoutOverlapping()
    ->when(fn () => (bool) config('services.converge.payment_sync_enabled'));

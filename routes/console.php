<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();


Schedule::command('conversations:remarketing')
    ->cron('0 5 * * 1,3,5,7') // Lunes, Miércoles, Viernes, Domingo a las 5:00 AM
    ->timezone('America/Bogota')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/conversations_remarketing.log'));

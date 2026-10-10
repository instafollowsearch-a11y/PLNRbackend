<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:send-reminders')->daily();
Schedule::command('itineraries:send-stop-reminders')->hourly();
Schedule::command('weekends:send-pick-reminders')->hourly();
Schedule::command('events:sync')->daily();
Schedule::command('interests:scan')->weeklyOn(2, '10:15');
Schedule::command('interests:scan')->weeklyOn(4, '10:15');
Schedule::command('weekends:send')->weeklyOn(5, '9:00');

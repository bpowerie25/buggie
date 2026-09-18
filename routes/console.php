<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Batched notifications go out once a group has been quiet for the digest delay.
Schedule::command('notifications:flush')->everyMinute()->withoutOverlapping();

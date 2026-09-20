<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Batched notifications go out once a group has been quiet for the digest delay.
Schedule::command('notifications:flush')->everyMinute()->withoutOverlapping();

// Ages out screenshots, reporter identities and dismissed reports. Runs everywhere,
// including self-hosted installs: keeping this data for ever is nobody's interest.
Schedule::command('buggie:prune')->dailyAt('03:20')->withoutOverlapping();

// --- due-date chasing ---------------------------------------------------------
// Once a day, early enough that the digest is waiting when the working day starts
// and late enough that it is the same UTC date everywhere it will be read. Safe to
// run again by hand: issues carry the date they were last chased, so a second run
// on the same day sends nothing.
Schedule::command('issues:chase-due')->dailyAt('06:40')->withoutOverlapping();
// --- end due-date chasing -----------------------------------------------------

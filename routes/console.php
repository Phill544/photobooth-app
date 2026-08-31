<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The retention sweep. Daily is often enough for a window measured in months,
// and the hour is a quiet one: an album's worth of files is a prefix delete per
// event, and nobody is shooting at 3am.
//
// onOneServer, because Laravel Cloud runs the scheduler on every replica of the
// cluster it is enabled on, and this command deletes photos — a scaled
// environment would otherwise sweep the same album from several instances at
// once. Nothing runs any of it without the Scheduler toggle on the App cluster:
// DEPLOY.md has that, and the redeploy it needs.
Schedule::command('photobooth:sweep-expired')->dailyAt('03:15')->onOneServer();

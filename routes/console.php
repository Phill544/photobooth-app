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

// And the download-all archives, each a second copy of a whole event, offered
// for a week (Archive::LIFETIME_DAYS) and then deleted.
Schedule::command('photobooth:sweep-archives')->dailyAt('03:30')->onOneServer();

// Housekeeping. Neither of these deletes anything a host or a guest would miss,
// but both hold personal information the app has no reason to keep: an
// abandoned reset row is keyed on the host's email address in the clear, and a
// failed job's exception is an unredacted stack trace — and a failed queued
// mailable carries the recipient's address into it. Left alone they are the two
// tables that grow forever, which is not a retention period anybody can write
// into a privacy policy.
//
// A week of failed jobs is long enough to notice one and read it. onOneServer
// for the same reason as the sweeps above.
Schedule::command('auth:clear-resets')->dailyAt('03:45')->onOneServer();
Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:50')->onOneServer();

<?php

use Illuminate\Console\Scheduling\Schedule;

// Two tables that grow forever and hold personal information nobody asked us to
// keep. `password_reset_tokens` is keyed on the host's own email address in the
// clear, and a row is only ever deleted by a reset somebody actually finished —
// an abandoned one sits there for good. `failed_jobs.exception` is an unredacted
// stack trace, and the jobs that fail here are the mail ones, so a recipient's
// address is exactly what ends up in it.
//
// The privacy policy has to state a real retention period for both, which it
// cannot do while the answer is "forever". Scheduled beside the two photo
// sweeps, in the same quiet hour, and on one server for the same reason they
// are: Laravel Cloud runs the scheduler on every replica.

it('prunes abandoned password reset tokens', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'auth:clear-resets'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->onOneServer)->toBeTrue();
});

it('prunes failed jobs and the stack traces they carry', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'queue:prune-failed'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->onOneServer)->toBeTrue();
});

// The window a host is told about in the policy. A failed mail job is worth
// keeping long enough to notice and read, and no longer.
it('keeps failed jobs for a stated number of days', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'queue:prune-failed'));

    expect($event->command)->toContain('--hours=168');
});

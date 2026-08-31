<?php

use App\Models\Event;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

// Photos get taken while the booth is live and the window runs out afterwards,
// which is also the only order the app allows: an expired event refuses uploads.
function expiredEvent(string $code, int $daysAgo): Event
{
    $event = Event::create(['name' => "Party $code", 'code' => $code]);
    uploadPhoto($code);
    $event->update(['photos_expire_at' => now()->subDays($daysAgo)]);

    return $event->refresh();
}

it('deletes the photos of an event past its window and its grace', function () {
    $event = expiredEvent('PARTY2', Event::PURGE_GRACE_DAYS + 1);
    $paths = Photo::get()->flatMap->paths();

    $this->artisan('photobooth:sweep-expired')->assertSuccessful();

    expect(Photo::count())->toBe(0)
        ->and(Event::whereCode('PARTY2')->exists())->toBeTrue();
    $paths->each(fn (string $path) => Storage::assertMissing($path));
    expect($event->name)->toBe('Party PARTY2');
});

// The grace is the whole reason a host can ask for more time after the date has
// passed. A sweep that ignored it would make the extension useless.
it('leaves an album alone while it is still inside the grace period', function () {
    expiredEvent('PARTY2', Event::PURGE_GRACE_DAYS - 1);

    $this->artisan('photobooth:sweep-expired')->assertSuccessful();

    expect(Photo::count())->toBe(1);
});

it('leaves an album with no window at all alone', function () {
    $event = Event::create(['name' => 'Forever', 'code' => 'NEVER2']);
    $event->update(['photos_expire_at' => null]);
    uploadPhoto('NEVER2');

    $this->artisan('photobooth:sweep-expired')->assertSuccessful();

    expect(Photo::count())->toBe(1);
});

it('leaves a live album alone', function () {
    Event::create(['name' => 'Tonight', 'code' => 'LIVE23']);
    uploadPhoto('LIVE23');

    $this->artisan('photobooth:sweep-expired')->assertSuccessful();

    expect(Photo::count())->toBe(1);
});

it('sweeps every album that is due, not just the first', function () {
    expiredEvent('PARTY2', Event::PURGE_GRACE_DAYS + 1);
    expiredEvent('OTHER2', Event::PURGE_GRACE_DAYS + 9);
    Event::create(['name' => 'Tonight', 'code' => 'LIVE23']);
    uploadPhoto('LIVE23');

    $this->artisan('photobooth:sweep-expired')->assertSuccessful();

    expect(Photo::count())->toBe(1)
        ->and(Photo::sole()->event->code)->toBe('LIVE23');
});

// It runs unattended on a schedule, so a second pass over an album it already
// emptied must not cost another object-store round trip every night forever.
it('does not pick up an album it has already swept', function () {
    expiredEvent('PARTY2', Event::PURGE_GRACE_DAYS + 1);

    $this->artisan('photobooth:sweep-expired')->expectsOutputToContain('PARTY2');
    $this->artisan('photobooth:sweep-expired')->doesntExpectOutputToContain('PARTY2');
});

it('says what it did, because nobody is watching it run', function () {
    expiredEvent('PARTY2', Event::PURGE_GRACE_DAYS + 1);

    $this->artisan('photobooth:sweep-expired')
        ->expectsOutputToContain('PARTY2')
        ->assertSuccessful();
});

// Laravel Cloud runs schedule:run on every replica, so a task that deletes
// photos has to be told to run on one of them. Without it a scaled environment
// sweeps the same album from several instances at once.
it('is scheduled to run on one server only', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'photobooth:sweep-expired'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->onOneServer)->toBeTrue();
});

it('reports an empty sweep as success', function () {
    $this->artisan('photobooth:sweep-expired')
        ->expectsOutputToContain('Nothing')
        ->assertSuccessful();
});

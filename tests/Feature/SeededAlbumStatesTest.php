<?php

use App\Models\Event;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;

// The demo seed is the fixture every gate pass and every album measurement runs
// against, so the states it produces are load-bearing. It is guarded to the local
// environment, which is why these tests borrow that environment to reach it.
beforeEach(function () {
    Storage::fake();
    $this->app['env'] = 'local';
    $this->seed(DatabaseSeeder::class);
});

// The bug this pins (2026-09-06): when the retention window moved to the first
// photo, the seeder started anchoring the window to `$openedAt` — and those are
// fixed literals in the past, because that is what makes a seeded album read
// like a night that already happened. Every fixture was handed a date that had
// already gone. NEWYRS opens on New Year's Eve 2025, so the 4000-photo album the
// paging work is measured against arrived expired *and* through its grace: an
// expired page for guests, and one `photobooth:sweep-expired` from deletion.
it('anchors a seeded window to the seed run, not to the night it depicts', function () {
    foreach (['BREKKY', 'GARDEN', 'SECRET'] as $code) {
        $event = Event::where('code', $code)->sole();

        expect($event->photos_expire_at->isSameDay(now()->addDays(Event::RETENTION_DAYS)))
            ->toBeTrue("$code was anchored to its night rather than to the seed");
    }
});

it('does not seed an album the sweep would delete', function () {
    $this->artisan('photobooth:sweep-expired')
        ->expectsOutputToContain('Nothing')
        ->assertSuccessful();
});

it('seeds live albums a guest can actually open', function () {
    foreach (['PARTY2', 'BREKKY', 'GARDEN', 'SECRET'] as $code) {
        expect(Event::where('code', $code)->sole()->hasExpired())->toBeFalse("$code arrived expired");
    }
});

// The two the seeder sets deliberately, because nothing else can produce them:
// an album past its date but still inside the grace period, and one whose photos
// have already gone.
it('still seeds the two end-of-life states', function () {
    $lapsed = Event::where('code', 'LAPSED')->sole();
    $swept = Event::where('code', 'SWEPT2')->sole();

    expect($lapsed->hasExpired())->toBeTrue()
        ->and($lapsed->photosWerePurged())->toBeFalse()
        ->and($swept->photosWerePurged())->toBeTrue();
});

// An empty booth is the one seeded event nobody has shot into, so it is also the
// only place the "waiting for its window" state shows up in the demo.
it('seeds an empty booth that is still waiting for its window', function () {
    $party = Event::where('code', 'PARTY2')->sole();

    expect($party->awaitingFirstPhoto())->toBeTrue()
        ->and($party->photos_expire_at)->toBeNull();
});

// SeedsAlbums::SHAPES admits in its own comment that it mirrors templates.ts,
// and nothing held it honest — a cell resize on the JS side left every seeded
// album the wrong size, silently, in the fixture the gate pass runs against.
// The sizes are literals because PHP holds no strip geometry (P5's shared JSON
// is the eventual fix); this pins the mirror, it does not derive it. `single`
// is absent because no demo event uses it.
it('seeds strips the size the JS templates really produce', function () {
    $sizes = ['BREKKY' => [1008, 3288], 'GARDEN' => [1008, 2544], 'SECRET' => [1992, 1800]];

    foreach ($sizes as $code => $expected) {
        $strip = imagecreatefromstring(Storage::get(seededStrip($code)->path));
        $size = [imagesx($strip), imagesy($strip)];
        imagedestroy($strip);

        expect($size)->toBe($expected, "$code was seeded at the wrong strip size");
    }
});

it('fills every cell of a seeded strip, rather than dropping a small photo in its corner', function () {
    // imagecopy does not resample: a source smaller than the cell lands at its
    // own size in the top-left and leaves mat colour where a photo should be,
    // which is what a cell resize that misses the seeder's shot() call looks
    // like. Read a pixel just inside the first cell's bottom-right corner.
    $strip = imagecreatefromstring(Storage::get(seededStrip('GARDEN')->path));

    $insideCell = imagecolorat($strip, 24 + 960 - 2, 24 + 720 - 2);
    $mat = imagecolorat($strip, 12, 12);
    imagedestroy($strip);

    expect($insideCell)->not->toBe($mat);
});

function seededStrip(string $code): \App\Models\Photo
{
    return Event::where('code', $code)->sole()->photos()->where('kind', 'strip')->first();
}

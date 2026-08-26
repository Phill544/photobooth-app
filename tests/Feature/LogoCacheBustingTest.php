<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// Images are served with a year of immutable caching, so a URL may never outlive
// its contents. A photo's URL carries its id and a photo file never changes — but
// the logo route is one stable URL per event and a host can replace the file, so
// that URL has to carry the file's fingerprint.

beforeEach(function () {
    Storage::fake();
    $this->owner = User::factory()->create();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'owner_id' => $this->owner->id]);
});

it('changes the logo url when the host replaces the file', function () {
    $this->event->update(['logo_path' => 'logos/first.png']);
    $before = $this->event->logoUrl();

    $this->event->update(['logo_path' => 'logos/second.png']);

    expect($this->event->logoUrl())->not->toBe($before);
});

it('points the booth at the new logo the moment it is replaced', function () {
    $this->actingAs($this->owner);
    $this->patch('/events/PARTY2', [
        'name' => 'Summer Party',
        'logo' => UploadedFile::fake()->image('first.png', 400, 200),
    ]);
    $first = $this->get('/e/PARTY2')->assertOk();

    $this->patch('/events/PARTY2', [
        'name' => 'Summer Party',
        'logo' => UploadedFile::fake()->image('second.png', 400, 200),
    ]);
    $second = $this->get('/e/PARTY2')->assertOk();

    // Same route, different URL — otherwise a phone shows last week's branding.
    $urlOf = fn ($response) => str($response->content())->after('class="event-logo" src="')->before('"')->value();
    expect($urlOf($second))->not->toBe($urlOf($first))
        ->and($urlOf($second))->toStartWith('/e/PARTY2/logo');
});

it('serves the logo whatever fingerprint the url carries', function () {
    $this->event->update(['logo_path' => 'logos/mark.png']);
    Storage::put('logos/mark.png', 'not-really-a-png');

    $this->get($this->event->logoUrl())->assertOk();
});

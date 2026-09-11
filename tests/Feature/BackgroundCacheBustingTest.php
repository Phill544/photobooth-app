<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// The same trap LogoCacheBustingTest guards, and worse here: a stale logo is a
// wrong mark in the footer, a stale background is the whole strip. Images are
// served with a year of immutable caching, so one stable URL per event has to
// carry the stored file's fingerprint or a phone keeps last week's artwork.

beforeEach(function () {
    Storage::fake();
    $this->owner = User::factory()->create();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'owner_id' => $this->owner->id]);
});

it('changes the background url when the host replaces the file', function () {
    $this->event->update(['background_path' => 'backgrounds/first.png']);
    $before = $this->event->backgroundUrl();

    $this->event->update(['background_path' => 'backgrounds/second.png']);

    expect($this->event->backgroundUrl())->not->toBe($before);
});

it('points the booth at the new background the moment it is replaced', function () {
    $this->actingAs($this->owner);
    $urlOf = fn ($response) => str($response->content())->after('data-background="')->before('"')->value();

    $this->patch('/events/PARTY2', [
        'name' => 'Summer Party',
        'background' => UploadedFile::fake()->image('first.png', 300, 700),
    ]);
    $first = $urlOf($this->get('/e/PARTY2')->assertOk());

    $this->patch('/events/PARTY2', [
        'name' => 'Summer Party',
        'background' => UploadedFile::fake()->image('second.png', 300, 700),
    ]);
    $second = $urlOf($this->get('/e/PARTY2')->assertOk());

    expect($second)->not->toBe($first)
        ->and($second)->toContain('/e/PARTY2/background');
});

it('serves the background whatever fingerprint the url carries', function () {
    $this->event->update(['background_path' => 'backgrounds/mat.png']);
    Storage::put('backgrounds/mat.png', 'not-really-a-png');

    $this->get($this->event->backgroundUrl())->assertOk();
});

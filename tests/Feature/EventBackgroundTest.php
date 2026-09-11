<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// The twin of EventLogoTest. A background is the same pipeline as a logo with
// two differences that matter: it is much larger, and it is rasterised into
// every strip of the night rather than sitting in the footer.

beforeEach(function () {
    Storage::fake();
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $this->event = Event::create(['name' => 'Acme Party', 'code' => 'PARTY2']);
});

it('stores a background uploaded when creating an event', function () {
    $this->post('/events', [
        'name' => 'Corporate Do',
        'background' => UploadedFile::fake()->image('mat.png', 1008, 2352),
    ]);

    $event = Event::where('name', 'Corporate Do')->sole();
    expect($event->background_path)->not->toBeNull();
    Storage::assertExists($event->background_path);
});

it('serves the event background', function () {
    $this->event->update(['background_path' => UploadedFile::fake()->image('m.png', 300, 700)->store('backgrounds')]);

    $this->get('/e/PARTY2/background')->assertOk();
});

it('404s when the event has no background', function () {
    $this->get('/e/PARTY2/background')->assertNotFound();
});

it('replaces the background on update and deletes the old file', function () {
    $old = UploadedFile::fake()->image('old.png', 300, 700)->store('backgrounds');
    $this->event->update(['background_path' => $old]);

    $this->patch('/events/PARTY2', [
        'name' => 'Acme Party',
        'background' => UploadedFile::fake()->image('new.png', 300, 700),
    ]);

    $event = $this->event->refresh();
    expect($event->background_path)->not->toBe($old);
    Storage::assertMissing($old);
    Storage::assertExists($event->background_path);
});

it('removes the background when asked', function () {
    $path = UploadedFile::fake()->image('m.png', 300, 700)->store('backgrounds');
    $this->event->update(['background_path' => $path]);

    $this->patch('/events/PARTY2', ['name' => 'Acme Party', 'remove_background' => '1']);

    expect($this->event->refresh()->background_path)->toBeNull();
    Storage::assertMissing($path);
});

it('keeps a logo and a background apart, so neither replaces the other', function () {
    $this->patch('/events/PARTY2', [
        'name' => 'Acme Party',
        'logo' => UploadedFile::fake()->image('logo.png', 400, 200),
        'background' => UploadedFile::fake()->image('mat.png', 300, 700),
    ]);

    $event = $this->event->refresh();
    expect($event->logo_path)->toStartWith('logos/')
        ->and($event->background_path)->toStartWith('backgrounds/');
});

it('rejects a non-image background', function () {
    $this->patch('/events/PARTY2', [
        'name' => 'Acme Party',
        'background' => UploadedFile::fake()->create('mat.pdf', 40, 'application/pdf'),
    ])->assertInvalid(['background']);
});

// A guest's phone decodes this to raw RGBA before it can be drawn, and that is
// four bytes a pixel however well the file compressed: a flat-gradient PNG of a
// couple of megabytes becomes 137 MiB at 4000x9000. The dimension rule is the
// real guard — Laravel reads it out of the header, not by decoding — and the
// byte cap only bounds the upload.
it('refuses a background too big for a phone to decode', function () {
    $this->patch('/events/PARTY2', [
        'name' => 'Acme Party',
        'background' => UploadedFile::fake()->image('huge.png', 4000, 4000),
    ])->assertInvalid(['background']);
});

it('accepts artwork at the largest size any template asks for', function () {
    // The grid is the widest strip (1992) and the tall strip the longest (3096).
    $this->patch('/events/PARTY2', [
        'name' => 'Acme Party',
        'background' => UploadedFile::fake()->image('wide.png', 1992, 1200),
    ])->assertValid();

    $this->patch('/events/PARTY2', [
        'name' => 'Acme Party',
        'background' => UploadedFile::fake()->image('tall.png', 1008, 3096),
    ])->assertValid();
});

// Event::purgePhotos() clears the events/{id} prefix wholesale, which is what
// makes the retention sweep a handful of calls rather than thousands. A host's
// artwork is their own, not a guest's photograph, so it must not live there.
it('keeps the background out of the prefix the retention sweep clears', function () {
    $this->patch('/events/PARTY2', [
        'name' => 'Acme Party',
        'background' => UploadedFile::fake()->image('mat.png', 300, 700),
    ]);

    expect($this->event->refresh()->background_path)->toStartWith('backgrounds/');
});

it('leaves the host background alone when the retention sweep takes the photos', function () {
    $path = UploadedFile::fake()->image('m.png', 300, 700)->store('backgrounds');
    $this->event->update(['background_path' => $path]);

    $this->event->purgePhotos();

    expect($this->event->refresh()->background_path)->toBe($path);
    Storage::assertExists($path);
});

it('deletes the background when the whole event goes', function () {
    $path = UploadedFile::fake()->image('m.png', 300, 700)->store('backgrounds');
    $this->event->update(['background_path' => $path]);

    $this->event->purge();

    Storage::assertMissing($path);
});

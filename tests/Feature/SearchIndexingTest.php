<?php

use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// An album is only as private as its code, so a crawler that finds one link
// publishes every strip at the event. The booth and the album stay out of the
// index; the join page is the one page meant to be found.

beforeEach(function () {
    Storage::fake();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
});

it('keeps the album out of search results', function () {
    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

it('keeps the booth out of search results', function () {
    $this->get('/e/PARTY2')
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

it('leaves the join page indexable so the app can be found', function () {
    $this->get('/')->assertOk()->assertDontSee('name="robots"', false);
});

// A meta tag cannot reach an image — a photo URL is indexable on its own, so
// the file responses carry the instruction as a header instead.
it('marks a served photo noindex', function () {
    $id = uploadPhoto('PARTY2')->json('id');

    $this->get("/e/PARTY2/photos/$id")->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
});

it('marks a served logo noindex', function () {
    $this->event->update(['logo_path' => UploadedFile::fake()->image('l.png', 200, 100)->store('logos')]);

    $this->get('/e/PARTY2/logo')->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
});

it('marks a served background noindex', function () {
    $this->event->update(['background_path' => UploadedFile::fake()->image('m.png', 300, 700)->store('backgrounds')]);

    $this->get('/e/PARTY2/background')->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
});

// The app now serves bytes a host chose, inline, to every guest at the event.
// Without this a browser is free to sniff a mislabelled file into something it
// will execute, on our own origin, where the album's session cookie lives.
it('refuses to let a browser sniff host artwork into something else', function () {
    $this->event->update(['background_path' => UploadedFile::fake()->image('m.png', 300, 700)->store('backgrounds')]);

    $this->get('/e/PARTY2/background')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('asks crawlers to stay out of the guest and host areas', function () {
    $robots = file_get_contents(public_path('robots.txt'));

    expect($robots)
        ->toContain('Disallow: /e/')
        ->toContain('Disallow: /join') // only ever redirects into /e/
        ->toContain('Disallow: /dashboard')
        ->toContain('Disallow: /new')
        ->toContain('Disallow: /events')
        ->toContain('Disallow: /login')
        ->toContain('Disallow: /register');
});

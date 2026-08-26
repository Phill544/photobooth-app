<?php

use App\Jobs\GenerateThumbnail;
use App\Models\Event;
use App\Models\Photo;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Stored images never change — a new upload writes a new random path — so they
// can be cached hard. But an album is only as private as its event code, so
// only the guest's own device may keep a copy.

beforeEach(function () {
    Storage::fake();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
    $this->photo = Photo::find(boothUpload('PARTY2')->json('id'));
    (new GenerateThumbnail($this->photo))->handle();
    $this->photo->refresh();
});

// The web group is a name until the pipeline expands it, so expand it here too.
function middlewareFor($route): array
{
    return collect(Route::gatherRouteMiddleware($route))
        ->flatMap(fn ($name) => Route::getMiddlewareGroups()[$name] ?? [$name])
        ->all();
}

function expectImmutablePrivate($response): void
{
    $cacheControl = $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('private')
        ->and($cacheControl)->toContain('max-age=31536000')
        ->and($cacheControl)->toContain('immutable')
        ->and($cacheControl)->not->toContain('public')
        ->and($response->headers->get('ETag'))->not->toBeEmpty();
}

it('lets the phone keep a photo for a year, and shared caches keep nothing', function () {
    expectImmutablePrivate($this->get("/e/PARTY2/photos/{$this->photo->id}")->assertOk());
});

it('answers 304 when the phone already has the photo', function () {
    $etag = $this->get("/e/PARTY2/photos/{$this->photo->id}")->headers->get('ETag');

    $this->get("/e/PARTY2/photos/{$this->photo->id}", ['If-None-Match' => $etag])
        ->assertStatus(304);
});

it('still serves the photo when the phone offers a stale etag', function () {
    $this->get("/e/PARTY2/photos/{$this->photo->id}", ['If-None-Match' => '"something-else"'])
        ->assertOk();
});

it('caches a thumbnail the same way, under its own etag', function () {
    $original = $this->get("/e/PARTY2/photos/{$this->photo->id}")->headers->get('ETag');
    $thumb = $this->get("/e/PARTY2/photos/{$this->photo->id}/thumb")->assertOk();

    expectImmutablePrivate($thumb);
    expect($thumb->headers->get('ETag'))->not->toBe($original);
});

it('serves the derivative bytes, not the original, from the thumb route', function () {
    $thumb = $this->get("/e/PARTY2/photos/{$this->photo->id}/thumb");

    expect(strlen($thumb->streamedContent()))->toBe(strlen(Storage::get($this->photo->thumb_path)));
});

it('404s a thumbnail the queue has not written yet', function () {
    $fresh = Photo::find(boothUpload('PARTY2', ['slot' => 2])->json('id'));
    $fresh->update(['thumb_path' => null]);

    $this->get("/e/PARTY2/photos/{$fresh->id}/thumb")->assertNotFound();
});

it('caches an event logo the same way', function () {
    $this->event->update(['logo_path' => 'logos/mark.png']);
    Storage::put('logos/mark.png', 'not-really-a-png');

    expectImmutablePrivate($this->get('/e/PARTY2/logo')->assertOk());
});

it('keeps telling crawlers not to index an image', function () {
    $this->get("/e/PARTY2/photos/{$this->photo->id}")->assertHeader('X-Robots-Tag', 'noindex');
    $this->get("/e/PARTY2/photos/{$this->photo->id}/thumb")->assertHeader('X-Robots-Tag', 'noindex');
});

it('serves images without starting a session, but still resolves bindings', function () {
    $imageRoutes = [
        'e/{event}/photos/{photo}',
        'e/{event}/photos/{photo}/thumb',
        'e/{event}/logo',
    ];

    foreach ($imageRoutes as $uri) {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === $uri);

        expect($route)->not->toBeNull("no route registered for $uri");
        expect(middlewareFor($route))->not->toContain(StartSession::class);
        expect(middlewareFor($route))->toContain(SubstituteBindings::class);
    }
});

it('sets no session cookie on an image, while a page still gets one', function () {
    // The suite's array driver never writes a cookie, so ask for a driver that
    // does — otherwise this test would pass on a route that starts a session.
    config(['session.driver' => 'cookie']);
    $cookie = config('session.cookie');

    $this->get('/e/PARTY2/gallery')->assertCookie($cookie);

    $this->get("/e/PARTY2/photos/{$this->photo->id}")->assertCookieMissing($cookie);
    $this->get("/e/PARTY2/photos/{$this->photo->id}/thumb")->assertCookieMissing($cookie);
});

it('leaves the pages that need a session alone', function () {
    $gallery = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'e/{event}/gallery');

    expect(middlewareFor($gallery))->toContain(StartSession::class);
});

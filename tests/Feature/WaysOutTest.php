<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

// The navigation audit found guest pages with no way onward at all — no link,
// no button, nothing but the browser's back arrow. A guest at a party is
// holding a phone they were handed a QR code for; the back arrow may be four
// taps into a scanner app. Every one of these pages now leads somewhere.

beforeEach(function () {
    Storage::fake();
});

function expiredButOpen(string $code = 'LAPSD2'): Event
{
    // The state that used to break: never closed, so the head's isClosed() gate
    // loaded capture.ts — but expired, so acceptsUploads() rendered a body with
    // none of its elements, and it threw before its error handlers registered.
    return Event::create([
        'name' => 'Winter Staff Party',
        'code' => $code,
        'photos_expire_at' => now()->subDay(),
    ]);
}

it('renders no booth at all for an event that has expired but was never closed', function () {
    expiredButOpen();

    $this->get('/e/LAPSD2')
        ->assertOk()
        ->assertSee('no longer kept')
        ->assertDontSee('id="start-screen"', false)
        ->assertDontSee('id="preview"', false);
});

it('offers the front door from a booth that is finished or shut', function () {
    expiredButOpen();
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'closed_at' => now()]);

    foreach (['LAPSD2', 'PARTY2'] as $code) {
        $this->get("/e/$code")->assertOk()->assertSee('Got another code?');
    }
});

it('offers the front door from an album whose night has passed', function () {
    expiredButOpen();

    $this->get('/e/LAPSD2/gallery')
        ->assertOk()
        ->assertSee('no longer available')
        ->assertSee('Got another code?');
});

// A hidden album is a wall, and the wall used to be the last thing a guest saw.
it('sends a guest back to the booth from a private or PIN-locked album', function () {
    Event::create(['name' => 'Marsh Wedding', 'code' => 'SECRT2', 'album_privacy' => 'hidden']);
    Event::create(['name' => 'Garden Party', 'code' => 'GARDN2', 'album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    $this->get('/e/SECRT2/gallery')->assertForbidden()->assertSee('Back to the booth');
    $this->get('/e/GARDN2/gallery')->assertOk()->assertSee('Back to the booth');
});

it('gives every guest auth page a way into a booth', function () {
    foreach (['/login', '/register', '/forgot-password'] as $path) {
        $this->get($path)->assertOk()->assertSee('Got an event code?');
    }
});

// D3: the wordmark is the way home. It used to point at /dashboard — a door a
// guest cannot open — on every page that had one.
it('sends the wordmark home from every page that carries it', function () {
    $host = User::factory()->create(['is_admin' => true]);
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'owner_id' => $host->id]);

    $this->get('/e/PARTY2/gallery')->assertOk()->assertSee('<a class="wordmark" href="/">', false);

    $this->actingAs($host);
    foreach (['/dashboard', '/new', '/events/PARTY2'] as $path) {
        $this->get($path)->assertOk()->assertSee('<a class="wordmark" href="/">', false);
    }
});

it('tells a signed-in host where their events are instead of offering a sign-in', function () {
    $this->get('/')->assertOk()->assertSee('Host sign in');

    $this->actingAs(User::factory()->create())
        ->get('/')->assertOk()->assertSee('Your events')->assertDontSee('Host sign in');
});

// A host standing in their own booth had no route back to their own controls
// except the dashboard, three taps away.
it('offers a host their own controls from the booth and the album', function () {
    $host = User::factory()->create();
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'owner_id' => $host->id]);

    $this->actingAs($host);
    $this->get('/e/PARTY2')->assertOk()->assertSee('Manage this event');
    $this->get('/e/PARTY2/gallery')->assertOk()->assertSee('Manage this event');
});

it('never shows a guest a door that would only answer 403', function () {
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'owner_id' => User::factory()->create()->id]);

    $this->get('/e/PARTY2')->assertOk()->assertDontSee('Manage this event');
    $this->get('/e/PARTY2/gallery')->assertOk()->assertDontSee('Manage this event');
});

// The owner page is the one page a host must not lose — everything it links to
// is somewhere they will want to come straight back from.
it('opens the booth and the album in a new tab from the owner page', function () {
    $host = User::factory()->create();
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'owner_id' => $host->id]);

    $this->actingAs($host)->get('/events/PARTY2')
        ->assertOk()
        ->assertSee('href="/e/PARTY2/gallery" target="_blank"', false)
        ->assertSee('href="/e/PARTY2" target="_blank"', false);
});

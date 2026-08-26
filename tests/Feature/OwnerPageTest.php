<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
});

it('shows the event code, a QR code, and booth links', function () {
    $this->get('/events/PARTY2')
        ->assertOk()
        ->assertSee('Summer Party')
        ->assertSee('>PARTY2<', false) // the join-code display, not just link URLs
        ->assertSee('<svg', false)
        ->assertSee('href="/e/PARTY2"', false) // the booth link specifically
        ->assertSee('href="/e/PARTY2/gallery"', false);
});

it('shows how many photos the event has', function () {
    Storage::fake();
    uploadPhoto('PARTY2', ['slot' => 1]);
    uploadPhoto('PARTY2', ['slot' => 2]);

    $this->get('/events/PARTY2')->assertSee('2 photos');
});

// "Photos" means the shots a guest took. The composed strip is a separate
// artifact with its own count, so it must not inflate the photo count.
it('counts the shots but not the composed strip', function () {
    Storage::fake();
    $group = fake()->uuid();
    uploadPhoto('PARTY2', ['group' => $group, 'kind' => 'strip', 'slot' => 0]);
    uploadPhoto('PARTY2', ['group' => $group, 'slot' => 1]);
    uploadPhoto('PARTY2', ['group' => $group, 'slot' => 2]);

    // Asserted on the stat's own spoken phrase: the page also carries the
    // template label "Classic strip · 3 photos", which is a different 3.
    $this->get('/events/PARTY2')
        ->assertSee('<span class="sr-only">2 photos</span>', false)
        ->assertSee('<span class="sr-only">1 strip</span>', false);
});

it('404s for an unknown event code', function () {
    $this->get('/events/XXXXXX')->assertNotFound();
});

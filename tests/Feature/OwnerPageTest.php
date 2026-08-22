<?php

use App\Models\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
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

it('404s for an unknown event code', function () {
    $this->get('/events/XXXXXX')->assertNotFound();
});

<?php

use App\Models\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
});

it('closes and reopens an event from the owner page', function () {
    $this->post('/events/PARTY2/toggle-closed')->assertRedirect('/events/PARTY2');
    expect($this->event->refresh()->closed_at)->not->toBeNull();

    $this->post('/events/PARTY2/toggle-closed');
    expect($this->event->refresh()->closed_at)->toBeNull();
});

it('rejects uploads to a closed event', function () {
    $this->event->update(['closed_at' => now()]);

    uploadPhoto('PARTY2')->assertGone();

    expect($this->event->photos()->count())->toBe(0);
});

it('tells guests the booth is closed instead of offering the camera', function () {
    $this->event->update(['closed_at' => now()]);

    $this->get('/e/PARTY2')
        ->assertOk()
        ->assertSee('closed')
        ->assertDontSee('Quick shoot');
});

it('keeps the album visible for a closed event', function () {
    $id = uploadPhoto('PARTY2')->json('id');
    $this->event->update(['closed_at' => now()]);

    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee("photos/$id", false);
});

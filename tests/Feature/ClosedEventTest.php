<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
});

it('closes and reopens an event from the owner page', function () {
    $this->post('/events/PARTY2/toggle-closed')->assertRedirect('/events/PARTY2#booth');
    expect($this->event->refresh()->closed_at)->not->toBeNull();

    $this->post('/events/PARTY2/toggle-closed');
    expect($this->event->refresh()->closed_at)->toBeNull();
});

// Closing is reversible — the copy beside it says so — and it wore .btn--danger,
// the quiet text tier kept for the three irreversible controls, whose colour and
// underline only ever appeared on hover: on a phone it read as static copy
// rather than a button. It still does not match "Reopen the booth", which is a
// solid btn--small; see PLAN's design system for that open question.
it('dresses close the booth as the reversible control it is', function () {
    $this->get('/events/PARTY2')->assertSee('class="btn--ghost btn--small">Close the booth', false);
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

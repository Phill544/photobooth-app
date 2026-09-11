<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// The host's own screens. A field the server accepts but no form offers is a
// feature only an API client has, and a rejected upload whose message renders
// nowhere is worse than no validation at all.

beforeEach(function () {
    Storage::fake();
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'owner_id' => $this->owner->id]);
});

it('offers a background field on the create form', function () {
    $this->get('/new')->assertOk()->assertSee('name="background"', false);
});

it('offers a background field in the edit fold', function () {
    $this->get('/events/PARTY2')->assertOk()->assertSee('name="background"', false);
});

it('offers a remove tick only once there is a background to remove', function () {
    $this->get('/events/PARTY2')->assertOk()->assertDontSee('name="remove_background"', false);

    $this->event->update(['background_path' => 'backgrounds/mat.png']);

    $this->get('/events/PARTY2')->assertOk()->assertSee('name="remove_background"', false);
});

// Unlike the logo's hint-or-tick above it, which hides the guidance from exactly
// the host who came back to change their artwork.
it('still says what the background does once the host has one', function () {
    $this->event->update(['background_path' => 'backgrounds/mat.png']);

    $this->get('/events/PARTY2')->assertOk()->assertSee('Sits behind the photos');
});

it('points the edit form at the current background so the preview can draw it', function () {
    $this->get('/events/PARTY2')->assertOk()->assertDontSee('data-background-url', false);

    $this->event->update(['background_path' => 'backgrounds/mat.png']);

    $this->get('/events/PARTY2')->assertOk()->assertSee('data-background-url', false);
});

// Every control on this page sits a screen below the poster, so a rejected
// upload that redirects with the fold shut answers the host with the top of the
// page and no sign of what went wrong.
it('opens the edit fold when a background is rejected', function () {
    $this->patch('/events/PARTY2', [
        'name' => 'Summer Party',
        'background' => UploadedFile::fake()->create('mat.pdf', 40, 'application/pdf'),
    ])->assertRedirect('/events/PARTY2#edit');

    $this->followingRedirects()
        ->patch('/events/PARTY2', [
            'name' => 'Summer Party',
            'background' => UploadedFile::fake()->create('mat.pdf', 40, 'application/pdf'),
        ])
        ->assertSee('<details class="edit" id="edit" open', false);
});

it('offers the layout guide on both forms', function () {
    $this->get('/new')->assertOk()->assertSee('data-strip-guide', false);
    $this->get('/events/PARTY2')->assertOk()->assertSee('data-strip-guide', false);
});

// The link is inert until the PNG has been drawn and its object URL set, so it
// ships disabled rather than as a control that silently downloads nothing.
it('ships the guide link disabled, for the script to arm', function () {
    $this->get('/new')->assertOk()->assertSee('data-strip-guide download aria-disabled="true"', false);
});

<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

// A host whose background already has their name across the foot of it has, until
// now, had no way to stop the app printing a second one over the top: an empty
// caption field falls back to the event name, and ConvertEmptyStringsToNull means
// "cleared" and "never set" arrive at the server as the same null. So the choice
// needs a control of its own rather than a blank field.

beforeEach(function () {
    Storage::fake();
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'owner_id' => $this->owner->id]);
});

it('prints the caption a host typed', function () {
    $this->event->update(['caption' => 'Sam & Ali']);

    expect($this->event->stripCaption())->toBe('Sam & Ali');
});

it('falls back to the event name when no caption was typed', function () {
    expect($this->event->stripCaption())->toBe('Summer Party');
});

it('prints nothing when the host says their artwork has its own', function () {
    $this->event->update(['caption' => 'Sam & Ali', 'caption_hidden' => true]);

    expect($this->event->stripCaption())->toBe('');
});

it('hides the event name too, not just a typed caption', function () {
    $this->event->update(['caption_hidden' => true]);

    expect($this->event->stripCaption())->toBe('');
});

it('tells the booth there is no caption, rather than leaving it to guess', function () {
    $this->event->update(['caption' => 'Sam & Ali', 'caption_hidden' => true]);

    $this->get('/e/PARTY2')->assertOk()->assertSee('data-caption=""', false);
});

it('offers the tick on the create form and in the edit fold', function () {
    $this->get('/new')->assertOk()->assertSee('name="caption_hidden"', false);
    $this->get('/events/PARTY2')->assertOk()->assertSee('name="caption_hidden"', false);
});

it('saves the tick from the edit fold', function () {
    $this->patch('/events/PARTY2', ['name' => 'Summer Party', 'caption_hidden' => '1']);

    expect($this->event->refresh()->caption_hidden)->toBeTrue();
});

// An unticked checkbox sends nothing at all, so absence has to mean false or a
// host could turn the caption off and never get it back.
it('clears the tick when the host unticks it', function () {
    $this->event->update(['caption_hidden' => true]);

    $this->patch('/events/PARTY2', ['name' => 'Summer Party']);

    expect($this->event->refresh()->caption_hidden)->toBeFalse();
});

it('keeps the typed caption while it is hidden, so unticking brings it back', function () {
    $this->event->update(['caption' => 'Sam & Ali']);

    $this->patch('/events/PARTY2', ['name' => 'Summer Party', 'caption' => 'Sam & Ali', 'caption_hidden' => '1']);
    expect($this->event->refresh()->stripCaption())->toBe('');

    $this->patch('/events/PARTY2', ['name' => 'Summer Party', 'caption' => 'Sam & Ali']);
    expect($this->event->refresh()->stripCaption())->toBe('Sam & Ali');
});

it('takes the tick when creating an event', function () {
    $this->post('/events', ['name' => 'Corporate Do', 'caption_hidden' => '1']);

    expect(Event::where('name', 'Corporate Do')->sole()->caption_hidden)->toBeTrue();
});

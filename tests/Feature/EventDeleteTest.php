<?php

use App\Models\Event;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->owner = User::factory()->create();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'owner_id' => $this->owner->id]);
});

it('offers the owner a way to delete the event', function () {
    $this->actingAs($this->owner)->get('/events/PARTY2')
        ->assertOk()
        ->assertSee('Delete this event')
        ->assertSee('name="confirm_code"', false);
});

it('deletes the event, its photos and their files', function () {
    uploadPhoto('PARTY2', ['kind' => 'strip', 'slot' => 0]);
    uploadPhoto('PARTY2');
    $paths = Photo::pluck('path');
    expect($paths)->toHaveCount(2); // or the assertions below assert nothing

    $this->actingAs($this->owner)
        ->delete('/events/PARTY2', ['confirm_code' => 'PARTY2'])
        ->assertRedirect('/dashboard');

    expect(Event::count())->toBe(0)
        ->and(Photo::count())->toBe(0);
    $paths->each(fn (string $path) => Storage::assertMissing($path));
});

// The queue runs inline under test, so this is the derivative GenerateThumbnail
// really wrote, at the path it really chose — not a stand-in.
it('deletes the thumbnails as well as the originals', function () {
    uploadPhoto('PARTY2');
    $thumb = Photo::sole()->thumb_path;
    expect($thumb)->not->toBeNull();
    Storage::assertExists($thumb);

    $this->actingAs($this->owner)->delete('/events/PARTY2', ['confirm_code' => 'PARTY2']);

    Storage::assertMissing($thumb);
});

// GenerateThumbnail writes the derivative and only then records the path, so a
// job that died in between leaves a file no row knows about. Sweeping the
// event's prefix takes those too; deleting row by row never could.
it('deletes a derivative the photo row never recorded', function () {
    uploadPhoto('PARTY2');
    $photo = Photo::sole();
    $written = $photo->thumb_path;
    $photo->update(['thumb_path' => null]); // the write landed, the column never did

    $this->actingAs($this->owner)->delete('/events/PARTY2', ['confirm_code' => 'PARTY2']);

    Storage::assertMissing($written);
});

// purge() sweeps events/{id}/ rather than spending one delete per file, because
// an object store clears a prefix in batches of a thousand and a busy event has
// thousands of files. That is only correct while every photo is written there —
// so this pins it, and a change to where uploads land fails here first.
it('writes every photo under its own event prefix', function () {
    uploadPhoto('PARTY2', ['kind' => 'strip', 'slot' => 0]);
    uploadPhoto('PARTY2');

    expect(Photo::count())->toBe(2);
    Photo::each(fn (Photo $photo) => expect($photo->path)->toStartWith("events/{$photo->event_id}/"));
});

// The logo is the one file an event owns that isn't a photo row, so it is the
// one a purge can silently leave behind.
it('deletes the logo file too', function () {
    Storage::put('logos/party.png', 'bytes');
    $this->event->update(['logo_path' => 'logos/party.png']);

    $this->actingAs($this->owner)->delete('/events/PARTY2', ['confirm_code' => 'PARTY2']);

    Storage::assertMissing('logos/party.png');
});

it('leaves another event untouched', function () {
    Event::create(['name' => 'Other Party', 'code' => 'OTHER2', 'owner_id' => $this->owner->id]);
    uploadPhoto('OTHER2');
    $survivor = Photo::sole();

    $this->actingAs($this->owner)->delete('/events/PARTY2', ['confirm_code' => 'PARTY2']);

    expect(Event::sole()->code)->toBe('OTHER2')
        ->and(Photo::count())->toBe(1);
    Storage::assertExists($survivor->path);
});

// A browser confirm() is client-side theatre — the guard has to be somewhere a
// stray request cannot skip, so the code is checked on the server.
it('refuses to delete without the confirmation code', function () {
    uploadPhoto('PARTY2');

    $this->actingAs($this->owner)->delete('/events/PARTY2')
        ->assertInvalid(['confirm_code']);

    expect(Event::count())->toBe(1)
        ->and(Photo::count())->toBe(1);
    Storage::assertExists(Photo::sole()->path); // the rows surviving is not the point — the bytes are
});

it('refuses to delete with the wrong confirmation code', function () {
    $this->actingAs($this->owner)->delete('/events/PARTY2', ['confirm_code' => 'OTHER2'])
        ->assertInvalid(['confirm_code']);

    expect(Event::count())->toBe(1);
});

// Codes are case-insensitive everywhere else a human types one, and this field
// is the one place a host types theirs from memory.
it('accepts the confirmation code in lower case', function () {
    $this->actingAs($this->owner)->delete('/events/PARTY2', ['confirm_code' => 'party2'])
        ->assertRedirect('/dashboard');

    expect(Event::count())->toBe(0);
});

// The panel is folded by default and sits at the bottom of a long page, so a
// rejected code has to both reopen it and land the host on it — measured at
// 990px down a 720px viewport, the error is otherwise simply not on screen.
it('reopens the delete panel when the code was wrong', function () {
    $this->actingAs($this->owner)
        ->from('/events/PARTY2')
        ->delete('/events/PARTY2', ['confirm_code' => 'nope'])
        ->assertRedirect('/events/PARTY2#delete');

    $this->actingAs($this->owner)->get('/events/PARTY2')
        ->assertSee('<details class="danger" id="delete" open>', false)
        ->assertSee('Type PARTY2 to delete this event.')
        // ...and only that panel: the edit fold used to spring open on any error.
        ->assertDontSee('<details class="edit" open', false);
});

it('does not let a different owner delete the event', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->delete('/events/PARTY2', ['confirm_code' => 'PARTY2'])
        ->assertForbidden();

    expect(Event::count())->toBe(1);
});

it('does not let a guest delete the event', function () {
    $this->delete('/events/PARTY2', ['confirm_code' => 'PARTY2'])->assertRedirect('/login');

    expect(Event::count())->toBe(1);
});

it('lets an admin delete somebody else’s event', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->delete('/events/PARTY2', ['confirm_code' => 'PARTY2'])
        ->assertRedirect('/dashboard');

    expect(Event::count())->toBe(0);
});

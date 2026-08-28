<?php

use App\Jobs\GenerateThumbnail;
use App\Models\Event;
use App\Models\Photo;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
});

it('serves an uploaded photo file', function () {
    $id = uploadPhoto('PARTY2')->json('id');

    $this->get("/e/PARTY2/photos/$id")
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg');
});

it('404s for a photo belonging to a different event', function () {
    Event::create(['name' => 'Other Party', 'code' => 'OTHER2']);
    $id = uploadPhoto('OTHER2')->json('id');

    $this->get("/e/PARTY2/photos/$id")->assertNotFound();
});

it('404s for a derivative belonging to a different event', function () {
    Event::create(['name' => 'Other Party', 'code' => 'OTHER2']);
    $photo = Photo::find(uploadPhoto('OTHER2')->json('id'));
    (new GenerateThumbnail($photo))->handle();

    // Same scoping as the full-size route: a photo id from one event must not
    // resolve under another event's code.
    $this->get("/e/PARTY2/photos/{$photo->id}/thumb")->assertNotFound();
});

it('404s for an unknown photo id', function () {
    $this->get('/e/PARTY2/photos/999')->assertNotFound();
});

// A row can outlive its file: a bucket detached mid-life, a release that ran
// before storage was attached (DEPLOY.md documents exactly that state), or an
// event purge that cleared the prefix and then failed to drop the rows. That is
// a missing file, not a broken server — and it matters which one the app says,
// because an album asks this route once per tile. Answering 500 turns one bad
// row into a wall of the most expensive page the app can render: measured at
// 1.2s and 902KB each against 0.40s and 79KB for a photo that is really there.

it('404s when a photo row outlives its file', function () {
    $id = uploadPhoto('PARTY2')->json('id');
    Storage::delete(Photo::find($id)->path);

    $this->get("/e/PARTY2/photos/$id")->assertNotFound();
});

it('404s when a thumbnail row outlives its file', function () {
    $id = uploadPhoto('PARTY2')->json('id');
    Storage::delete(Photo::find($id)->thumb_path);

    $this->get("/e/PARTY2/photos/$id/thumb")->assertNotFound();
});

it('404s when an event logo outlives its file', function () {
    Storage::put('logos/party.png', 'bytes');
    $this->event->update(['logo_path' => 'logos/party.png']);
    Storage::delete('logos/party.png');

    $this->get('/e/PARTY2/logo')->assertNotFound();
});

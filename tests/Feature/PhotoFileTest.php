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

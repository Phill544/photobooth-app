<?php

use App\Models\Event;
use App\Models\Photo;
use Illuminate\Support\Facades\Storage;

// Stored paths are random hashes, and a browser prefers the filename the server
// states over an <a download> attribute — so the server has to state a good one,
// or a guest saving a photo from the album gets 1XutcJBNfLXr44p5.jpg in their
// camera roll.

beforeEach(function () {
    Storage::fake();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
});

it('names a saved strip after the event', function () {
    $id = boothUpload('PARTY2', ['kind' => 'strip', 'slot' => 0])->json('id');

    $disposition = $this->get("/e/PARTY2/photos/$id")->headers->get('Content-Disposition');

    expect($disposition)->toContain("summer-party-strip-$id.jpg")
        ->and($disposition)->toStartWith('inline');
});

it('names a saved shot after the event too', function () {
    $id = boothUpload('PARTY2', ['kind' => 'original', 'slot' => 1])->json('id');

    expect($this->get("/e/PARTY2/photos/$id")->headers->get('Content-Disposition'))
        ->toContain("summer-party-photo-$id.jpg");
});

it('never states the storage hash as the filename', function () {
    $photo = Photo::find(boothUpload('PARTY2')->json('id'));

    expect($this->get("/e/PARTY2/photos/{$photo->id}")->headers->get('Content-Disposition'))
        ->not->toContain(pathinfo($photo->path, PATHINFO_FILENAME));
});

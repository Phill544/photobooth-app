<?php

use App\Jobs\GenerateThumbnail;
use App\Models\Event;
use App\Models\Photo;
use Illuminate\Support\Facades\Storage;

// The album shows derivatives and enlarges originals: a phone scrolling 50
// sessions must not download 50 full-size strips.

beforeEach(function () {
    Storage::fake();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
});

it('asks for the derivative in the grid once the queue has written one', function () {
    $photo = Photo::find(boothUpload('PARTY2', ['kind' => 'strip', 'slot' => 0])->json('id'));
    (new GenerateThumbnail($photo))->handle();

    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee("src=\"/e/PARTY2/photos/{$photo->id}/thumb\"", false);
});

it('asks for the original in the grid while the derivative is still pending', function () {
    $photo = Photo::find(boothUpload('PARTY2', ['kind' => 'strip', 'slot' => 0])->json('id'));
    $photo->update(['thumb_path' => null]); // the worker hasn't got to it yet

    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee("src=\"/e/PARTY2/photos/{$photo->id}\"", false);
});

it('links every grid image to the full-size file, whatever the grid is showing', function () {
    $photo = Photo::find(boothUpload('PARTY2', ['slot' => 1])->json('id'));
    (new GenerateThumbnail($photo))->handle();

    // The href is the original: enlarging is the point of tapping it.
    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee("href=\"/e/PARTY2/photos/{$photo->id}\"", false);
});

it('links a strip tile to the full-size strip, not to its derivative', function () {
    $photo = Photo::find(boothUpload('PARTY2', ['kind' => 'strip', 'slot' => 0])->json('id'));
    (new GenerateThumbnail($photo))->handle();

    // The wall of strips is the album's centrepiece: tapping one has to enlarge
    // the real thing, not the 480px tile.
    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee("href=\"/e/PARTY2/photos/{$photo->id}\"", false);
});

it('offers a save for the one photo a guest enlarged', function () {
    boothUpload('PARTY2');

    // The filename itself comes from the server (see PhotoDownloadNameTest), so
    // all this page needs is the affordance.
    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee('id="lightbox-save"', false)
        ->assertSee('download>Save this photo', false);
});

it('has nothing to enlarge in an empty album', function () {
    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertDontSee('id="lightbox"', false);
});

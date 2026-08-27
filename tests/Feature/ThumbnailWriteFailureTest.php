<?php

use App\Jobs\GenerateThumbnail;
use App\Models\Event;
use App\Models\Photo;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// The same silent `false` as an upload: Storage::put returns it rather than
// throwing. Recording thumb_path anyway would point every album tile for that
// photo at a file that was never written — and the tile 500s rather than 404s,
// because Storage::response asks the disk for a size first.

beforeEach(function () {
    Storage::fake();
    // The suite runs the queue synchronously, so hold the job back and run it by
    // hand — otherwise the upload has already generated the derivative.
    Queue::fake();
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
});

it('does not record a derivative that was never written', function () {
    $photo = Photo::find(boothUpload('PARTY2')->json('id'));
    $original = Storage::get($photo->path);

    Storage::shouldReceive('get')->once()->andReturn($original);
    Storage::shouldReceive('put')->once()->andReturn(false);

    expect(fn () => (new GenerateThumbnail($photo))->handle())->toThrow(RuntimeException::class);

    expect($photo->refresh()->thumb_path)->toBeNull();
});

it('records the derivative when the write lands', function () {
    $photo = Photo::find(boothUpload('PARTY2')->json('id'));

    (new GenerateThumbnail($photo))->handle();

    expect($photo->refresh()->thumb_path)->not->toBeNull();
    Storage::assertExists($photo->thumb_path);
});

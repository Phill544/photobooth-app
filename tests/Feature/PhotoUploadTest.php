<?php

use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
    $this->group = 'aa0f7c69-3c1e-4d3c-9c39-58b7d31f2f10';
});

it('stores an uploaded original photo', function () {
    uploadPhoto('PARTY2', ['group' => $this->group])
        ->assertCreated()
        ->assertJsonStructure(['id']);

    $photo = $this->event->photos()->sole();
    expect($photo->kind)->toBe('original')
        ->and($photo->group_uuid)->toBe($this->group)
        ->and($photo->slot)->toBe(1);
    Storage::assertExists($photo->path);
});

it('stores a photo strip at slot 0', function () {
    uploadPhoto('PARTY2', ['kind' => 'strip', 'slot' => 0])
        ->assertCreated();

    expect($this->event->photos()->sole()->kind)->toBe('strip');
});

it('returns the existing photo when the same group and slot is uploaded again', function () {
    $first = uploadPhoto('PARTY2', ['group' => $this->group])->json('id');

    uploadPhoto('PARTY2', ['group' => $this->group])
        ->assertOk()
        ->assertJson(['id' => $first]);

    expect($this->event->photos()->count())->toBe(1);
});

it('rejects a non-image upload', function () {
    // Fake uploads mime-guess from the filename, so content sniffing itself
    // can't be exercised here — that part is framework behavior.
    $notAnImage = UploadedFile::fake()->create('notes.txt', 100, 'text/plain');

    uploadPhoto('PARTY2', ['photo' => $notAnImage])
        ->assertInvalid(['photo']);
});

it('rejects an unknown kind', function () {
    uploadPhoto('PARTY2', ['kind' => 'gif'])
        ->assertInvalid(['kind']);
});

it('rejects a malformed group uuid', function () {
    uploadPhoto('PARTY2', ['group' => 'not-a-uuid'])
        ->assertInvalid(['group']);
});

it('rejects an oversized upload', function () {
    $huge = UploadedFile::fake()->create('huge.jpg', 15_000, 'image/jpeg');

    uploadPhoto('PARTY2', ['photo' => $huge])
        ->assertInvalid(['photo']);
});

it('404s when uploading to an unknown event', function () {
    uploadPhoto('XXXXXX')->assertNotFound();
});

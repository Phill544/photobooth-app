<?php

use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
    $this->group = 'aa0f7c69-3c1e-4d3c-9c39-58b7d31f2f10';
});

function upload(array $overrides = []): array
{
    return array_merge([
        'photo' => UploadedFile::fake()->image('shot.jpg', 1080, 810),
        'kind' => 'original',
        'group' => 'aa0f7c69-3c1e-4d3c-9c39-58b7d31f2f10',
        'slot' => 1,
    ], $overrides);
}

it('stores an uploaded original photo', function () {
    $this->post('/e/PARTY2/photos', upload())
        ->assertCreated()
        ->assertJsonStructure(['id']);

    $photo = $this->event->photos()->sole();
    expect($photo->kind)->toBe('original')
        ->and($photo->group_uuid)->toBe($this->group)
        ->and($photo->slot)->toBe(1);
    Storage::assertExists($photo->path);
});

it('stores a photo strip at slot 0', function () {
    $this->post('/e/PARTY2/photos', upload(['kind' => 'strip', 'slot' => 0]))
        ->assertCreated();

    expect($this->event->photos()->sole()->kind)->toBe('strip');
});

it('returns the existing photo when the same group and slot is uploaded again', function () {
    $first = $this->post('/e/PARTY2/photos', upload())->json('id');

    $this->post('/e/PARTY2/photos', upload())
        ->assertOk()
        ->assertJson(['id' => $first]);

    expect($this->event->photos()->count())->toBe(1);
});

it('rejects a non-image upload', function () {
    // Fake uploads mime-guess from the filename, so content sniffing itself
    // can't be exercised here — that part is framework behavior.
    $notAnImage = UploadedFile::fake()->create('notes.txt', 100, 'text/plain');

    $this->post('/e/PARTY2/photos', upload(['photo' => $notAnImage]))
        ->assertInvalid(['photo']);
});

it('rejects an unknown kind', function () {
    $this->post('/e/PARTY2/photos', upload(['kind' => 'gif']))
        ->assertInvalid(['kind']);
});

it('rejects a missing group uuid', function () {
    $this->post('/e/PARTY2/photos', upload(['group' => 'not-a-uuid']))
        ->assertInvalid(['group']);
});

it('rejects an oversized upload', function () {
    $huge = UploadedFile::fake()->create('huge.jpg', 15_000, 'image/jpeg');

    $this->post('/e/PARTY2/photos', upload(['photo' => $huge]))
        ->assertInvalid(['photo']);
});

it('404s when uploading to an unknown event', function () {
    $this->post('/e/XXXXXX/photos', upload())->assertNotFound();
});

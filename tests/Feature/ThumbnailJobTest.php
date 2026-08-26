<?php

use App\Jobs\GenerateThumbnail;
use App\Models\Event;
use App\Models\Photo;
use App\Models\User;
use App\Support\Thumbnail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
});

it('queues a derivative for every photo the booth sends up', function () {
    Queue::fake();

    boothUpload('PARTY2', ['kind' => 'strip', 'slot' => 0]);
    boothUpload('PARTY2', ['kind' => 'original', 'slot' => 1]);

    Queue::assertPushed(GenerateThumbnail::class, 2);
});

it('does not queue anything for an upload the server already had', function () {
    $group = fake()->uuid();
    boothUpload('PARTY2', ['group' => $group, 'slot' => 1]);

    Queue::fake();
    boothUpload('PARTY2', ['group' => $group, 'slot' => 1]); // the same slot, resent

    Queue::assertNothingPushed();
});

it('writes the derivative alongside the original and records where', function () {
    $id = boothUpload('PARTY2', ['photo' => UploadedFile::fake()->image('shot.jpg', 1200, 900)])
        ->json('id');
    $photo = Photo::find($id);

    (new GenerateThumbnail($photo))->handle();

    $photo->refresh();
    expect($photo->thumb_path)->toBe(dirname($photo->path).'/thumbs/'.basename($photo->path));
    Storage::assertExists($photo->thumb_path);
});

it('writes a derivative no wider than the grid needs', function () {
    $id = boothUpload('PARTY2', ['photo' => UploadedFile::fake()->image('shot.jpg', 1200, 900)])
        ->json('id');
    $photo = Photo::find($id);

    (new GenerateThumbnail($photo))->handle();

    $bytes = Storage::get($photo->refresh()->thumb_path);
    expect(getimagesizefromstring($bytes)[0])->toBe(Thumbnail::MAX_WIDTH);
    expect(strlen($bytes))->toBeLessThan(Storage::size($photo->path));
});

it('takes the derivative with it when a session is deleted', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $group = fake()->uuid();
    $id = boothUpload('PARTY2', ['group' => $group])->json('id');
    $photo = Photo::find($id);
    (new GenerateThumbnail($photo))->handle();
    $thumb = $photo->refresh()->thumb_path;

    $this->delete("/e/PARTY2/groups/$group");

    Storage::assertMissing($thumb);
    Storage::assertMissing($photo->path);
});

it('takes derivatives with it when an event is purged', function () {
    $id = boothUpload('PARTY2')->json('id');
    $photo = Photo::find($id);
    (new GenerateThumbnail($photo))->handle();
    $thumb = $photo->refresh()->thumb_path;

    $this->artisan('photobooth:purge-event PARTY2')->expectsConfirmation(
        "Delete 'Summer Party' and its 1 photos?", 'yes'
    )->assertSuccessful();

    Storage::assertMissing($thumb);
    Storage::assertMissing($photo->path);
});

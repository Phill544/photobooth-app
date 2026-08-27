<?php

use App\Models\Event;
use App\Models\Photo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

// Laravel Cloud builds its object-storage disk with 'throw' => false and
// 'report' => false, so a refused write (an R2 5xx, a rotated credential, a
// throttle) comes back as a plain `false` with nothing logged. If that false is
// stored as the photo's path, the guest is told 201 — and the booth then drops
// the only other copy of their strip from IndexedDB. The bytes are gone.

beforeEach(function () {
    Storage::fake();
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
});

it('refuses the upload when the disk silently drops the write', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('putFileAs')->once()->andReturn(false);
    Storage::shouldReceive('disk')->andReturn($disk);

    // Not 2xx: the client's retry tail treats this as worth another go, and the
    // phone keeps its copy until one actually lands.
    boothUpload('PARTY2')->assertStatus(503);
});

it('never records a photo whose bytes were not written', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('putFileAs')->once()->andReturn(false);
    Storage::shouldReceive('disk')->andReturn($disk);

    boothUpload('PARTY2');

    // A row with a falsey path is worse than no row: it is invisible to
    // Photo::paths(), so no delete or purge would ever clean it up.
    expect(Photo::count())->toBe(0);
});

it('still stores a photo normally when the disk is healthy', function () {
    $id = boothUpload('PARTY2')->assertStatus(201)->json('id');

    expect(Photo::find($id)->path)->toBeString()->not->toBeEmpty();
    Storage::assertExists(Photo::find($id)->path);
});

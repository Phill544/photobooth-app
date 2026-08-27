<?php

use App\Models\Event;
use App\Models\Photo;
use Illuminate\Support\Facades\Storage;

// photobooth:check-storage runs once, at deploy, in one container. A bucket
// detached mid-life, a preview environment that gets no bucket replicated, or a
// container whose env lacks the injected disk config all revert the default disk
// to the container's own filesystem — silently, and long after the deploy gate
// has passed. A refused upload is recoverable; a 201 written to a disk that dies
// with the container is not.

beforeEach(function () {
    Storage::fake();
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
});

it('refuses an upload rather than write a photo to a disk that dies with the container', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['filesystems.default' => 'local']);

    boothUpload('PARTY2')->assertStatus(503);

    expect(Photo::count())->toBe(0);
});

it('refuses on staging too, not just production', function () {
    app()->detectEnvironment(fn () => 'staging');
    config(['filesystems.default' => 'local']);

    boothUpload('PARTY2')->assertStatus(503);
});

it('lets a local disk through while developing, where it is the right answer', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['filesystems.default' => 'local']);

    boothUpload('PARTY2')->assertStatus(201);
});

it('lets a durable disk through', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['filesystems.default' => 'private', 'filesystems.disks.private' => ['driver' => 's3']]);
    Storage::fake('private');

    boothUpload('PARTY2')->assertStatus(201);
});

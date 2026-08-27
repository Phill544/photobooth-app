<?php

use App\Models\Event;
use App\Models\Photo;
use Illuminate\Support\Facades\Storage;

// The failure this exists to catch: with no bucket attached, the default disk
// silently falls back to the container's local disk, every photo is written to
// storage that dies with the next deploy, and nothing anywhere reports it. This
// command is meant to run as a deploy command so that a release configured that
// way never goes live.

it('fails when a deployed environment would write photos to a local disk', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['filesystems.default' => 'local']);

    $this->artisan('photobooth:check-storage')
        ->expectsOutputToContain('local')
        ->assertFailed();
});

it('fails on a staging environment too, not just production', function () {
    app()->detectEnvironment(fn () => 'staging');
    config(['filesystems.default' => 'local']);

    $this->artisan('photobooth:check-storage')->assertFailed();
});

it('accepts a local disk while developing, where it is the right answer', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['filesystems.default' => 'local']);
    Storage::fake('local');

    $this->artisan('photobooth:check-storage')->assertSuccessful();
});

it('passes when the default disk is durable and a write survives a read', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['filesystems.default' => 'private', 'filesystems.disks.private' => ['driver' => 's3']]);
    Storage::fake('private');

    $this->artisan('photobooth:check-storage')
        ->expectsOutputToContain('private')
        ->assertSuccessful();
});

it('leaves nothing behind on the disk it probed', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['filesystems.default' => 'private', 'filesystems.disks.private' => ['driver' => 's3']]);
    Storage::fake('private');

    $this->artisan('photobooth:check-storage')->assertSuccessful();

    expect(Storage::disk('private')->allFiles())->toBeEmpty();
});

it('fails when the disk takes a write but cannot give it back', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['filesystems.default' => 'private', 'filesystems.disks.private' => ['driver' => 's3']]);
    // A bucket that accepts writes and loses them is indistinguishable from one
    // that works, until an event is over. Read it back and compare.
    Storage::shouldReceive('put')->once()->andReturnTrue();
    Storage::shouldReceive('get')->once()->andReturn(null);

    $this->artisan('photobooth:check-storage')->assertFailed();
});

// Prevention is only half of it: if a file ever does go missing, the owner needs
// a way to find out that isn't "a guest tells me the album is broken".

it('reports nothing missing when every photo has its file', function () {
    app()->detectEnvironment(fn () => 'local');
    Storage::fake('local');
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
    boothUpload('PARTY2');

    $this->artisan('photobooth:check-storage --photos')
        ->expectsOutputToContain('0 of 1')
        ->assertSuccessful();
});

it('fails and counts the photos whose files have gone', function () {
    app()->detectEnvironment(fn () => 'local');
    Storage::fake('local');
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
    $photo = Photo::find(boothUpload('PARTY2')->json('id'));
    Storage::delete($photo->path); // what a wiped ephemeral disk leaves behind

    $this->artisan('photobooth:check-storage --photos')
        ->expectsOutputToContain('1 of 1')
        ->assertFailed();
});

it('leaves the photo sweep alone unless asked', function () {
    app()->detectEnvironment(fn () => 'local');
    Storage::fake('local');
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
    $photo = Photo::find(boothUpload('PARTY2')->json('id'));
    Storage::delete($photo->path);

    // The deploy gate must stay fast; checking every object is opt-in.
    $this->artisan('photobooth:check-storage')->assertSuccessful();
});

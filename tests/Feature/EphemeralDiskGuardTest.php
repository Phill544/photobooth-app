<?php

use App\Models\Event;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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

// A host's branding goes through EventController::applyLogo, which had no such
// guard: the disk returns a real path, the column commits, and the file is gone
// on the next deploy with nothing anywhere saying so. Unlike a photo this is not
// even recoverable by re-shooting — the host's original is on their own machine
// and they have no reason to think it did not stick.
//
// These POST as a host, where the upload cases above are a guest at the booth.
it('refuses a logo rather than write it to a disk that dies with the container', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    // Leaving the testing environment turns CSRF verification back on with it,
    // and this is a form post rather than the booth's exempt upload route — so
    // these two carry a token the way a real browser would.
    $this->withSession(['_token' => 'a-real-session-token']);
    app()->detectEnvironment(fn () => 'production');
    config(['filesystems.default' => 'local']);

    $this->patch('/events/PARTY2', [
        '_token' => 'a-real-session-token',
        'name' => 'Summer Party',
        'logo' => UploadedFile::fake()->image('logo.png', 400, 200),
    ])->assertStatus(503);

    expect(Event::where('code', 'PARTY2')->sole()->logo_path)->toBeNull();
});

// The guard cannot simply sit at the top of applyLogo: removing a logo writes
// nothing, so refusing it would strand a host on an ephemeral disk with branding
// they cannot take off.
it('still lets a host remove a logo on a disk it would refuse to write to', function () {
    $event = Event::where('code', 'PARTY2')->sole();
    $event->update(['logo_path' => 'logos/old.png']);
    Storage::put('logos/old.png', 'bytes');

    $this->actingAs(User::factory()->create(['is_admin' => true]));
    // Leaving the testing environment turns CSRF verification back on with it,
    // and this is a form post rather than the booth's exempt upload route — so
    // these two carry a token the way a real browser would.
    $this->withSession(['_token' => 'a-real-session-token']);
    app()->detectEnvironment(fn () => 'production');
    config(['filesystems.default' => 'local']);

    $this->patch('/events/PARTY2', [
        '_token' => 'a-real-session-token',
        'name' => 'Summer Party',
        'remove_logo' => '1',
    ])->assertRedirect('/events/PARTY2#edit');

    expect($event->fresh()->logo_path)->toBeNull();
});

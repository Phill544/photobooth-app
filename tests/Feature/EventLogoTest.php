<?php

use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->event = Event::create(['name' => 'Acme Party', 'code' => 'PARTY2']);
});

it('stores a logo uploaded when creating an event', function () {
    $this->post('/events', [
        'name' => 'Corporate Do',
        'logo' => UploadedFile::fake()->image('logo.png', 400, 200),
    ]);

    $event = Event::where('name', 'Corporate Do')->sole();
    expect($event->logo_path)->not->toBeNull();
    Storage::assertExists($event->logo_path);
});

it('serves the event logo', function () {
    $this->event->update(['logo_path' => UploadedFile::fake()->image('l.png', 200, 100)->store('logos')]);

    $this->get('/e/PARTY2/logo')->assertOk();
});

it('404s when the event has no logo', function () {
    $this->get('/e/PARTY2/logo')->assertNotFound();
});

it('replaces the logo on update and deletes the old file', function () {
    $old = UploadedFile::fake()->image('old.png')->store('logos');
    $this->event->update(['logo_path' => $old]);

    $this->patch('/events/PARTY2', [
        'name' => 'Acme Party',
        'logo' => UploadedFile::fake()->image('new.png', 300, 150),
    ]);

    $event = $this->event->refresh();
    expect($event->logo_path)->not->toBe($old);
    Storage::assertMissing($old);
    Storage::assertExists($event->logo_path);
});

it('removes the logo when asked', function () {
    $path = UploadedFile::fake()->image('l.png')->store('logos');
    $this->event->update(['logo_path' => $path]);

    $this->patch('/events/PARTY2', ['name' => 'Acme Party', 'remove_logo' => '1']);

    expect($this->event->refresh()->logo_path)->toBeNull();
    Storage::assertMissing($path);
});

it('rejects a non-image logo', function () {
    $this->patch('/events/PARTY2', [
        'name' => 'Acme Party',
        'logo' => UploadedFile::fake()->create('logo.pdf', 40, 'application/pdf'),
    ])->assertInvalid(['logo']);
});

<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// Same silent-false write as a photo upload, and the logo path made it worse by
// deleting the old file before writing the new one.

beforeEach(function () {
    Storage::fake();
    $this->owner = User::factory()->create();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'owner_id' => $this->owner->id]);
    $this->actingAs($this->owner);
});

it('keeps the old logo when the disk refuses the replacement', function () {
    $this->event->update(['logo_path' => 'logos/original.png']);
    Storage::put('logos/original.png', 'the original');

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('putFileAs')->once()->andReturn(false);
    Storage::shouldReceive('disk')->andReturn($disk);
    Storage::shouldReceive('delete')->never();

    $this->patch('/events/PARTY2', [
        'name' => 'Summer Party',
        'logo' => UploadedFile::fake()->image('new.png', 400, 200),
    ])->assertStatus(503);

    expect($this->event->refresh()->logo_path)->toBe('logos/original.png');
});

it('drops the old file only once the replacement is safely stored', function () {
    $this->event->update(['logo_path' => 'logos/original.png']);
    Storage::put('logos/original.png', 'the original');

    $this->patch('/events/PARTY2', [
        'name' => 'Summer Party',
        'logo' => UploadedFile::fake()->image('new.png', 400, 200),
    ])->assertRedirect('/events/PARTY2');

    Storage::assertMissing('logos/original.png');
    Storage::assertExists($this->event->refresh()->logo_path);
});

it('still removes a logo when the host asks for it gone', function () {
    $this->event->update(['logo_path' => 'logos/original.png']);
    Storage::put('logos/original.png', 'the original');

    $this->patch('/events/PARTY2', ['name' => 'Summer Party', 'remove_logo' => '1'])
        ->assertRedirect('/events/PARTY2');

    expect($this->event->refresh()->logo_path)->toBeNull();
    Storage::assertMissing('logos/original.png');
});

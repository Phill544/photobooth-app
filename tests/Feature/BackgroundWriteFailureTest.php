<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// The twin of LogoWriteFailureTest. The disk is built with 'throw' => false, so
// a refused write comes back as a bare false with nothing logged — recording
// that as the path would leave the host with a column pointing at nothing and
// every strip of the night composed against a background that isn't there.

beforeEach(function () {
    Storage::fake();
    $this->owner = User::factory()->create();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'owner_id' => $this->owner->id]);
    $this->actingAs($this->owner);
});

it('keeps the old background when the disk refuses the replacement', function () {
    $this->event->update(['background_path' => 'backgrounds/original.png']);
    Storage::put('backgrounds/original.png', 'the original');

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('putFileAs')->once()->andReturn(false);
    Storage::shouldReceive('disk')->andReturn($disk);
    Storage::shouldReceive('delete')->never();

    $this->patch('/events/PARTY2', [
        'name' => 'Summer Party',
        'background' => UploadedFile::fake()->image('new.png', 300, 700),
    ])->assertStatus(503);

    expect($this->event->refresh()->background_path)->toBe('backgrounds/original.png');
});

it('drops the old file only once the replacement is safely stored', function () {
    $this->event->update(['background_path' => 'backgrounds/original.png']);
    Storage::put('backgrounds/original.png', 'the original');

    $this->patch('/events/PARTY2', [
        'name' => 'Summer Party',
        'background' => UploadedFile::fake()->image('new.png', 300, 700),
    ])->assertRedirect('/events/PARTY2#edit');

    Storage::assertMissing('backgrounds/original.png');
    Storage::assertExists($this->event->refresh()->background_path);
});

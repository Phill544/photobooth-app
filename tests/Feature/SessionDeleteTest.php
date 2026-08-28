<?php

use App\Models\Event;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
});

it('deletes a whole session with its files', function () {
    $group = fake()->uuid();
    uploadPhoto('PARTY2', ['group' => $group, 'kind' => 'strip', 'slot' => 0]);
    uploadPhoto('PARTY2', ['group' => $group, 'slot' => 1]);
    $unrelated = uploadPhoto('PARTY2')->json('id');
    $paths = Photo::where('group_uuid', $group)->pluck('path');

    $this->delete("/e/PARTY2/groups/$group")->assertRedirect('/e/PARTY2/gallery');

    expect(Photo::where('group_uuid', $group)->count())->toBe(0)
        ->and(Photo::find($unrelated))->not->toBeNull();
    $paths->each(fn (string $path) => Storage::assertMissing($path));
});

it('cannot delete a session through a different event', function () {
    Event::create(['name' => 'Other Party', 'code' => 'OTHER2']);
    $group = fake()->uuid();
    uploadPhoto('OTHER2', ['group' => $group]);

    $this->delete("/e/PARTY2/groups/$group")->assertNotFound();

    expect(Photo::where('group_uuid', $group)->count())->toBe(1);
});

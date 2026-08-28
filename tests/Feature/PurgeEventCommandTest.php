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

it('purges a whole event with its photos and files', function () {
    uploadPhoto('PARTY2');
    $path = Photo::sole()->path;

    $this->artisan('photobooth:purge-event PARTY2')
        ->expectsConfirmation("Delete 'Summer Party' and its 1 photos?", 'yes')
        ->assertSuccessful();

    expect(Event::count())->toBe(0)
        ->and(Photo::count())->toBe(0);
    Storage::assertMissing($path);
});

it('leaves the event alone when the confirmation is declined', function () {
    uploadPhoto('PARTY2');
    $path = Photo::sole()->path;

    $this->artisan('photobooth:purge-event PARTY2')
        ->expectsConfirmation("Delete 'Summer Party' and its 1 photos?", 'no')
        ->assertSuccessful();

    expect(Event::count())->toBe(1)
        ->and(Photo::count())->toBe(1);
    Storage::assertExists($path);
});

// A scheduled purge has nobody to answer the prompt, so it must be skippable.
it('purges without a prompt when forced', function () {
    uploadPhoto('PARTY2');
    $path = Photo::sole()->path;

    $this->artisan('photobooth:purge-event PARTY2 --force')->assertSuccessful();

    expect(Event::count())->toBe(0);
    Storage::assertMissing($path);
});

it('deletes the logo file as well as the photos', function () {
    Storage::put('logos/party.png', 'bytes');
    $this->event->update(['logo_path' => 'logos/party.png']);

    $this->artisan('photobooth:purge-event PARTY2 --force')->assertSuccessful();

    Storage::assertMissing('logos/party.png');
});

it('takes the code in any case', function () {
    $this->artisan('photobooth:purge-event party2 --force')->assertSuccessful();

    expect(Event::count())->toBe(0);
});

it('fails on an unknown code', function () {
    $this->artisan('photobooth:purge-event NOPE99 --force')->assertFailed();

    expect(Event::count())->toBe(1);
});

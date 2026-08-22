<?php

use App\Models\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
});

it('shows uploaded photos newest first', function () {
    $first = uploadPhoto('PARTY2', ['slot' => 1])->json('id');
    $second = uploadPhoto('PARTY2', ['slot' => 2])->json('id');

    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee('Summer Party')
        ->assertSeeInOrder(["/e/PARTY2/photos/$second", "/e/PARTY2/photos/$first"]);
});

it('does not show photos from other events', function () {
    Event::create(['name' => 'Other Party', 'code' => 'OTHER2']);
    $other = uploadPhoto('OTHER2')->json('id');

    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertDontSee("photos/$other");
});

it('groups a session together with the strip first', function () {
    $group = fake()->uuid();
    $first = uploadPhoto('PARTY2', ['group' => $group, 'slot' => 1])->json('id');
    $second = uploadPhoto('PARTY2', ['group' => $group, 'slot' => 2])->json('id');
    $strip = uploadPhoto('PARTY2', ['group' => $group, 'kind' => 'strip', 'slot' => 0])->json('id');

    // The strip leads its session even though it uploaded last.
    $this->get('/e/PARTY2/gallery')
        ->assertSeeInOrder(["photos/$strip", "photos/$first", "photos/$second"]);
});

it('404s for an unknown event code', function () {
    $this->get('/e/XXXXXX/gallery')->assertNotFound();
});

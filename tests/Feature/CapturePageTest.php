<?php

use App\Models\Event;
use Illuminate\Support\Facades\Storage;

it('serves the capture page for a valid event code', function () {
    $event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);

    $this->get('/e/PARTY2')
        ->assertOk()
        ->assertSee('Summer Party');
});

it('accepts the event code in any casing', function () {
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);

    $this->get('/e/party2')->assertOk();
});

// The booth's tally sits right under "3 photos. One strip." — so the two
// numbers have to mean the same thing the guest was just promised.
it('tallies strips and shots separately on the booth screen', function () {
    Storage::fake();
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
    $group = fake()->uuid();
    uploadPhoto('PARTY2', ['group' => $group, 'kind' => 'strip', 'slot' => 0]);
    uploadPhoto('PARTY2', ['group' => $group, 'slot' => 1]);
    uploadPhoto('PARTY2', ['group' => $group, 'slot' => 2]);

    $this->get('/e/PARTY2')->assertOk()->assertSee('1 strip shot · 2 photos');
});

it('404s for an unknown event code', function () {
    $this->get('/e/XXXXXX')->assertNotFound();
});

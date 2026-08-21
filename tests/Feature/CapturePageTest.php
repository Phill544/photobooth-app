<?php

use App\Models\Event;

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

it('404s for an unknown event code', function () {
    $this->get('/e/XXXXXX')->assertNotFound();
});

<?php

use App\Models\Event;

it('generates a 6-character join code when an event is created', function () {
    $event = Event::create(['name' => 'Summer Party']);

    expect($event->code)->toHaveLength(6);
});

it('only uses unambiguous characters in codes', function () {
    // No 0, O, 1, I — codes get yelled across rooms and typed by hand.
    foreach (range(1, 20) as $i) {
        $event = Event::create(['name' => "Event $i"]);

        expect($event->code)->toMatch('/^[A-HJ-NP-Z2-9]{6}$/');
    }
});

it('keeps an explicitly given code', function () {
    $event = Event::create(['name' => 'Fixed Code Party', 'code' => 'PARTY2']);

    expect($event->code)->toBe('PARTY2');
});

it('stores an explicitly given code uppercased so lookups always match', function () {
    Event::create(['name' => 'Lowercase Party', 'code' => 'party2']);

    $this->get('/e/party2')->assertOk();
    $this->get('/e/PARTY2')->assertOk();
});

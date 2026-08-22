<?php

use App\Models\Event;

beforeEach(function () {
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
});

it('allows a healthy run of uploads then throttles the event', function () {
    foreach (range(1, 59) as $i) {
        $this->post('/e/PARTY2/photos', []);
    }

    $this->post('/e/PARTY2/photos', [])->assertStatus(302); // 60th: invalid but allowed
    $this->post('/e/PARTY2/photos', [])->assertStatus(429); // 61st: throttled
});

it('keeps a separate upload budget per event', function () {
    Event::create(['name' => 'Other Party', 'code' => 'OTHER2']);

    foreach (range(1, 61) as $i) {
        $this->post('/e/PARTY2/photos', []);
    }

    // Two events at the same venue must not starve each other.
    $this->post('/e/OTHER2/photos', [])->assertStatus(302);
});

it('cannot be bypassed by spoofing the forwarded ip', function () {
    foreach (range(1, 61) as $i) {
        $this->post('/e/PARTY2/photos', [], ['X-Forwarded-For' => "10.0.0.$i"]);
    }

    $this->post('/e/PARTY2/photos', [], ['X-Forwarded-For' => '10.9.9.9'])->assertStatus(429);
});

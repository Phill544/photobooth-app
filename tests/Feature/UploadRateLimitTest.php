<?php

use App\Models\Event;

it('rate limits uploads per ip', function () {
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);

    foreach (range(1, 30) as $i) {
        $this->post('/e/PARTY2/photos', []); // invalid, but still counts toward the limit
    }

    $this->post('/e/PARTY2/photos', [])->assertStatus(429);
});

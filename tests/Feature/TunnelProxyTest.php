<?php

use App\Models\Event;

// The phone reaches dev through an HTTPS tunnel (cloudflared) that talks plain
// HTTP to artisan serve. If the forwarded proto is ignored, Laravel renders
// absolute http:// asset URLs into the https page — mixed content the phone
// silently blocks, so no JS loads and the capture page is dead.
it('generates https urls when a proxy forwards an https request', function () {
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);

    $this->get('/e/PARTY2', ['X-Forwarded-Proto' => 'https'])->assertOk();

    expect(request()->isSecure())->toBeTrue()
        ->and(url('/build/anything.js'))->toStartWith('https://');
});

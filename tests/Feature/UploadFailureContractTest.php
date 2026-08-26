<?php

use App\Models\Event;
use Illuminate\Support\Facades\Storage;

// The booth branches on the status of a refused upload to decide what to tell
// the guest and whether to try again (resources/js/upload.ts). These three
// statuses are the contract behind that, so they get pinned here.

beforeEach(function () {
    Storage::fake();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
});

it('answers an upload to a closed booth with 410', function () {
    $this->event->update(['closed_at' => now()]);

    boothUpload('PARTY2')->assertStatus(410);
});

it('answers an unacceptable file with 422, not a redirect', function () {
    boothUpload('PARTY2', ['kind' => 'sideways'])->assertStatus(422);
});

it('answers a throttled event with 429 and says how long to wait', function () {
    foreach (range(1, 60) as $i) {
        boothUpload('PARTY2', ['kind' => 'sideways']); // rejected, but it still spends the budget
    }

    $response = boothUpload('PARTY2')->assertStatus(429);

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

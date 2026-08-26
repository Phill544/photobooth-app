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

// The album header's counts have to agree with what the tabs below them render:
// the strips tab shows one card per strip, the all-photos tab only originals.
it('counts strips and shots separately in the album header', function () {
    $group = fake()->uuid();
    uploadPhoto('PARTY2', ['group' => $group, 'kind' => 'strip', 'slot' => 0]);
    uploadPhoto('PARTY2', ['group' => $group, 'slot' => 1]);
    uploadPhoto('PARTY2', ['group' => $group, 'slot' => 2]);

    // The header stats, not the per-session card, which also says "2 photos".
    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee('<span class="sr-only">1 strip</span>', false)
        ->assertSee('<span class="sr-only">2 photos</span>', false);
});

it('404s for an unknown event code', function () {
    $this->get('/e/XXXXXX/gallery')->assertNotFound();
});

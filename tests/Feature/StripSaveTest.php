<?php

use App\Models\Event;
use Illuminate\Support\Str;

beforeEach(function () {
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
});

// The invite row is on this page three times — start, done, in-app — so a count
// over the whole document cannot see which of them changed. Everything from the
// done screen to the section after it is the only part worth counting.
function doneScreen(string $page): string
{
    return Str::between($page, 'id="done-screen"', 'id="camera-lost-screen"');
}

// `navigator.share` has no target hint — the sheet belongs to the OS, and nothing
// the booth can say will lift Save to the top of it. So the done screen stops
// trying: Save saves, Share shares, and both are on screen at once. The
// long-press line went with the either/or it belonged to.
it('saves the strip straight off the done screen, with no long-press fallback', function () {
    $this->get('/e/PARTY2')
        ->assertOk()
        ->assertDontSee('Long-press the strip above')
        ->assertDontSee('id="save-fallback"', false)
        ->assertDontSee('id="save-download"', false);
});

// An anchor, not a button: capture.ts arms it by setting href and download, which
// a <button> would take as inert properties and then save nothing at all. And it
// ships disabled, because until there is a blob to point at it is a control that
// would silently do nothing.
it('makes the done-screen save a real download link, disabled for the script to arm', function () {
    $this->get('/e/PARTY2')
        ->assertOk()
        ->assertSee('<a id="save-strip" class="btn btn--hero" download aria-disabled="true">', false);
});

// Share sits in the stack under Save, not down in the row Copy link left: it
// hands over the strip, and that row hands over the event.
it('offers sharing the strip as its own button under the save', function () {
    $this->get('/e/PARTY2')
        ->assertOk()
        ->assertSee('Share it')
        ->assertSeeInOrder(['id="done-screen"', 'id="save-strip"', 'id="share-strip"', 'class="share invite"'], false);
});

// Copy link leaves the done screen and nowhere else. The start screen is where a
// guest is sent to pass the booth on, and the in-app screen has no share sheet to
// offer at all — on the done screen "Invite others" already hands the link over.
it('drops copy link from the done screen alone', function () {
    $page = $this->get('/e/PARTY2')->assertOk()->getContent();

    expect(substr_count(doneScreen($page), 'data-copy='))->toBe(0)
        ->and(substr_count($page, 'data-copy='))->toBe(2); // the start and in-app screens keep theirs
});

// What has to survive that removal: the invite button, and under it the raw URL
// for every browser that hides the invite button (share-script drops it where
// there is no navigator.share). One of the two always hands the link over.
it('keeps the invite button and the raw URL on the done screen', function () {
    $done = doneScreen($this->get('/e/PARTY2')->assertOk()->getContent());

    expect(substr_count($done, 'data-share-url='))->toBe(1)
        ->and(substr_count($done, 'class="link-chip"'))->toBe(1);
});

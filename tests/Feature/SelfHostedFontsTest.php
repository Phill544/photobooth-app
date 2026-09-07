<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

// Every page in this app loaded a render-blocking stylesheet from Google and its
// faces from another Google host, which meant every guest's IP address and
// User-Agent reached a third party in the United States *before* the camera
// opened and before any notice was shown. The fonts are ours now.
//
// This is a privacy test, not a performance one: it exists so that a future
// change adding a Google Fonts <link> back — or a fourth family pulled in from a
// CDN "just for now" — fails here rather than quietly reopening a disclosure the
// privacy policy says the app does not make.

beforeEach(function () {
    Storage::fake();
    $this->owner = User::factory()->create();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'owner_id' => $this->owner->id]);
});

dataset('every page a stranger can reach', [
    'home' => '/',
    'booth' => '/e/PARTY2',
    'album' => '/e/PARTY2/gallery',
    'login' => '/login',
    'register' => '/register',
    'forgot password' => '/forgot-password',
]);

it('reaches no third-party origin for its fonts', function (string $path) {
    $page = $this->get($path)->assertOk();

    $page->assertDontSee('fonts.googleapis.com', false)
        ->assertDontSee('fonts.gstatic.com', false);
})->with('every page a stranger can reach');

// The whole point is that the bytes come from us, so the rules have to name a
// same-origin path and the files have to actually be there.
it('serves its faces from this origin', function () {
    $this->get('/e/PARTY2')
        ->assertOk()
        ->assertSee('@font-face', false)
        ->assertSee('/fonts/', false);
});

it('ships every font file the stylesheet asks for', function () {
    $css = $this->get('/')->getContent();

    preg_match_all('#/fonts/[a-z0-9.-]+\.woff2#', $css, $matches);
    $referenced = array_unique($matches[0]);

    expect($referenced)->not->toBeEmpty('no local font files are referenced at all');

    foreach ($referenced as $path) {
        expect(public_path(ltrim($path, '/')))->toBeFile("$path is referenced but not shipped");
    }
});

// Instrument Sans is a VARIABLE font: Google's stylesheet points weights 400,
// 500 and 600 at one file and lets three @font-face rules share it. The first
// pass of the generator downloaded that file once per weight and gave each copy
// its own name, so a phone fetched 90KB of identical bytes where 30KB would do —
// worse than the Google setup it replaced, because the browser caches by URL.
//
// Pinned by content rather than by filename: whatever a future regeneration
// names things, two files with the same bytes mean the same resource is being
// fetched twice.
it('ships no two font files with identical bytes', function () {
    $byHash = [];

    foreach (glob(public_path('fonts/*.woff2')) as $file) {
        $byHash[md5_file($file)][] = basename($file);
    }

    $duplicated = array_filter($byHash, fn ($names) => count($names) > 1);

    expect($duplicated)->toBeEmpty(
        'these are the same font saved under different names: '
        .implode(' | ', array_map(fn ($n) => implode(', ', $n), $duplicated))
    );
});

// SIL Open Font License 1.1 permits self-hosting and requires the licence to
// travel with the fonts. Shipping the files without it is the one way to get
// this slice wrong quietly.
it('ships the licence beside the fonts', function () {
    expect(public_path('fonts/OFL.txt'))->toBeFile();
});

// Google's stylesheet split each family by unicode-range so a page only fetched
// latin-ext when it actually contained those characters. Dropping the ranges
// would make every visitor pay for both.
it('keeps the unicode ranges that stop latin-ext downloading for everyone', function () {
    $this->get('/')->assertOk()->assertSee('unicode-range:', false);
});

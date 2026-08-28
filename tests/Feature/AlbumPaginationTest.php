<?php

use App\Http\Controllers\EventController;
use App\Models\Event;
use App\Models\Photo;
use Illuminate\Testing\TestResponse;

// A 4000-photo album used to render every strip and every original into one
// page: 3997 <img> tags and 1.6MB of HTML, which on serverless is thousands of
// invocations for one view. The album now hands out a page of sessions at a
// time, and the page that asked follows the link for the next one.

beforeEach(function () {
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);
});

// Rows, not uploads: these tests are about how many sessions a page carries,
// and an HTTP upload per photo would spend a minute proving it.
function seedSession(Event $event, int $shots = 2): string
{
    $group = fake()->uuid();
    $event->photos()->create(['kind' => 'strip', 'group_uuid' => $group, 'slot' => 0, 'path' => "p/{$group}-strip.jpg"]);
    foreach (range(1, $shots) as $slot) {
        $event->photos()->create(['kind' => 'original', 'group_uuid' => $group, 'slot' => $slot, 'path' => "p/{$group}-{$slot}.jpg"]);
    }

    return $group;
}

function seedSessions(Event $event, int $count): array
{
    return collect(range(1, $count))->map(fn () => seedSession($event))->all();
}

// The link the album offers for the next page — the same one the infinite
// scroll follows, so the tests exercise the contract the browser uses.
function nextPageUrl(TestResponse $response): ?string
{
    preg_match('/<a id="more"[^>]*href="([^"]*)"/', $response->getContent(), $matches);

    return isset($matches[1]) ? html_entity_decode($matches[1]) : null;
}

it('renders only the first page of sessions', function () {
    $groups = seedSessions($this->event, EventController::SESSIONS_PER_PAGE + 3);

    $response = $this->get('/e/PARTY2/gallery')->assertOk();

    // Newest first: the three oldest sessions are over the page boundary.
    foreach (array_slice($groups, 0, 3) as $missing) {
        $response->assertDontSee($missing);
    }
    foreach (array_slice($groups, 3) as $present) {
        $response->assertSee($present);
    }
});

it('offers a next page while there are more sessions', function () {
    seedSessions($this->event, EventController::SESSIONS_PER_PAGE + 1);

    expect(nextPageUrl($this->get('/e/PARTY2/gallery')))->not->toBeNull();
});

it('offers no next page once the album fits', function () {
    seedSessions($this->event, EventController::SESSIONS_PER_PAGE);

    expect(nextPageUrl($this->get('/e/PARTY2/gallery')))->toBeNull();
});

it('offers no next page for an empty album', function () {
    expect(nextPageUrl($this->get('/e/PARTY2/gallery')))->toBeNull();
});

it('picks up where the first page stopped', function () {
    $groups = seedSessions($this->event, EventController::SESSIONS_PER_PAGE + 3);

    $first = $this->get('/e/PARTY2/gallery');
    $second = $this->get(nextPageUrl($first))->assertOk();

    // The oldest three, and nothing the first page already rendered.
    foreach (array_slice($groups, 0, 3) as $present) {
        $second->assertSee($present);
    }
    foreach (array_slice($groups, 3) as $missing) {
        $second->assertDontSee($missing);
    }
    expect(nextPageUrl($second))->toBeNull();
});

// The cursor is the session boundary, not a row offset: a session that arrives
// while a guest is scrolling must not shunt a card onto a second page as well.
it('does not repeat a session when the album grows mid-scroll', function () {
    $groups = seedSessions($this->event, EventController::SESSIONS_PER_PAGE + 1);

    $first = $this->get('/e/PARTY2/gallery');
    $next = nextPageUrl($first);
    seedSessions($this->event, 5); // five more guests share while the phone scrolls

    $second = $this->get($next)->assertOk();

    foreach (array_slice($groups, 1) as $alreadySeen) {
        $second->assertDontSee($alreadySeen);
    }
    $second->assertSee($groups[0]);
});

it('never splits a session across a page boundary', function () {
    // A six-row session right under a page that is exactly full: the cut has to
    // fall between sessions, so all six rows travel to the second page together.
    $straddler = seedSession($this->event, shots: 5);
    seedSessions($this->event, EventController::SESSIONS_PER_PAGE);

    $first = $this->get('/e/PARTY2/gallery')->assertDontSee($straddler);
    $second = $this->get(nextPageUrl($first))->assertOk();
    $ids = Photo::where('group_uuid', $straddler)->pluck('id');

    expect($ids)->toHaveCount(6);
    foreach ($ids as $id) {
        $second->assertSee("/e/PARTY2/photos/{$id}", false);
    }
});

// A strip upload can be refused while the shots behind it land. Paginating on
// strips would drop that guest's photos out of the album entirely.
it('pages a session that never got its strip', function () {
    $group = fake()->uuid();
    $this->event->photos()->create(['kind' => 'original', 'group_uuid' => $group, 'slot' => 1, 'path' => 'p/lone.jpg']);

    $this->get('/e/PARTY2/gallery')->assertOk()->assertSee($group);
});

// The host can delete the last session while a guest's phone is still holding
// a link to it. That page is the end of the album, not an empty one — and it
// still has to carry the panels the scroll pours into, or the guest is left
// tapping a Load more that can never work.
it('ends the album gracefully when a cursor outlives the sessions behind it', function () {
    $groups = seedSessions($this->event, EventController::SESSIONS_PER_PAGE + 1);
    $next = nextPageUrl($this->get('/e/PARTY2/gallery'));
    Photo::where('group_uuid', $groups[0])->delete();

    $this->get($next)
        ->assertOk()
        ->assertDontSee('No photos yet')
        ->assertSee("That's the whole album.", false)
        ->assertSee('id="panel-strips"', false)
        ->assertSee('id="panel-photos"', false);
});

it('still says an empty album is empty', function () {
    $this->get('/e/PARTY2/gallery')->assertOk()->assertSee('No photos yet');
});

it('counts the whole album in the header, not the page', function () {
    seedSessions($this->event, EventController::SESSIONS_PER_PAGE + 2);
    $total = EventController::SESSIONS_PER_PAGE + 2;

    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee('<span class="sr-only">'.$total.' strips</span>', false)
        ->assertSee('<span class="sr-only">'.($total * 2).' photos</span>', false);
});

// The originals are the bulk of the tags — three or four per strip — so the
// second panel has to be windowed by the same cursor, not just the first.
it('windows the all-photos panel too', function () {
    seedSessions($this->event, EventController::SESSIONS_PER_PAGE + 4);

    $tiles = substr_count($this->get('/e/PARTY2/gallery')->getContent(), 'data-name="Event photo"');

    expect($tiles)->toBe(EventController::SESSIONS_PER_PAGE * 2);
});

it('reverses the album with ?order=oldest', function () {
    $groups = seedSessions($this->event, EventController::SESSIONS_PER_PAGE + 3);

    $response = $this->get('/e/PARTY2/gallery?order=oldest')->assertOk();

    // The first three shared are now the top of the page, and the last three
    // are the ones over the boundary.
    foreach (array_slice($groups, 0, 3) as $present) {
        $response->assertSee($present);
    }
    foreach (array_slice($groups, -3) as $missing) {
        $response->assertDontSee($missing);
    }
});

it('keeps the order when it follows the next page', function () {
    $groups = seedSessions($this->event, EventController::SESSIONS_PER_PAGE + 3);

    $second = $this->get(nextPageUrl($this->get('/e/PARTY2/gallery?order=oldest')))->assertOk();

    foreach (array_slice($groups, -3) as $present) {
        $second->assertSee($present);
    }
});

it('offers the flip as a link, so it reorders the album and not just the page', function () {
    seedSessions($this->event, 2);

    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee('href="/e/PARTY2/gallery?order=oldest"', false);

    $this->get('/e/PARTY2/gallery?order=oldest')
        ->assertOk()
        ->assertSee('href="/e/PARTY2/gallery"', false);
});

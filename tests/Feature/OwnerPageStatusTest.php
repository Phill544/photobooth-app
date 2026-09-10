<?php

use App\Jobs\BuildEventArchive;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// Every control on the owner page used to redirect to the top of a long page
// with nothing to say it worked. These pin the other half of the bargain: the
// POST comes back to the fold it came from, and that fold says what happened.

beforeEach(function () {
    Storage::fake();
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'owner_id' => $this->owner->id]);
});

// --- Each POST lands back on its own fold, with a line ---

it('sends a saved look back to the edit fold', function () {
    $this->patch('/events/PARTY2', ['name' => 'Sarah 30'])
        ->assertRedirect('/events/PARTY2#edit')
        ->assertSessionHas('fold', 'edit')
        ->assertSessionHas('status');
});

// A failed save is the case that needed it most: the fold opens itself on an
// error, and without the fragment the host lands a screen and a half above it.
it('sends a rejected look back to the edit fold too', function () {
    $this->patch('/events/PARTY2', ['name' => 'Summer Party', 'theme' => 'neon'])
        ->assertInvalid(['theme'])
        ->assertRedirect('/events/PARTY2#edit');
});

it('sends a closed booth back to the booth row, and says which way it went', function () {
    $this->post('/events/PARTY2/toggle-closed')
        ->assertRedirect('/events/PARTY2#booth')
        ->assertSessionHas('fold', 'booth')
        ->assertSessionHas('status', 'Closed just now.');

    $this->post('/events/PARTY2/toggle-closed')
        ->assertRedirect('/events/PARTY2#booth')
        ->assertSessionHas('status', 'Reopened just now.');
});

// The row already carries a sentence about the booth's state, so a status that
// restated it would have the page saying the same thing twice in one line.
it('confirms the toggle without restating the line beside it', function () {
    $this->post('/events/PARTY2/toggle-closed');

    // "The booth" is a link, so the state sentence is not contiguous in the
    // markup — the half after it is what tells the two lines apart.
    $this->get('/events/PARTY2')
        ->assertSee('Closed just now.')
        ->assertSee('is closed — guests can still see the album.');
});

it('sends a privacy change back to the privacy fold, naming the setting', function () {
    $this->post('/events/PARTY2/privacy', ['album_privacy' => 'pin', 'album_pin' => 'bridesmaids'])
        ->assertRedirect('/events/PARTY2#privacy')
        ->assertSessionHas('fold', 'privacy')
        ->assertSessionHas('status', fn (string $status) => str_contains($status, Event::ALBUM_PRIVACY['pin']));
});

it('sends a retention change back to the retention fold, naming the date', function () {
    $keepUntil = now()->addDays(200);

    $this->post('/events/PARTY2/retention', ['photos_expire_at' => $keepUntil->toDateString()])
        ->assertRedirect('/events/PARTY2#retention')
        ->assertSessionHas('fold', 'retention')
        ->assertSessionHas('status', fn (string $status) => str_contains($status, $keepUntil->format('j M Y')));
});

it('says so when the host clears the date instead of naming one', function () {
    uploadPhoto('PARTY2');

    $this->post('/events/PARTY2/retention', ['photos_expire_at' => ''])
        ->assertRedirect('/events/PARTY2#retention')
        ->assertSessionHas('status', fn (string $status) => str_contains($status, 'for good'));
});

// An empty date means two different things, which is the whole reason
// Event::awaitingFirstPhoto() exists and the fold's own summary renders three
// states off it. A booth nobody has shot into is not being kept for good — its
// clock has not started — so a confirmation saying it is contradicts the hint
// an inch above it, and the host who believes it loses the album on day 90.
it('does not promise for-good on an album whose clock has not started', function () {
    expect($this->event->awaitingFirstPhoto())->toBeTrue();

    $this->post('/events/PARTY2/retention', ['photos_expire_at' => ''])
        ->assertRedirect('/events/PARTY2#retention')
        ->assertSessionHas('status', fn (string $status) => ! str_contains($status, 'for good')
            && str_contains($status, 'first photo'));
});

it('sends an archive build back to the archive panel', function () {
    Queue::fake();
    uploadPhoto('PARTY2');

    $this->post('/events/PARTY2/archive')
        ->assertRedirect('/events/PARTY2#archive')
        ->assertSessionHas('fold', 'archive')
        ->assertSessionHas('status');

    Queue::assertPushed(BuildEventArchive::class);
});

// The refusal is a redirect too, and it carries an error rather than a status —
// so it has to reach the same panel or the message is off-screen.
it('sends a refused archive back to the archive panel', function () {
    $this->post('/events/PARTY2/archive')
        ->assertInvalid(['archive'])
        ->assertRedirect('/events/PARTY2#archive');
});

// --- The fold the host was sent to is the one that is open when they land ---

it('renders the fold named by the flash open, with its status inside it', function () {
    $this->withSession(['fold' => 'retention', 'status' => 'Photos are kept for good.'])
        ->get('/events/PARTY2')
        ->assertOk()
        ->assertSee('id="retention" open', false)
        ->assertSee('Photos are kept for good.');
});

it('leaves the other folds shut', function () {
    $this->withSession(['fold' => 'retention', 'status' => 'Photos are kept for good.'])
        ->get('/events/PARTY2')
        ->assertOk()
        ->assertDontSee('id="privacy" open', false)
        ->assertDontSee('id="edit" open', false)
        ->assertDontSee('id="delete" open', false);
});

// One status, in one place. A message rendered in every fold is a page that
// claims four things happened when one did.
it('puts the status only in the fold it belongs to', function () {
    $this->withSession(['fold' => 'privacy', 'status' => 'Only you can open the album.'])
        ->get('/events/PARTY2')
        ->assertOk()
        ->assertSeeInOrder(['id="privacy" open', 'Only you can open the album.'], false)
        ->assertDontSee('id="retention" open', false);
});

it('opens no fold and confirms nothing when nothing was flashed', function () {
    $this->get('/events/PARTY2')
        ->assertOk()
        ->assertDontSee(' open>', false)
        // The element, not a sentence: "The booth is closed" is split by the
        // link inside it and so can never be found whatever the page says.
        ->assertDontSee('class="status"', false);
});

// A validation error still owns the fold it came from, flash or no flash.
it('keeps opening the fold an error came from', function () {
    $this->post('/events/PARTY2/privacy', ['album_privacy' => 'pin', 'album_pin' => '']);

    $this->get('/events/PARTY2')->assertSee('id="privacy" open', false);
});

// --- The fragment a link carries, for the pages that link here ---

// The expired album's "give it more time" is a plain GET from another page, so
// no flash can open it — the fragment is all the page gets, which is why the
// script exists. Both halves pinned together: a link to a fold that isn't there
// and a fold nothing opens fail the host the same way.
it('gives the expired albums give-it-more-time link a fold to land on', function () {
    uploadPhoto('PARTY2');
    $this->event->update(['photos_expire_at' => now()->subDay()]);

    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee('/events/PARTY2#retention', false);

    $this->get('/events/PARTY2')
        ->assertOk()
        ->assertSee('id="retention"', false)
        ->assertSee('hashchange', false);
});

// --- The dashboard finally renders what verification flashes at it ---

it('shows the dashboard a status that was flashed to it', function () {
    $this->withSession(['status' => 'Address confirmed.'])
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Address confirmed.');
});

// Verification redirects to the host's *intended* page, and for the journey
// that matters that is /new — they were sent to verify by being turned away
// from it. Rendering the line only on the dashboard fixes the rarer half.
it('shows the same status on the page verification actually lands on', function () {
    $this->owner->markEmailAsVerified();

    $this->withSession(['status' => 'Address confirmed.'])
        ->get('/new')
        ->assertOk()
        ->assertSee('Address confirmed.');
});

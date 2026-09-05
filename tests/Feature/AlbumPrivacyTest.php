<?php

use App\Models\Event;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->owner = User::factory()->create();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'owner_id' => $this->owner->id]);
});

// The setting every existing album already has: nothing changes underneath a
// live event because a column arrived.
it('leaves an album open to anyone with the code by default', function () {
    $id = uploadPhoto('PARTY2')->json('id');

    expect($this->event->refresh()->album_privacy)->toBe('open');

    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee("photos/$id", false);
});

// --- Hidden: the host's own album ---

it('refuses a guest a hidden album and says why', function () {
    $id = uploadPhoto('PARTY2')->json('id');
    $this->event->update(['album_privacy' => 'hidden']);

    $this->get('/e/PARTY2/gallery')
        ->assertForbidden()
        ->assertSee('private')
        ->assertDontSee("photos/$id", false);
});

it('still shows a hidden album to the host and to an admin', function () {
    $id = uploadPhoto('PARTY2')->json('id');
    $this->event->update(['album_privacy' => 'hidden']);

    $this->actingAs($this->owner)->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee("photos/$id", false);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get('/e/PARTY2/gallery')->assertOk();
});

// Offering a guest a door that answers 403 is worse than not offering it.
it('drops the album links from the booth when the album is hidden', function () {
    $this->event->update(['album_privacy' => 'hidden']);

    $this->get('/e/PARTY2')
        ->assertOk()
        ->assertDontSee('/e/PARTY2/gallery', false);
});

it('keeps the album links in the booth when the album only wants a PIN', function () {
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    $this->get('/e/PARTY2')->assertOk()->assertSee('/e/PARTY2/gallery', false);
});

// --- PIN ---

it('asks for the PIN instead of showing the photos', function () {
    $id = uploadPhoto('PARTY2')->json('id');
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee('name="pin"', false)
        ->assertDontSee("photos/$id", false)
        // The PIN is the host's to read out, never the page's to give away.
        ->assertDontSee('bridesmaids');
});

it('opens the album for the rest of the session once the PIN is right', function () {
    $id = uploadPhoto('PARTY2')->json('id');
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    $this->post('/e/PARTY2/gallery/unlock', ['pin' => 'bridesmaids'])
        ->assertRedirect('/e/PARTY2/gallery');

    $this->get('/e/PARTY2/gallery')->assertOk()->assertSee("photos/$id", false);
});

it('sends the guest back to the page of the album they were on', function () {
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    // The gate carries the page it was standing in front of, so the form posts
    // it back and the unlock hands the guest the album they were reading.
    $this->get('/e/PARTY2/gallery?order=oldest&after=40')
        ->assertOk()
        ->assertSee('/e/PARTY2/gallery/unlock?order=oldest&amp;after=40', false);

    $this->post('/e/PARTY2/gallery/unlock?order=oldest&after=40', ['pin' => 'bridesmaids'])
        ->assertRedirect('/e/PARTY2/gallery?order=oldest&after=40');
});

it('refuses the wrong PIN and keeps the album shut', function () {
    $id = uploadPhoto('PARTY2')->json('id');
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    $this->post('/e/PARTY2/gallery/unlock', ['pin' => 'groomsmen'])
        ->assertInvalid(['pin']);

    $this->get('/e/PARTY2/gallery')->assertOk()->assertDontSee("photos/$id", false);
});

// A PIN gets read across a room, so it arrives in whatever case and with
// whatever spaces the guest's keyboard added.
it('takes the PIN in any case, with stray spaces', function () {
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'Bridesmaids']);

    $this->post('/e/PARTY2/gallery/unlock', ['pin' => '  BRIDESMAIDS '])
        ->assertRedirect('/e/PARTY2/gallery');

    $this->get('/e/PARTY2/gallery')->assertOk();
});

it('does not make the host type the PIN for their own album', function () {
    $id = uploadPhoto('PARTY2')->json('id');
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    $this->actingAs($this->owner)->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee("photos/$id", false);
});

// One unlock is one album's. The session carries them separately, or a guest at
// two events walks into the second one.
it('does not open a second album with the first unlock', function () {
    // The same PIN on both, or this passes on the PINs differing rather than on
    // the key being per event — which is the whole claim.
    Event::create(['name' => 'Other Party', 'code' => 'OTHER2', 'album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    $this->post('/e/PARTY2/gallery/unlock', ['pin' => 'bridesmaids']);

    $this->get('/e/OTHER2/gallery')->assertOk()->assertSee('name="pin"', false);
});

// The reason a host changes a PIN is that the wrong people have it. An unlock
// that outlives the PIN it was bought with leaves them only "Only me", which
// shuts out the guests who should be there along with the ones who shouldn't.
it('makes a guest type the new PIN when the host changes it', function () {
    $id = uploadPhoto('PARTY2')->json('id');
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    $this->post('/e/PARTY2/gallery/unlock', ['pin' => 'bridesmaids']);
    $this->get('/e/PARTY2/gallery')->assertOk()->assertSee("photos/$id", false);

    // Changed on the model, not through the host's route: actingAs persists
    // into the next request, so the guest below would walk in as the owner and
    // green a test that proves nothing.
    $this->event->update(['album_pin' => 'groomsmen']);

    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee('name="pin"', false)
        ->assertDontSee("photos/$id", false);

    $this->post('/e/PARTY2/gallery/unlock', ['pin' => 'groomsmen'])
        ->assertRedirect('/e/PARTY2/gallery');

    $this->get('/e/PARTY2/gallery')->assertOk()->assertSee("photos/$id", false);
});

// Hiding an album is how a host shuts it right now, and it must take effect
// against a guest already inside — the unlock they are holding is not consulted,
// because privacy is one column and 'hidden' is not 'pin'.
it('shuts an unlocked guest out the moment the host hides the album', function () {
    $id = uploadPhoto('PARTY2')->json('id');
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    $this->post('/e/PARTY2/gallery/unlock', ['pin' => 'bridesmaids']);
    $this->get('/e/PARTY2/gallery')->assertOk()->assertSee("photos/$id", false);

    $this->event->update(['album_privacy' => 'hidden']);

    $this->get('/e/PARTY2/gallery')->assertForbidden()->assertDontSee("photos/$id", false);
});

// Same door, same key. Putting the old PIN back is not a new secret, so a guest
// who already typed that word is not made to type it again — changing the PIN
// is what locks somebody out, and hiding the album is what locks everybody out.
it('lets a guest back in when the host hides an album and puts the same PIN back', function () {
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);
    $this->post('/e/PARTY2/gallery/unlock', ['pin' => 'bridesmaids']);

    $this->event->update(['album_privacy' => 'hidden']);
    $this->get('/e/PARTY2/gallery')->assertForbidden();

    $this->event->update(['album_privacy' => 'pin']);
    $this->get('/e/PARTY2/gallery')->assertOk()->assertDontSee('name="pin"', false);
});

// The stored PIN is what the fingerprint is taken from, and a host who re-saves
// the same word with a stray space or a capital has not changed it — pinMatches()
// still opens the album on the old typing, so the room must not be shut out.
it('does not evict anyone when the host re-saves the same PIN differently typed', function () {
    $id = uploadPhoto('PARTY2')->json('id');
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    $this->post('/e/PARTY2/gallery/unlock', ['pin' => 'bridesmaids']);

    $this->event->update(['album_pin' => '  Bridesmaids ']);

    $this->get('/e/PARTY2/gallery')->assertOk()->assertSee("photos/$id", false);
});

// Sessions outlive a deploy. The ones carrying the flag this replaced match no
// fingerprint, so those guests meet the door once more — the PIN still opens it,
// and being asked once beats the alternative of honouring a flag forever.
it('makes a guest unlocked before the fingerprint type the PIN again', function () {
    $id = uploadPhoto('PARTY2')->json('id');
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    $this->withSession(['album-unlocked.'.$this->event->id => true])
        ->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee('name="pin"', false)
        ->assertDontSee("photos/$id", false);
});

// Keyed on the event code like the upload limiter: a venue is one NAT address,
// so an IP key would throttle a room instead of an attacker.
it('throttles PIN guesses per event', function () {
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    foreach (range(1, 20) as $attempt) {
        $this->post('/e/PARTY2/gallery/unlock', ['pin' => 'guess'])->assertStatus(302);
    }

    $this->post('/e/PARTY2/gallery/unlock', ['pin' => 'guess'])->assertStatus(429);
});

// Changing the PIN sends a whole room back through this door inside one minute,
// which is more guests than the guess budget has room for. Only wrong ones are
// rationed, or the rotation locks out the guests it was meant to keep.
it('does not spend the guess budget on guests who type the PIN correctly', function () {
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    foreach (range(1, 40) as $guest) {
        $this->post('/e/PARTY2/gallery/unlock', ['pin' => 'bridesmaids'])
            ->assertRedirect('/e/PARTY2/gallery');
    }

    // And the budget is still there for the guesses it is actually for.
    $this->post('/e/PARTY2/gallery/unlock', ['pin' => 'guess'])->assertStatus(302);
});

// A guest types the PIN into a form, and maxlength truncates typing AND paste —
// so a gate that caps below what the host is allowed to set is a PIN nobody can
// enter. These tests POST to /unlock directly, which is exactly how the two
// drifted apart: 'bridesmaids' is 11 characters and the gate stopped at 10.
it('lets a guest type every character of a PIN the host is allowed to set', function () {
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee('maxlength="'.Event::PIN_MAX_LENGTH.'"', false);

    expect(Event::PIN_MAX_LENGTH)->toBeGreaterThanOrEqual(strlen('bridesmaids'));
});

// The limiter runs before route-model binding, so its key is the raw URL
// segment — and codes are case-insensitive, so every spelling of one resolves to
// the same album. Un-normalised, each spelling bought its own budget: a six-letter
// code is dozens of buckets, which is dozens of times the guesses the comment on
// the limiter promises.
it('counts a PIN guess against the album whatever case the URL spelled it', function () {
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    foreach (range(1, 20) as $attempt) {
        $this->post('/e/PARTY2/gallery/unlock', ['pin' => 'guess'])->assertStatus(302);
    }

    $this->post('/e/party2/gallery/unlock', ['pin' => 'guess'])->assertStatus(429);
    $this->post('/e/PaRtY2/gallery/unlock', ['pin' => 'guess'])->assertStatus(429);
});

// Phill's call (2026-08-31): the PIN gates the album page, and the image routes
// stay session-free and immutably cached exactly as P1 left them. Pinned here
// so a change to that is a decision somebody makes on purpose.
it('leaves the image routes open to anyone holding a photo URL', function () {
    $id = uploadPhoto('PARTY2')->json('id');
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    $this->get("/e/PARTY2/photos/$id")->assertOk();
});

// --- The host's control ---

it('lets the host set the album privacy and its PIN', function () {
    $this->actingAs($this->owner)
        ->post('/events/PARTY2/privacy', ['album_privacy' => 'pin', 'album_pin' => 'bridesmaids'])
        ->assertRedirect('/events/PARTY2');

    expect($this->event->refresh()->album_privacy)->toBe('pin')
        ->and($this->event->album_pin)->toBe('bridesmaids');
});

it('will not lock an album behind a PIN nobody set', function () {
    $this->actingAs($this->owner)
        ->post('/events/PARTY2/privacy', ['album_privacy' => 'pin', 'album_pin' => ''])
        ->assertInvalid(['album_pin']);

    expect($this->event->refresh()->album_privacy)->toBe('open');
});

it('holds the PIN while the album is open, so switching back does not lose it', function () {
    $this->actingAs($this->owner)->post('/events/PARTY2/privacy', ['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);
    $this->actingAs($this->owner)->post('/events/PARTY2/privacy', ['album_privacy' => 'open']);

    expect($this->event->refresh()->album_privacy)->toBe('open')
        ->and($this->event->album_pin)->toBe('bridesmaids');
});

it('shows the host the PIN, because they are the one reading it out', function () {
    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);

    $this->actingAs($this->owner)->get('/events/PARTY2')
        ->assertOk()
        ->assertSee('bridesmaids', false);
});

it('does not let a stranger change the privacy', function () {
    $this->actingAs(User::factory()->create())
        ->post('/events/PARTY2/privacy', ['album_privacy' => 'hidden'])
        ->assertForbidden();

    expect($this->event->refresh()->album_privacy)->toBe('open');
});

it('does not let a guest change the privacy', function () {
    $this->post('/events/PARTY2/privacy', ['album_privacy' => 'hidden'])->assertRedirect('/login');

    expect($this->event->refresh()->album_privacy)->toBe('open');
});

// --- What the booth promises a guest before they share ---

it('tells a guest who will see the strip they are about to share', function () {
    $this->get('/e/PARTY2')->assertSee('anyone with the link can see it');

    $this->event->update(['album_privacy' => 'pin', 'album_pin' => 'bridesmaids']);
    $this->get('/e/PARTY2')->assertSee('guests with the album PIN');

    $this->event->update(['album_privacy' => 'hidden']);
    $this->get('/e/PARTY2')->assertSee('only the host');
});

it('refuses the album without deleting anything behind it', function () {
    uploadPhoto('PARTY2');
    $this->event->update(['album_privacy' => 'hidden']);

    $this->get('/e/PARTY2/gallery');

    expect(Photo::count())->toBe(1); // the gate refuses, it does not delete
});

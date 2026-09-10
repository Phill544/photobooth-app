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

// --- The window itself ---

// Deliberately inverted (2026-09-06). The window used to be stamped in the
// `creating` hook, so a host who set a wedding up four weeks early had burned a
// month of it before a guest arrived, and the consent line promised them a date
// that was already running down. It is a promise made at the moment a guest
// consents, so it starts when there is something to keep.
it('does not start the window until there is a photo to keep', function () {
    expect($this->event->photos_expire_at)->toBeNull()
        ->and($this->event->awaitingFirstPhoto())->toBeTrue()
        ->and($this->event->hasExpired())->toBeFalse();
});

it('starts the window at the first photo, not at the setup', function () {
    $this->travelTo(now()->addDays(28)); // the wedding, four weeks after the setup

    uploadPhoto('PARTY2');

    expect($this->event->refresh()->photos_expire_at->isSameDay(now()->addDays(Event::RETENTION_DAYS)))->toBeTrue();
});

// Every guest after the first shares into an album already counting down, and
// the date they were shown is the one they keep.
it('leaves a started window where the first photo put it', function () {
    uploadPhoto('PARTY2');
    $started = $this->event->refresh()->photos_expire_at;

    $this->travelTo(now()->addDays(3));
    uploadPhoto('PARTY2', ['slot' => 2]);

    expect($this->event->refresh()->photos_expire_at->eq($started))->toBeTrue();
});

// A host who cleared the window meant it. The next guest through the booth must
// not hand it back.
it('does not restart a window the host cleared', function () {
    uploadPhoto('PARTY2');
    $this->event->update(['photos_expire_at' => null]);

    uploadPhoto('PARTY2', ['slot' => 2]);

    expect($this->event->refresh()->photos_expire_at)->toBeNull()
        ->and($this->event->awaitingFirstPhoto())->toBeFalse();
});

// The column arrives on albums whose guests were told nothing about a window,
// so it arrives empty on those and nothing they shared starts counting down.
it('leaves an event created before the window existed alone', function () {
    $this->event->update(['photos_expire_at' => null]);

    expect($this->event->refresh()->hasExpired())->toBeFalse();

    $this->get('/e/PARTY2/gallery')->assertOk();
});

it('knows when the window has run out', function () {
    expect($this->event->hasExpired())->toBeFalse();

    $this->event->update(['photos_expire_at' => now()->subMinute()]);

    expect($this->event->refresh()->hasExpired())->toBeTrue();
});

// --- What a guest meets ---

it('shows a guest an expired album instead of the photos', function () {
    $id = uploadPhoto('PARTY2')->json('id');
    $this->event->update(['photos_expire_at' => now()->subDay()]);

    $this->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee('no longer available')
        ->assertDontSee("photos/$id", false);
});

// Expired is the more useful thing to say: a guest holding a PIN they cannot
// use should be told the album is over, not asked for the PIN again.
it('says expired rather than asking for a PIN it would not accept', function () {
    $this->event->update([
        'photos_expire_at' => now()->subDay(),
        'album_privacy' => 'pin',
        'album_pin' => 'bridesmaids',
    ]);

    $this->get('/e/PARTY2/gallery')->assertOk()->assertDontSee('name="pin"', false);
});

// The whole point of a grace period: the host can still get in and pull the
// photos down, and can still give the album more time.
it('still shows the host the album inside the grace period', function () {
    $id = uploadPhoto('PARTY2')->json('id');
    $this->event->update(['photos_expire_at' => now()->subDay()]);

    $this->actingAs($this->owner)->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee("photos/$id", false)
        ->assertSee('deleted');
});

// A photo taken into an album that is already being swept is a photo the guest
// loses within the month — so the booth stops taking them.
it('closes the booth when the window has run out', function () {
    $this->event->update(['photos_expire_at' => now()->subDay()]);

    $this->get('/e/PARTY2')->assertOk()->assertSee('finished')->assertDontSee('Quick shoot');

    uploadPhoto('PARTY2')->assertGone();
    expect(Photo::count())->toBe(0);
});

it('tells a guest how long the photos are kept before they share', function () {
    uploadPhoto('PARTY2');

    $this->get('/e/PARTY2')
        ->assertOk()
        ->assertSee('Photos are kept until '.$this->event->refresh()->photos_expire_at->format('j M Y'));
});

// The guest about to take the first photo is the one who starts the window, so
// there is no date to show them yet — they get the rule instead.
it('tells the first guest what the window will be', function () {
    $this->get('/e/PARTY2')
        ->assertOk()
        ->assertSee('Photos are kept for '.Event::RETENTION_DAYS.' days from the first photo')
        ->assertDontSee('Photos are kept until');
});

it('promises nothing about a window that was never set', function () {
    uploadPhoto('PARTY2');
    $this->event->update(['photos_expire_at' => null]);

    $this->get('/e/PARTY2')->assertOk()
        ->assertDontSee('Photos are kept until')
        ->assertDontSee('days from the first photo');
});

// --- The host's control, and the extension Phill asked for ---

it('lets the host buy the album more time', function () {
    $extended = now()->addDays(200)->startOfDay();

    $this->actingAs($this->owner)
        ->post('/events/PARTY2/retention', ['photos_expire_at' => $extended->toDateString()])
        ->assertRedirect('/events/PARTY2#retention');

    expect($this->event->refresh()->photos_expire_at->toDateString())->toBe($extended->toDateString());
});

// The case Phill named: somebody emails asking nicely after the album expired,
// and the photos are still there because the sweep has not reached them.
it('brings an expired album back when the host extends it in time', function () {
    $id = uploadPhoto('PARTY2')->json('id');
    $this->event->update(['photos_expire_at' => now()->subDay()]);
    $this->get('/e/PARTY2/gallery')->assertSee('no longer available');

    $this->actingAs($this->owner)
        ->post('/events/PARTY2/retention', ['photos_expire_at' => now()->addDays(30)->toDateString()]);

    $this->get('/e/PARTY2/gallery')->assertOk()->assertSee("photos/$id", false);
});

// The upload is what gives this test its teeth. A fresh event's window is null
// now that the clock starts at the first photo, so without a real date to clear
// the assertion would hold however the controller treated an empty field.
it('lets the host keep the photos for good', function () {
    uploadPhoto('PARTY2');
    expect($this->event->refresh()->photos_expire_at)->not->toBeNull();

    $this->actingAs($this->owner)
        ->post('/events/PARTY2/retention', ['photos_expire_at' => ''])
        ->assertRedirect('/events/PARTY2#retention');

    expect($this->event->refresh()->photos_expire_at)->toBeNull()
        ->and($this->event->awaitingFirstPhoto())->toBeFalse();
});

// Backdating would hand the next sweep an album the host never meant to lose.
it('refuses a window that has already gone', function () {
    $this->actingAs($this->owner)
        ->post('/events/PARTY2/retention', ['photos_expire_at' => now()->subDay()->toDateString()])
        ->assertInvalid(['photos_expire_at']);

    expect($this->event->refresh()->hasExpired())->toBeFalse();
});

it('shows the host the date and what happens on it', function () {
    uploadPhoto('PARTY2');

    $this->actingAs($this->owner)->get('/events/PARTY2')
        ->assertOk()
        ->assertSee($this->event->refresh()->photos_expire_at->format('j M Y'))
        ->assertSee('name="photos_expire_at"', false);
});

// A host looking at an album nobody has shot into yet must not read "kept for
// good" — that is what the same empty column means once photos exist.
it('tells the host a quiet album is waiting for its window, not keeping it for good', function () {
    $this->actingAs($this->owner)->get('/events/PARTY2')
        ->assertOk()
        ->assertSee('kept for '.Event::RETENTION_DAYS.' days from the first photo')
        // The fold's body has to agree with its summary. Both spellings, because
        // the summary says "kept for good" and the body's hint said "keep them
        // for good" — an assertion on one alone misses the other by a word.
        ->assertSee('The clock starts at')
        ->assertDontSee('kept for good')
        ->assertDontSee('keep them for good');
});

// The field refuses a date in the past, so on the one album whose host actually
// needs to extend, loading it with the date that already passed hands them a
// form the browser will not submit. Offer a fresh window instead.
it('offers an expired album a date it can actually be given', function () {
    $this->event->update(['photos_expire_at' => now()->subDays(5)]);

    $this->actingAs($this->owner)->get('/events/PARTY2')
        ->assertOk()
        ->assertSee('value="'.now()->addDays(Event::RETENTION_DAYS)->toDateString().'"', false)
        ->assertDontSee('value="'.now()->subDays(5)->toDateString().'"', false);
});

it('does not let a stranger move the window', function () {
    $this->actingAs(User::factory()->create())
        ->post('/events/PARTY2/retention', ['photos_expire_at' => now()->addYear()->toDateString()])
        ->assertForbidden();
});

it('does not let a guest move the window', function () {
    $this->post('/events/PARTY2/retention', ['photos_expire_at' => now()->addYear()->toDateString()])
        ->assertRedirect('/login');
});

// An admin manages every event, which is how a host who emails asking nicely
// gets their extra time.
it('lets an admin extend somebody elses album', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->post('/events/PARTY2/retention', ['photos_expire_at' => now()->addYear()->toDateString()])
        ->assertRedirect('/events/PARTY2#retention');

    expect($this->event->refresh()->photos_expire_at->year)->toBe(now()->addYear()->year);
});

// --- Deleting the photos, and only the photos ---

it('deletes the photos and their files but keeps the event', function () {
    uploadPhoto('PARTY2', ['kind' => 'strip', 'slot' => 0]);
    uploadPhoto('PARTY2');
    $paths = Photo::get()->flatMap->paths();
    expect($paths)->toHaveCount(4); // two photos, each with the derivative the queue wrote

    $this->event->purgePhotos();

    expect(Photo::count())->toBe(0)
        ->and(Event::whereCode('PARTY2')->exists())->toBeTrue();
    $paths->each(fn (string $path) => Storage::assertMissing($path));
});

// The worst state the host-trust pack can get wrong is the one where the host has
// actually lost something. Before this, a swept album still ran the grace-period
// countdown over an empty feed reading "No photos yet — be the first", and both
// the album and the retention panel offered an extension that recovers nothing.
it('tells the host the photos are gone rather than counting down to it', function () {
    uploadPhoto('PARTY2');
    $this->event->update(['photos_expire_at' => now()->subDays(Event::PURGE_GRACE_DAYS + 1)]);
    $this->event->purgePhotos();

    $this->actingAs($this->owner)->get('/e/PARTY2/gallery')
        ->assertOk()
        ->assertSee('photos were deleted')
        ->assertDontSee('give it more time')
        ->assertDontSee('be the first');
});

it('stops offering the host an extension that would recover nothing', function () {
    uploadPhoto('PARTY2');
    $this->event->purgePhotos();

    $this->actingAs($this->owner)->get('/events/PARTY2')
        ->assertOk()
        ->assertSee('photos were deleted')
        ->assertDontSee('name="photos_expire_at"', false);
});

// Whatever date the event now carries, the photos are not coming back — so the
// album stays shut rather than showing guests an empty wall.
it('keeps a swept album shut even after the date is moved', function () {
    uploadPhoto('PARTY2');
    $this->event->purgePhotos();
    $this->event->update(['photos_expire_at' => now()->addYear()]);

    $this->get('/e/PARTY2/gallery')->assertOk()->assertSee('no longer available');
});

it('records when the photos were deleted', function () {
    uploadPhoto('PARTY2');
    expect($this->event->photos_purged_at)->toBeNull();

    $this->event->purgePhotos();

    expect($this->event->refresh()->photos_purged_at)->not->toBeNull()
        ->and($this->event->photosWerePurged())->toBeTrue();
});

// A host who deletes every session by hand has not had their album swept, and
// must not be told it was.
it('does not call a hand-emptied album a swept one', function () {
    uploadPhoto('PARTY2');
    $group = Photo::sole()->group_uuid;

    $this->actingAs($this->owner)->delete("/e/PARTY2/groups/{$group}");

    expect($this->event->refresh()->photosWerePurged())->toBeFalse();
});

// The code has to keep explaining itself. Purging the row would hand a guest
// the unknown-code 404, which says the booth never existed.
it('keeps answering on the event code after the photos are gone', function () {
    uploadPhoto('PARTY2');
    $this->event->update(['photos_expire_at' => now()->subDay()]);

    $this->event->purgePhotos();

    $this->get('/e/PARTY2/gallery')->assertOk()->assertSee('no longer available');
});

// The logo is the host's own branding, not a guest's photo, and retention is a
// promise about guests' photos.
it('leaves the host logo alone', function () {
    Storage::put('logos/party.png', 'bytes');
    $this->event->update(['logo_path' => 'logos/party.png']);

    $this->event->purgePhotos();

    Storage::assertExists('logos/party.png');
    expect($this->event->refresh()->logo_path)->toBe('logos/party.png');
});

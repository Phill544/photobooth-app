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

it('gives a new event a stated window rather than an open-ended one', function () {
    expect($this->event->photos_expire_at)->not->toBeNull()
        ->and($this->event->photos_expire_at->isSameDay(now()->addDays(Event::RETENTION_DAYS)))->toBeTrue();
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
    $this->get('/e/PARTY2')
        ->assertOk()
        ->assertSee('Photos are kept until '.$this->event->photos_expire_at->format('j M Y'));
});

it('promises nothing about a window that was never set', function () {
    $this->event->update(['photos_expire_at' => null]);

    $this->get('/e/PARTY2')->assertOk()->assertDontSee('Photos are kept until');
});

// --- The host's control, and the extension Phill asked for ---

it('lets the host buy the album more time', function () {
    $extended = now()->addDays(200)->startOfDay();

    $this->actingAs($this->owner)
        ->post('/events/PARTY2/retention', ['photos_expire_at' => $extended->toDateString()])
        ->assertRedirect('/events/PARTY2');

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

it('lets the host keep the photos for good', function () {
    $this->actingAs($this->owner)
        ->post('/events/PARTY2/retention', ['photos_expire_at' => ''])
        ->assertRedirect('/events/PARTY2');

    expect($this->event->refresh()->photos_expire_at)->toBeNull();
});

// Backdating would hand the next sweep an album the host never meant to lose.
it('refuses a window that has already gone', function () {
    $this->actingAs($this->owner)
        ->post('/events/PARTY2/retention', ['photos_expire_at' => now()->subDay()->toDateString()])
        ->assertInvalid(['photos_expire_at']);

    expect($this->event->refresh()->hasExpired())->toBeFalse();
});

it('shows the host the date and what happens on it', function () {
    $this->actingAs($this->owner)->get('/events/PARTY2')
        ->assertOk()
        ->assertSee($this->event->photos_expire_at->format('j M Y'))
        ->assertSee('name="photos_expire_at"', false);
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
        ->assertRedirect('/events/PARTY2');

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

<?php

use App\Jobs\BuildEventArchive;
use App\Models\Archive;
use App\Models\Event;
use App\Models\Photo;
use App\Models\User;
use App\Notifications\ArchiveReady;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake();
    $this->owner = User::factory()->create();
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'owner_id' => $this->owner->id]);
});

function shootSession(string $code = 'PARTY2'): string
{
    $group = fake()->uuid();
    uploadPhoto($code, ['kind' => 'strip', 'slot' => 0, 'group' => $group]);
    uploadPhoto($code, ['slot' => 1, 'group' => $group]);
    uploadPhoto($code, ['slot' => 2, 'group' => $group]);

    return $group;
}

// --- Asking for one ---

it('offers the host a way to take the whole night home', function () {
    shootSession();

    $this->actingAs($this->owner)->get('/events/PARTY2')
        ->assertOk()
        ->assertSee('Download everything');
});

it('queues the build rather than making the host wait for it', function () {
    Queue::fake();
    shootSession();

    $this->actingAs($this->owner)->post('/events/PARTY2/archive')
        ->assertRedirect('/events/PARTY2');

    Queue::assertPushed(BuildEventArchive::class);
    expect(Archive::sole())
        ->event_id->toBe($this->event->id)
        ->requested_by->toBe($this->owner->id)
        ->status->toBe('pending');
});

// A host who taps twice should not set two multi-gigabyte jobs running.
it('does not start a second build while one is still going', function () {
    Queue::fake();
    shootSession();

    $this->actingAs($this->owner)->post('/events/PARTY2/archive');
    $this->actingAs($this->owner)->post('/events/PARTY2/archive');

    expect(Archive::count())->toBe(1);
    Queue::assertPushed(BuildEventArchive::class, 1);
});

it('will not build an archive of nothing', function () {
    Queue::fake();

    $this->actingAs($this->owner)->post('/events/PARTY2/archive')->assertInvalid(['archive']);

    expect(Archive::count())->toBe(0);
});

it('does not let a stranger ask', function () {
    shootSession();

    $this->actingAs(User::factory()->create())->post('/events/PARTY2/archive')->assertForbidden();

    expect(Archive::count())->toBe(0);
});

// Its own test, not a second assertion above: actingAs sticks for the rest of a
// test, so a guest check after one would still be the stranger.
it('does not let a guest ask', function () {
    shootSession();

    $this->post('/events/PARTY2/archive')->assertRedirect('/login');

    expect(Archive::count())->toBe(0);
});

// --- Building one ---

it('builds a zip with the strips and the originals in their own folders', function () {
    Notification::fake();
    shootSession();

    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();

    $archive = Archive::sole();
    expect($archive->status)->toBe('ready')
        ->and($archive->bytes)->toBeGreaterThan(0);
    Storage::assertExists($archive->path);

    $names = zipEntries(Storage::path($archive->path));
    expect($names)->toHaveCount(3)
        ->and(collect($names)->filter(fn ($n) => str_starts_with($n, 'strips/')))->toHaveCount(1)
        ->and(collect($names)->filter(fn ($n) => str_starts_with($n, 'photos/')))->toHaveCount(2);
});

// Deflating a JPEG is CPU spent to save nothing, and a busy night is thousands
// of them. Storing is what makes a big event's archive finish at all.
it('stores the photos rather than trying to compress them again', function () {
    Notification::fake();
    shootSession();

    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();

    $zip = new ZipArchive;
    $zip->open(Storage::path(Archive::sole()->path));
    $methods = array_map(fn (int $i) => $zip->statIndex($i)['comp_method'], range(0, $zip->numFiles - 1));
    $zip->close();

    expect(array_unique($methods))->toBe([ZipArchive::CM_STORE]);
});

// Same prefix as the photos, which is what makes both the event delete and the
// retention sweep take the archive with them without knowing it exists.
it('writes the archive under the event prefix', function () {
    Notification::fake();
    shootSession();

    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();

    expect(Archive::sole()->path)->toStartWith("events/{$this->event->id}/");
});

it('emails the host who asked, with a link and a size', function () {
    Notification::fake();
    shootSession();

    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();

    Notification::assertSentTo($this->owner, ArchiveReady::class, function (ArchiveReady $mail) {
        $rendered = (string) $mail->toMail($this->owner)->render();

        return str_contains($rendered, '/archives/')
            && str_contains($rendered, 'Summer Party')
            && str_contains($rendered, 'signature=');
    });
});

it('records a build that failed so the host is not left watching a spinner', function () {
    $archive = Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id]);

    (new BuildEventArchive($archive))->failed(new RuntimeException('the disk said no'));

    expect($archive->refresh()->status)->toBe('failed');

    // Static Blade text, so the apostrophe is raw in the HTML.
    $this->actingAs($this->owner)->get('/events/PARTY2')->assertSee("didn't finish", false);
});

// A row can outlive its file — this app has a commit and a 404 route for
// exactly that state — and one orphan must not cost the host the whole night.
it('skips a photo whose file has gone rather than losing the whole download', function () {
    Notification::fake();
    shootSession();
    Storage::delete(Photo::where('kind', 'original')->first()->path);

    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();

    $archive = Archive::sole();
    expect($archive->status)->toBe('ready')
        // ...and the counts are what actually went in, not what the rows claim.
        ->and($archive->photo_count)->toBe(1)
        ->and($archive->strip_count)->toBe(1);

    expect(zipEntries(Storage::path($archive->path)))->toHaveCount(2);
});

// A build stages every original to a temp file. A throw between the first one
// and the write used to leave the lot behind — on a four-thousand-photo event
// that is the whole night, twice, since it retries.
it('leaves no temp files behind, whichever way the build ends', function () {
    Notification::fake();
    shootSession();

    $before = count(glob(sys_get_temp_dir().'/pb-*'));

    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();

    expect(count(glob(sys_get_temp_dir().'/pb-*')))->toBe($before);

    // ...and the same on the path where a photo's file is missing, which is the
    // one that used to throw straight past the cleanup.
    shootSession();
    Storage::delete(Photo::where('kind', 'original')->latest()->first()->path);
    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();

    expect(count(glob(sys_get_temp_dir().'/pb-*')))->toBe($before);
});

// --- Taking it home ---

it('hands over the zip to a signed link', function () {
    Notification::fake();
    shootSession();
    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();

    $archive = Archive::sole();

    $this->get(URL::temporarySignedRoute('archive.download', now()->addDay(), ['archive' => $archive->id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/zip');
});

// The signature is the credential, so an unsigned or edited URL is nothing.
it('refuses an unsigned link', function () {
    Notification::fake();
    shootSession();
    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();

    $this->get('/archives/'.Archive::sole()->id.'/download')->assertForbidden();
});

it('refuses a link whose time is up', function () {
    Notification::fake();
    shootSession();
    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();

    $url = URL::temporarySignedRoute('archive.download', now()->subMinute(), ['archive' => Archive::sole()->id]);

    $this->get($url)->assertForbidden();
});

// The row can outlive the file, exactly as a photo row can — and the answer is
// the same one the image routes give: 404, not a 500.
it('answers 404 for an archive whose file has gone', function () {
    Notification::fake();
    shootSession();
    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();

    $archive = Archive::sole();
    Storage::delete($archive->path);

    $this->get(URL::temporarySignedRoute('archive.download', now()->addDay(), ['archive' => $archive->id]))
        ->assertNotFound();
});

// --- Not outliving what it holds ---

// A retention window that deletes the photos and leaves a zip of them on the
// same disk is not a retention window.
it('goes when the retention sweep takes the photos it holds', function () {
    Notification::fake();
    shootSession();
    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();
    $path = Archive::sole()->path;
    Storage::assertExists($path);

    $this->event->purgePhotos();

    Storage::assertMissing($path);
    expect(Archive::count())->toBe(0);
});

it('goes when the host deletes the event', function () {
    Notification::fake();
    shootSession();
    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();
    $path = Archive::sole()->path;

    $this->actingAs($this->owner)->delete('/events/PARTY2', ['confirm_code' => 'PARTY2']);

    Storage::assertMissing($path);
    expect(Archive::count())->toBe(0);
});

// --- Cleaning up after itself ---

it('sweeps archives whose link has expired', function () {
    Notification::fake();
    shootSession();
    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();

    $archive = Archive::sole();
    $path = $archive->path;
    $archive->update(['expires_at' => now()->subDay()]);

    $this->artisan('photobooth:sweep-archives')->assertSuccessful();

    Storage::assertMissing($path);
    expect(Archive::count())->toBe(0);
});

it('leaves an archive that is still good', function () {
    Notification::fake();
    shootSession();
    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();

    $this->artisan('photobooth:sweep-archives')->assertSuccessful();

    expect(Archive::count())->toBe(1);
    Storage::assertExists(Archive::sole()->path);
});

it('is scheduled, and on one server only', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'photobooth:sweep-archives'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->onOneServer)->toBeTrue();
});

// --- What the host sees while it happens ---

it('tells the host it is building, then hands them the link', function () {
    Notification::fake();
    shootSession();
    $archive = Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id]);

    $this->actingAs($this->owner)->get('/events/PARTY2')->assertSee('Building');

    (new BuildEventArchive($archive))->handle();

    $this->actingAs($this->owner)->get('/events/PARTY2')
        ->assertSee('/archives/', false)
        ->assertSee('signature=', false)
        ->assertDontSee('Building');
});

// The email says "ask again any time for a fresh one", and an archive is a
// snapshot: a host who took one at 9pm has a zip of the 9pm album, and the
// night went on until 1am.
it('still lets the host build a fresh one while an archive is ready', function () {
    Notification::fake();
    shootSession();
    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();

    $this->actingAs($this->owner)->get('/events/PARTY2')
        ->assertSee('/archives/', false)                                  // the one they have
        ->assertSee('action="/events/PARTY2/archive"', false)             // ...and a way to a newer one
        ->assertSee('fresh');
});

it('does not offer a link that has already expired', function () {
    Notification::fake();
    shootSession();
    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();

    Archive::sole()->update(['expires_at' => now()->subDay()]);

    $this->actingAs($this->owner)->get('/events/PARTY2')
        ->assertSee('Download everything')
        ->assertDontSee('/archives/', false);
});

it('counts the strips and the photos so the email is not a surprise', function () {
    Notification::fake();
    shootSession();
    shootSession();

    (new BuildEventArchive(Archive::create(['event_id' => $this->event->id, 'requested_by' => $this->owner->id])))->handle();

    expect(Archive::sole())->photo_count->toBe(4)->strip_count->toBe(2)
        ->and(Photo::count())->toBe(6);
});

<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Support\Deliverability;
use App\Support\Durability;
use App\Support\ImageResponse;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    // Sessions, not photos: one page is 24 cards — 24 strips on the wall, and
    // their ~72 originals in the second panel, which starts hidden. Every tile
    // is lazy, so a page costs the strips a guest can actually see.
    public const SESSIONS_PER_PAGE = 24;

    // Wrong PIN guesses a minute, per album. Far more than a room ever needs and
    // a rate no free-text PIN falls to. Keyed on the album rather than the
    // caller because a venue is one NAT address, so an IP key would throttle the
    // room instead of the attacker; the trade — one attacker can hold an album's
    // guests out a minute at a time — is the same one the upload limiter makes.
    public const PIN_GUESSES_PER_MINUTE = 20;

    public function dashboard(Request $request)
    {
        $user = $request->user();
        // Admins oversee every event; owners see only their own, and admin rows
        // name the owner — so eager-load it rather than a query per row.
        $events = ($user->is_admin ? Event::query()->with('owner') : $user->events())
            ->withCount(['photos' => fn ($query) => $query->where('kind', 'original')])
            ->latest()->get();

        return view('dashboard', [
            'events' => $events,
            'isAdmin' => $user->is_admin,
            // Only worth nagging about while it actually gates something, which
            // it does not when the app has no mailer to send the link with.
            'emailIsVerified' => $user->hasVerifiedEmail() || Deliverability::mailerIsFake(),
            // Live means taking photos, which a finished event is not, however
            // its closed_at reads.
            'liveCount' => $events->filter(fn (Event $event) => $event->status() === 'live')->count(),
        ]);
    }

    public function create()
    {
        return view('create-event', ['templates' => Event::TEMPLATES, 'themes' => Event::STRIP_THEMES]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'template' => ['sometimes', Rule::in(array_keys(Event::TEMPLATES))],
            'theme' => ['sometimes', Rule::in(array_keys(Event::STRIP_THEMES))],
            'caption' => ['nullable', 'string', 'max:60'],
            ...self::artworkRules(),
        ]);

        $event = Event::create([
            ...$validated,
            'owner_id' => $request->user()->id,
            // An unticked checkbox sends nothing at all, so this is read off the
            // request rather than the validated set, where absence would simply
            // leave the column alone.
            'caption_hidden' => $request->boolean('caption_hidden'),
        ]);
        $this->applyImage($request, $event, 'logo');
        $this->applyImage($request, $event, 'background');

        return redirect("/events/{$event->code}");
    }

    public function logo(Request $request, Event $event)
    {
        abort_if(! $event->logo_path, 404);

        return ImageResponse::immutable($request, $event->logo_path);
    }

    public function background(Request $request, Event $event)
    {
        abort_if(! $event->background_path, 404);

        return ImageResponse::immutable($request, $event->background_path);
    }

    public function show(Event $event)
    {
        abort_unless($event->managedBy(auth()->user()), 403);

        // "Photos" always means the shots a guest took; the composed strip is a
        // separate artifact with its own count, so it never joins that total.
        // Counted in the database, not in PHP: this page shows three numbers,
        // and hydrating a busy night's four thousand rows to reach them is the
        // same load the album was just cured of.
        $strips = fn () => $event->photos()->where('kind', 'strip');

        return view('owner', [
            'event' => $event,
            'qrSvg' => $this->qrSvg(url("/e/{$event->code}")),
            'photoCount' => $event->photos()->where('kind', 'original')->count(),
            'stripCount' => $strips()->count(),
            'lastStripAt' => $strips()->latest()->first()?->created_at,
            'templates' => Event::TEMPLATES,
            'themes' => Event::STRIP_THEMES,
            // Passed in rather than reached for in the view: `Event` in a Blade
            // template is the framework's facade alias, not this model.
            'privacyOptions' => Event::ALBUM_PRIVACY,
            'graceDays' => Event::PURGE_GRACE_DAYS,
            'retentionDays' => Event::RETENTION_DAYS,
            'pinMaxLength' => Event::PIN_MAX_LENGTH,
            'pinMinLength' => Event::PIN_MIN_LENGTH,
            // Only the most recent one is worth showing: asking again replaces
            // the link a host would have used anyway.
            'archive' => $event->archives()->latest()->first(),
        ]);
    }

    public function update(Request $request, Event $event)
    {
        abort_unless($event->managedBy($request->user()), 403);

        $validated = $this->validateInFold($request, $event, 'edit', [
            'name' => ['required', 'string', 'max:100'],
            'template' => ['sometimes', Rule::in(array_keys(Event::TEMPLATES))],
            'theme' => ['sometimes', Rule::in(array_keys(Event::STRIP_THEMES))],
            'caption' => ['nullable', 'string', 'max:60'],
            ...self::artworkRules(),
        ]);

        $event->update([...$validated, 'caption_hidden' => $request->boolean('caption_hidden')]);
        $this->applyImage($request, $event, 'logo');
        $this->applyImage($request, $event, 'background');

        return $this->backToFold($event, 'edit', 'Saved. New strips use this look.');
    }

    // Every control on this page sits in a fold, most of a screen below the
    // poster, so a bare redirect answers a host's tap with the top of the page
    // and no sign anything happened. The fragment carries them back to the
    // control they used; the two flashed keys are what the page needs to open
    // that fold and put one line inside it, because a fragment is client-side
    // only and the view never sees it.
    private function backToFold(Event $event, string $fold, string $status)
    {
        return redirect("/events/{$event->code}#{$fold}")
            ->with(['fold' => $fold, 'status' => $status]);
    }

    // A rejected POST is a redirect too, and it lands in the same wrong place —
    // worse, because the fold opens itself on an error and the message is then
    // a screen and a half below the host who has to read it.
    private function validateInFold(Request $request, Event $event, string $fold, array $rules): array
    {
        try {
            return $request->validate($rules);
        } catch (ValidationException $invalid) {
            throw $invalid->redirectTo("/events/{$event->code}#{$fold}");
        }
    }

    public function destroy(Request $request, Event $event)
    {
        abort_unless($event->managedBy($request->user()), 403);

        // Typing the code is the confirmation, and it is checked here rather
        // than in a browser confirm(): this is the one action that destroys
        // every guest's photos, and a dialog guards nothing a request can skip.
        // Case-insensitive like every other place a human types a code.
        if (strtoupper((string) $request->input('confirm_code')) !== $event->code) {
            // Straight back to the panel: it is the last thing on a long page, so
            // a plain redirect lands the host at the poster with the error a
            // screen and a half below them, and nothing to say it went wrong.
            throw ValidationException::withMessages([
                'confirm_code' => "Type {$event->code} to delete this event.",
            ])->redirectTo("/events/{$event->code}#delete");
        }

        $event->purge();

        return redirect('/dashboard');
    }

    // What a host may upload, for both the create form and the edit fold.
    //
    // The logo sits in the footer and 2MB is plenty. A background is the whole
    // strip, so that cap would refuse the very files the layout guide invites —
    // but the byte size is not the risk. A guest's phone decodes this to raw
    // RGBA at four bytes a pixel however well it compressed, so a modest
    // 4000x9000 PNG is 137 MiB on an old iPhone that is already holding four
    // shots and a composed strip. The dimension ceiling is therefore the real
    // guard, and it is cheap: Laravel reads the size out of the header rather
    // than decoding the file. 2048x3200 clears every template at 1x.
    //
    // No SVG in `mimes`, and it must stay that way: the `dimensions` rule waves
    // SVG straight through, so relaxing this would silently disarm the ceiling
    // above — and an SVG served inline on our own origin is stored XSS sitting
    // next to the album's session cookie.
    private static function artworkRules(): array
    {
        return [
            'logo' => ['nullable', 'image', 'mimes:png,jpeg,webp', 'max:2048'],
            'background' => ['nullable', 'image', 'mimes:png,jpeg,webp', 'max:8192', 'dimensions:max_width=2048,max_height=3200'],
        ];
    }

    // The two pieces of artwork a host owns: `logo` in the strip's footer, and
    // `background` behind the whole strip. Same pipeline, one field name — the
    // column, the directory and the removal checkbox are all derived from it, so
    // the second one cannot drift from the first.
    //
    // Stores a newly uploaded file (replacing any old one), or removes it.
    private function applyImage(Request $request, Event $event, string $field): void
    {
        $column = "{$field}_path";

        if (! $request->hasFile($field) && ! $request->boolean("remove_{$field}")) {
            return;
        }

        // The same per-request check the booth's uploads get, for the same
        // reason — except a host's artwork is worse off than a guest's photo:
        // nothing can be re-shot, the original is on the host's own machine, and
        // artwork that vanished on the next deploy gives them no reason to look.
        // Only a write is refused; a removal stores nothing, and refusing that
        // would strand a host with branding they cannot take back off.
        abort_if(
            $request->hasFile($field) && Durability::diskIsEphemeral(),
            503, 'Branding storage is not configured durably.'
        );

        // Deliberately not under events/{id}: Event::purgePhotos() clears that
        // prefix wholesale when a retention window closes, and a host's own
        // artwork is not a guest's photograph.
        //
        // Write the replacement before dropping the old one, and check that it
        // landed: the disk returns false rather than throwing when it refuses a
        // write, and deleting first would leave the host with neither.
        $path = $request->file($field)?->store("{$field}s");
        abort_if($path === false, 503, "The {$field} could not be stored.");

        $replaced = $event->{$column};
        $event->update([$column => $path]);

        if ($replaced) {
            Storage::delete($replaced);
        }
    }

    private function qrSvg(string $url): string
    {
        $renderer = new ImageRenderer(new RendererStyle(280), new SvgImageBackEnd);
        $svg = (new Writer($renderer))->writeString($url);

        return Str::after($svg, '?>'); // drop the XML declaration for inline HTML use
    }

    public function toggleClosed(Event $event)
    {
        abort_unless($event->managedBy(auth()->user()), 403);

        $event->update(['closed_at' => $event->isClosed() ? null : now()]);

        // What just happened, not what is now true: the line beside this one
        // already states the booth's state, and two full sentences saying the
        // same thing in one row is a page arguing with itself.
        return $this->backToFold($event, 'booth', $event->isClosed()
            ? 'Closed just now.'
            : 'Reopened just now.');
    }

    public function privacy(Request $request, Event $event)
    {
        abort_unless($event->managedBy($request->user()), 403);

        $validated = $this->validateInFold($request, $event, 'privacy', [
            'album_privacy' => ['required', Rule::in(array_keys(Event::ALBUM_PRIVACY))],
            'album_pin' => ['nullable', 'string', 'min:'.Event::PIN_MIN_LENGTH, 'max:'.Event::PIN_MAX_LENGTH],
        ]);

        // The one setting that is useless without a PIN is the one that insists
        // on it. An empty field is left alone everywhere else, so the word the
        // host has been reading out all night survives a trip through open and
        // back rather than having to be re-invented.
        if ($validated['album_privacy'] === 'pin' && blank($validated['album_pin'] ?? null)) {
            throw ValidationException::withMessages([
                'album_pin' => 'Give guests a PIN of '.Event::PIN_MIN_LENGTH.' to '.Event::PIN_MAX_LENGTH.' characters to type.',
            ])->redirectTo("/events/{$event->code}#privacy");
        }

        $event->album_privacy = $validated['album_privacy'];
        if (filled($validated['album_pin'] ?? null)) {
            $event->album_pin = $validated['album_pin'];
        }
        $event->save();

        // The setting itself, not "Saved": the three options differ by who gets
        // in, and reading the one that took is the whole point of the trip back.
        return $this->backToFold($event, 'privacy', 'Album: '.Event::ALBUM_PRIVACY[$event->album_privacy].'.');
    }

    public function retention(Request $request, Event $event)
    {
        abort_unless($event->managedBy($request->user()), 403);

        $validated = $this->validateInFold($request, $event, 'retention', [
            // Backdating would hand the next sweep an album the host never
            // meant to lose. An empty field is "keep these for good".
            'photos_expire_at' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        // Through the stated day, not up to the start of it: a host who says
        // "kept until the 15th" is promising their guests the 15th.
        $event->update([
            'photos_expire_at' => $validated['photos_expire_at']
                ? Carbon::parse($validated['photos_expire_at'])->endOfDay()
                : null,
        ]);

        return $this->backToFold($event, 'retention', $this->retentionStatus($event));
    }

    // Three states, not two — the same three the fold's summary and hint render,
    // because an empty date means two different things. A booth nobody has shot
    // into is not being kept for good: its clock has not started. Saying so
    // would contradict the hint an inch above it, and a host who believed it
    // would lose the album ninety days after the first guest arrived.
    private function retentionStatus(Event $event): string
    {
        if ($event->photos_expire_at) {
            return 'Photos are kept until '.$event->photos_expire_at->format('j M Y').'.';
        }

        if ($event->awaitingFirstPhoto()) {
            return 'Photos are kept for '.Event::RETENTION_DAYS.' days from the first photo.';
        }

        return 'Photos are kept for good.';
    }

    // Codes are read off a sign and typed by hand, so they arrive in whatever
    // case and spacing the guest managed. Nothing is looked up here: an unknown
    // code goes to the booth URL and meets the 404 that names it.
    public function join(Request $request)
    {
        // query() returns whatever was in the URL, and ?code[]=x is an array —
        // this is a public GET linked from the home page, so it has to shrug.
        $code = $request->query('code');
        $code = is_string($code) ? strtoupper(trim($code)) : '';

        return redirect($code === '' ? '/' : "/e/{$code}");
    }

    public function capture(Request $request, Event $event)
    {
        return view('capture', [
            'event' => $event,
            // A host standing in the booth needs the way back to their own
            // controls; a guest must never be shown a door that answers 403.
            'isHost' => $event->managedBy($request->user()),
            'photoCount' => $event->photos()->where('kind', 'original')->count(),
            'stripCount' => $event->photos()->where('kind', 'strip')->count(),
            // The consent line names it before any photo has started the window.
            'retentionDays' => Event::RETENTION_DAYS,
        ]);
    }

    // Why a guest is being kept out of the album, or null if they aren't. The
    // host and an admin are never turned away — the grace period exists so they
    // can still get in, pull the photos down, and give the album more time. The
    // booth is gated by none of this: a guest can always shoot, and always save
    // their own strip.
    private function albumGate(Request $request, Event $event): ?string
    {
        if ($event->managedBy($request->user())) {
            return null;
        }

        // Expiry outranks the PIN. A guest holding a PIN that would no longer
        // open anything should be told the album is over, not asked to type it.
        if ($event->hasExpired()) {
            return 'expired';
        }

        if ($event->albumIsHidden()) {
            return 'hidden';
        }

        if ($event->albumNeedsPin() && $request->session()->get($this->unlockKey($event)) !== $event->pinFingerprint()) {
            return 'pin';
        }

        return null;
    }

    // Per event, because a guest can be at two of them in one session. What is
    // stored under it is the PIN's fingerprint rather than a flag, so a changed
    // PIN invalidates the unlocks the old one bought without anything having to
    // go and find them.
    private function unlockKey(Event $event): string
    {
        return "album-unlocked.{$event->id}";
    }

    // Both sides of the gate carry the page of the album the guest was on, so
    // unlocking lands them back where they were reading rather than at the top
    // of the night. Rebuilt from the two keys the album knows rather than
    // echoed, so the only place this can ever redirect to is this album.
    private function albumQuery(Request $request): string
    {
        $query = array_filter([
            'order' => $request->query('order') === 'oldest' ? 'oldest' : null,
            'after' => $request->integer('after') ?: null,
        ]);

        return $query ? '?'.http_build_query($query) : '';
    }

    public function unlock(Request $request, Event $event)
    {
        $album = "/e/{$event->code}/gallery".$this->albumQuery($request);

        // Counted here rather than by a throttle on the route, because the route
        // middleware charges every caller and only wrong guesses are what this
        // is rationing. Changing the PIN sends a whole room back through this
        // door inside one minute — the host is standing there reading the new
        // word out, which is why they changed it — and a budget those spent
        // would lock out the guests the change was meant to keep. Clearing it on
        // success instead would have handed an attacker a fresh twenty every
        // time somebody legitimately walked in.
        $guesses = 'album-pin:'.$event->code;

        if (RateLimiter::tooManyAttempts($guesses, self::PIN_GUESSES_PER_MINUTE)) {
            abort(429);
        }

        if (! $event->pinMatches($request->input('pin'))) {
            RateLimiter::hit($guesses);

            throw ValidationException::withMessages([
                'pin' => 'That PIN does not open this album.',
            ])->redirectTo($album);
        }

        $request->session()->put($this->unlockKey($event), $event->pinFingerprint());

        return redirect($album);
    }

    public function gallery(Request $request, Event $event)
    {
        if ($state = $this->albumGate($request, $event)) {
            // Hidden is a refusal and says so. A PIN is a door, and a door is a
            // 200 with a form in it; so is an expired album, which a host can
            // still bring back inside the grace period.
            return response()->view('album-gate', [
                'event' => $event,
                'state' => $state,
                'unlockUrl' => "/e/{$event->code}/gallery/unlock".$this->albumQuery($request),
                'pinMaxLength' => Event::PIN_MAX_LENGTH,
            ], $state === 'hidden' ? 403 : 200);
        }

        $oldestFirst = $request->query('order') === 'oldest';

        // A page is a page of *sessions*. A strip and the shots it was composed
        // from are one card, so half a session is not a thing the album can
        // render — and the cursor rides on MAX(id), a session's place in the
        // night, rather than a row offset: a guest sharing while another guest
        // scrolls would otherwise push a card onto their second page as well,
        // and they'd see it twice. MAX() and HAVING are the portable spelling
        // of that in both SQLite and Postgres.
        $after = $request->integer('after');
        $sessions = $event->photos()->toBase()
            ->select('group_uuid')
            ->selectRaw('MAX(id) as last_id')
            ->groupBy('group_uuid')
            ->when($after, fn ($query) => $query->havingRaw('MAX(id) '.($oldestFirst ? '>' : '<').' ?', [$after]))
            ->orderBy('last_id', $oldestFirst ? 'asc' : 'desc')
            ->limit(self::SESSIONS_PER_PAGE + 1) // one over the page: is there another?
            ->get();

        $hasMore = $sessions->count() > self::SESSIONS_PER_PAGE;
        $page = $sessions->take(self::SESSIONS_PER_PAGE);

        $photos = $event->photos()
            ->whereIn('group_uuid', $page->pluck('group_uuid'))
            ->orderBy('slot')
            ->get()
            ->groupBy('group_uuid');

        return view('gallery', [
            'event' => $event,
            'isHost' => $event->managedBy($request->user()),
            'sessions' => $page->map(fn ($session) => $photos[$session->group_uuid]),
            'nextPage' => $hasMore ? $this->galleryUrl($event, $oldestFirst, $page->last()->last_id) : null,
            'flipUrl' => $this->galleryUrl($event, ! $oldestFirst),
            'oldestFirst' => $oldestFirst,
            // The header speaks for the whole album, so it asks the database to
            // count rather than counting a page it can see.
            'stripCount' => $event->photos()->where('kind', 'strip')->count(),
            'photoCount' => $event->photos()->where('kind', 'original')->count(),
            'graceDays' => Event::PURGE_GRACE_DAYS,
        ]);
    }

    private function galleryUrl(Event $event, bool $oldestFirst, ?int $after = null): string
    {
        $query = array_filter(['order' => $oldestFirst ? 'oldest' : null, 'after' => $after]);

        return "/e/{$event->code}/gallery".($query ? '?'.http_build_query($query) : '');
    }
}

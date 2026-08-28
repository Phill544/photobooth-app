<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Support\ImageResponse;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
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
            'liveCount' => $events->reject->isClosed()->count(),
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
            'logo' => ['nullable', 'image', 'mimes:png,jpeg,webp', 'max:2048'],
        ]);

        $event = Event::create([...$validated, 'owner_id' => $request->user()->id]);
        $this->applyLogo($request, $event);

        return redirect("/events/{$event->code}");
    }

    public function logo(Request $request, Event $event)
    {
        abort_if(! $event->logo_path, 404);

        return ImageResponse::immutable($request, $event->logo_path);
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
        ]);
    }

    public function update(Request $request, Event $event)
    {
        abort_unless($event->managedBy($request->user()), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'template' => ['sometimes', Rule::in(array_keys(Event::TEMPLATES))],
            'theme' => ['sometimes', Rule::in(array_keys(Event::STRIP_THEMES))],
            'caption' => ['nullable', 'string', 'max:60'],
            'logo' => ['nullable', 'image', 'mimes:png,jpeg,webp', 'max:2048'],
        ]);

        $event->update($validated);
        $this->applyLogo($request, $event);

        return redirect("/events/{$event->code}");
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

    // Stores a newly uploaded logo (replacing any old one), or removes it.
    private function applyLogo(Request $request, Event $event): void
    {
        if (! $request->hasFile('logo') && ! $request->boolean('remove_logo')) {
            return;
        }

        // Write the replacement before dropping the old one, and check that it
        // landed: the disk returns false rather than throwing when it refuses a
        // write, and deleting first would leave the host with neither logo.
        $path = $request->file('logo')?->store('logos');
        abort_if($path === false, 503, 'The logo could not be stored.');

        $replaced = $event->logo_path;
        $event->update(['logo_path' => $path]);

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

        return redirect("/events/{$event->code}");
    }

    public function capture(Event $event)
    {
        return view('capture', [
            'event' => $event,
            'photoCount' => $event->photos()->where('kind', 'original')->count(),
            'stripCount' => $event->photos()->where('kind', 'strip')->count(),
        ]);
    }

    public function gallery(Request $request, Event $event)
    {
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
            'sessions' => $page->map(fn ($session) => $photos[$session->group_uuid]),
            'nextPage' => $hasMore ? $this->galleryUrl($event, $oldestFirst, $page->last()->last_id) : null,
            'flipUrl' => $this->galleryUrl($event, ! $oldestFirst),
            'oldestFirst' => $oldestFirst,
            // The header speaks for the whole album, so it asks the database to
            // count rather than counting a page it can see.
            'stripCount' => $event->photos()->where('kind', 'strip')->count(),
            'photoCount' => $event->photos()->where('kind', 'original')->count(),
        ]);
    }

    private function galleryUrl(Event $event, bool $oldestFirst, ?int $after = null): string
    {
        $query = array_filter(['order' => $oldestFirst ? 'oldest' : null, 'after' => $after]);

        return "/e/{$event->code}/gallery".($query ? '?'.http_build_query($query) : '');
    }
}

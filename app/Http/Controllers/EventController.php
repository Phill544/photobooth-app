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

class EventController extends Controller
{
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
        $photos = $event->photos()->get();
        $strips = $photos->where('kind', 'strip');

        return view('owner', [
            'event' => $event,
            'qrSvg' => $this->qrSvg(url("/e/{$event->code}")),
            'photoCount' => $photos->where('kind', 'original')->count(),
            'stripCount' => $strips->count(),
            'lastStripAt' => $strips->max('created_at'),
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

    // Stores a newly uploaded logo (replacing any old one), or removes it.
    private function applyLogo(Request $request, Event $event): void
    {
        if (! $request->hasFile('logo') && ! $request->boolean('remove_logo')) {
            return;
        }

        if ($event->logo_path) {
            Storage::delete($event->logo_path);
        }

        $event->update(['logo_path' => $request->file('logo')?->store('logos')]);
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

    public function gallery(Event $event)
    {
        $photos = $event->photos()->orderBy('slot')->get();
        $sessions = $photos
            ->groupBy('group_uuid')
            ->sortByDesc(fn ($photos) => $photos->max('id'))
            ->values();

        return view('gallery', [
            'event' => $event,
            'sessions' => $sessions,
            'stripCount' => $photos->where('kind', 'strip')->count(),
            'photoCount' => $photos->where('kind', 'original')->count(),
        ]);
    }
}

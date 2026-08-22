<?php

namespace App\Http\Controllers;

use App\Models\Event;
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

        $event = Event::create($validated);
        $this->applyLogo($request, $event);

        return redirect("/events/{$event->code}");
    }

    public function logo(Event $event)
    {
        abort_if(! $event->logo_path, 404);

        return Storage::response($event->logo_path);
    }

    public function show(Event $event)
    {
        return view('owner', [
            'event' => $event,
            'qrSvg' => $this->qrSvg(url("/e/{$event->code}")),
            'photoCount' => $event->photos()->count(),
            'templates' => Event::TEMPLATES,
            'themes' => Event::STRIP_THEMES,
        ]);
    }

    public function update(Request $request, Event $event)
    {
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
        $event->update(['closed_at' => $event->isClosed() ? null : now()]);

        return redirect("/events/{$event->code}");
    }

    public function capture(Event $event)
    {
        return view('capture', ['event' => $event]);
    }

    public function gallery(Event $event)
    {
        $sessions = $event->photos()
            ->orderBy('slot')
            ->get()
            ->groupBy('group_uuid')
            ->sortByDesc(fn ($photos) => $photos->max('id'))
            ->values();

        return view('gallery', ['event' => $event, 'sessions' => $sessions]);
    }
}

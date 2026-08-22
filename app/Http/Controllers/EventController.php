<?php

namespace App\Http\Controllers;

use App\Models\Event;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
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
        ]);

        $event = Event::create($validated);

        return redirect("/events/{$event->code}");
    }

    public function show(Event $event)
    {
        return view('owner', [
            'event' => $event,
            'qrSvg' => $this->qrSvg(url("/e/{$event->code}")),
            'photoCount' => $event->photos()->count(),
        ]);
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

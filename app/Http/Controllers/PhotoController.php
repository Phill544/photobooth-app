<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Photo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PhotoController extends Controller
{
    public function show(Event $event, Photo $photo)
    {
        // A meta tag cannot ride on a JPEG. robots.txt already tells a compliant
        // crawler not to fetch this path; the header is for one that asked anyway.
        return Storage::response($photo->path, null, ['X-Robots-Tag' => 'noindex']);
    }

    public function destroyGroup(Event $event, string $group)
    {
        abort_unless($event->managedBy(auth()->user()), 403);

        $photos = $event->photos()->where('group_uuid', $group)->get();
        abort_if($photos->isEmpty(), 404);

        Storage::delete($photos->pluck('path')->all());
        $event->photos()->where('group_uuid', $group)->delete();

        return redirect("/e/{$event->code}/gallery");
    }

    public function store(Request $request, Event $event)
    {
        abort_if($event->isClosed(), 410, 'This event is closed.');

        $validated = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:10240'],
            'kind' => ['required', 'in:original,strip'],
            'group' => ['required', 'uuid'],
            'slot' => ['required', 'integer', 'min:0'],
        ]);

        $existing = $event->photos()
            ->where('group_uuid', $validated['group'])
            ->where('slot', $validated['slot'])
            ->first();

        if ($existing) {
            return response()->json(['id' => $existing->id]);
        }

        $photo = $event->photos()->create([
            'kind' => $validated['kind'],
            'group_uuid' => $validated['group'],
            'slot' => $validated['slot'],
            'path' => $request->file('photo')->store("events/{$event->id}"),
        ]);

        return response()->json(['id' => $photo->id], 201);
    }
}

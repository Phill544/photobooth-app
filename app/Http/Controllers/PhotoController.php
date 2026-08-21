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
        return Storage::response($photo->path);
    }

    public function store(Request $request, Event $event)
    {
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

<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateThumbnail;
use App\Models\Event;
use App\Models\Photo;
use App\Support\Durability;
use App\Support\ImageResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PhotoController extends Controller
{
    public function show(Request $request, Event $event, Photo $photo)
    {
        return ImageResponse::immutable($request, $photo->path, $photo->downloadName($event->name));
    }

    // The album grids ask for this one; it only exists once the queued job has
    // written a derivative, and the grid links to the original until then.
    public function thumb(Request $request, Event $event, Photo $photo)
    {
        abort_unless($photo->thumb_path, 404);

        return ImageResponse::immutable($request, $photo->thumb_path, $photo->downloadName($event->name));
    }

    public function destroyGroup(Event $event, string $group)
    {
        abort_unless($event->managedBy(auth()->user()), 403);

        $photos = $event->photos()->where('group_uuid', $group)->get();
        abort_if($photos->isEmpty(), 404);

        Storage::delete($photos->flatMap->paths()->all());
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

        // Checked per request, not just at deploy: a bucket detached mid-life, a
        // preview environment, or a container missing the injected disk config
        // all fall back to the local disk long after the deploy gate passed.
        abort_if(Durability::diskIsEphemeral(), 503, 'Photo storage is not configured durably.');

        // The object-storage disk is built with 'throw' => false and 'report' =>
        // false, so a refused write comes back as a plain false with nothing
        // logged. Storing that as the path would tell the guest 201 while their
        // strip went nowhere — and the booth drops its own copy on a 201.
        $path = $request->file('photo')->store("events/{$event->id}");
        abort_if($path === false, 503, 'The photo could not be stored.');

        $photo = $event->photos()->create([
            'kind' => $validated['kind'],
            'group_uuid' => $validated['group'],
            'slot' => $validated['slot'],
            'path' => $path,
        ]);

        GenerateThumbnail::dispatch($photo);

        return response()->json(['id' => $photo->id], 201);
    }
}

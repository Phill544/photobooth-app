<?php

namespace App\Http\Controllers;

use App\Jobs\BuildEventArchive;
use App\Models\Archive;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToRetrieveMetadata;

class ArchiveController extends Controller
{
    public function store(Request $request, Event $event)
    {
        abort_unless($event->managedBy($request->user()), 403);

        if ($event->photos()->doesntExist()) {
            throw ValidationException::withMessages([
                'archive' => 'There is nothing in this album to download yet.',
            ])->redirectTo("/events/{$event->code}");
        }

        // One at a time. A host who taps twice should not set two builds of the
        // same hundreds of megabytes running, and the second would only produce
        // the same file under a different name.
        $pending = $event->archives()->where('status', 'pending')->first();

        if (! $pending) {
            BuildEventArchive::dispatch($event->archives()->create([
                'requested_by' => $request->user()->id,
            ]));
        }

        return redirect("/events/{$event->code}")
            ->with('status', "We're building it now — we'll email you when it's ready.");
    }

    // The signature is the credential (see Archive::downloadUrl), so this route
    // asks for no login: the link is emailed and gets opened on whatever device
    // reads the mail, which is rarely the one the host signed in on.
    public function download(Archive $archive)
    {
        abort_unless($archive->path, 404);

        // A row can outlive its file — the retention sweep and the host's delete
        // both clear the prefix — and the answer is the one the image routes
        // give: 404 rather than a 500 out of Flysystem.
        try {
            return Storage::download($archive->path, str($archive->event->name)->slug().'-photos.zip', [
                'Content-Type' => 'application/zip',
            ]);
        } catch (UnableToRetrieveMetadata) {
            abort(404);
        }
    }
}

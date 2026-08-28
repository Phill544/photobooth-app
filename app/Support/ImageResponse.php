<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImageResponse
{
    // A stored image never changes: an upload writes a new random path, so a
    // photo's URL (which carries its id) is immutable for free. A logo's route is
    // one stable URL per event, so `Event::logoUrl()` fingerprints the stored path
    // into the query — a replaced logo becomes a different URL instead of a
    // year-long stale cache entry. `private`, not `public`: an album is only as
    // private as its event code, and a deleted session must not linger in a
    // shared cache anywhere.
    public static function immutable(Request $request, string $path, ?string $name = null): StreamedResponse
    {
        // A row can outlive its file — a detached bucket, a release that ran
        // before storage was attached, a purge that cleared the prefix and then
        // failed to drop the rows. Flysystem calls that an exception; this route
        // has to call it a 404, because an album asks it once per tile and a 500
        // turns one bad row into a wall of the most expensive page the app can
        // render (measured: 1.2s and 902KB each, against 0.40s and 79KB for a
        // photo that is really there). The check costs nothing when the file is
        // present, which is why it isn't a Storage::exists() first.
        try {
            $response = Storage::response($path, $name, [
                'Cache-Control' => 'private, max-age=31536000, immutable',
                'ETag' => '"'.md5($path).'"',
                // A meta tag cannot ride on a JPEG. robots.txt already tells a
                // compliant crawler not to fetch this path; the header is for one
                // that asked anyway.
                'X-Robots-Tag' => 'noindex',
            ]);
        } catch (FilesystemException) {
            abort(404);
        }

        // A phone that already has the bytes gets 304 and no body.
        $response->isNotModified($request);

        return $response;
    }
}

<?php

namespace App\Jobs;

use App\Models\Photo;
use App\Support\Thumbnail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

// Off the request: a guest is waiting on four or five uploads in a row, and
// resizing a strip is the one part of that the album can catch up on later.
class GenerateThumbnail implements ShouldQueue
{
    use Queueable;

    // A host can delete a session seconds after a guest shares it. There's
    // nothing to resize then, and nothing to report.
    public $deleteWhenMissingModels = true;

    public function __construct(public Photo $photo) {}

    public function handle(): void
    {
        $path = dirname($this->photo->path).'/thumbs/'.basename($this->photo->path);

        // The disk answers a refused write with `false`, not an exception, so
        // recording the path regardless would point every album tile at a file
        // that was never written. Failing the job puts it in the queue's failed
        // list instead, where someone can see it.
        throw_unless(
            Storage::put($path, Thumbnail::fromImage(Storage::get($this->photo->path))),
            new \RuntimeException("Could not write the derivative for photo {$this->photo->id}."),
        );

        $this->photo->update(['thumb_path' => $path]);
    }
}

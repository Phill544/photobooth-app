<?php

namespace App\Console\Commands;

use App\Models\Photo;
use App\Support\Durability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Run this as a deploy command: it fails the release rather than letting one go
// live that writes guests' photos somewhere they won't survive.
class CheckStorage extends Command
{
    protected $signature = 'photobooth:check-storage {--photos : Also check that every photo row still has its file}';

    protected $description = 'Fail if photos would be written somewhere that does not survive a deploy';

    public function handle(): int
    {
        $disk = config('filesystems.default');
        $driver = config("filesystems.disks.{$disk}.driver");

        // The same question the upload path asks on every request; this is the
        // one that stops a release built that way from going live at all.
        if (Durability::diskIsEphemeral()) {
            $this->error("The default disk [{$disk}] is a local disk, and this is the [".app()->environment().'] environment.');
            $this->error('Photos written there die with the container. Attach an object storage bucket and mark it the default disk.');

            return self::FAILURE;
        }

        // A bucket that takes a write and loses it looks identical to a working
        // one until an event is over, so read the bytes back.
        $probe = '.storage-check';
        $token = (string) Str::uuid();

        if (! Storage::put($probe, $token) || Storage::get($probe) !== $token) {
            $this->error("The default disk [{$disk}] did not return what was just written to it.");

            return self::FAILURE;
        }

        Storage::delete($probe);

        $this->info("Storage OK — default disk [{$disk}] ({$driver}) took a write and gave it back.");

        return $this->option('photos') ? $this->checkPhotos() : self::SUCCESS;
    }

    // One HEAD per photo, so this is opt-in rather than part of the deploy gate.
    // It answers the question the guards can't: has anything already gone?
    private function checkPhotos(): int
    {
        $photos = Photo::all();
        $missing = $photos->reject(fn (Photo $photo) => Storage::exists($photo->path));

        $this->line("Files: {$missing->count()} of {$photos->count()} photos have no file on the disk.");

        if ($missing->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($missing->take(10) as $photo) {
            $this->warn("  photo {$photo->id} (event {$photo->event_id}) -> {$photo->path}");
        }

        return self::FAILURE;
    }
}

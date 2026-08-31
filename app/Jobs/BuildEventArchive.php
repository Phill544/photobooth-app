<?php

namespace App\Jobs;

use App\Models\Archive;
use App\Models\Photo;
use App\Notifications\ArchiveReady;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

// Zips an event's night into one file and emails the host a link to it. Queued
// because a busy event is thousands of files and hundreds of megabytes, which
// is a request that would still be running when the gateway gave up — the same
// arithmetic that made Event::purge() sweep by prefix.
class BuildEventArchive implements ShouldQueue
{
    use Queueable;

    // Zipping already-compressed JPEGs is work for no gain, so the job is I/O
    // bound rather than CPU bound; the timeout is about the number of files.
    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(public Archive $archive) {}

    public function handle(): void
    {
        $event = $this->archive->event;

        // Photos stream through a temp file one at a time rather than being
        // collected in memory: ZipArchive::addFromString holds everything it is
        // handed until close(), which on a four-thousand-photo event is the
        // whole event in RAM. addFile lets libzip read them back at close time.
        $temp = tempnam(sys_get_temp_dir(), 'pb-archive-');
        $staged = [];
        $counts = ['strip' => 0, 'original' => 0];

        // try/finally, because every one of these staged files is a full-size
        // camera original: a throw on the way past used to leave the whole
        // night in the temp directory, and then do it again on the retry.
        try {
            $zip = new ZipArchive;
            $zip->open($temp, ZipArchive::OVERWRITE | ZipArchive::CREATE);

            foreach ($event->photos()->orderBy('id')->cursor() as $photo) {
                // A row can outlive its file — this app answers 404 for exactly
                // that on the album — and one orphan must not cost the host the
                // whole night. Skipped rather than fatal, and the counts below
                // are of what actually went in rather than what the rows claim.
                if (! $file = $this->stage($photo)) {
                    continue;
                }

                $staged[] = $file;
                $counts[$photo->kind]++;

                $folder = $photo->kind === 'strip' ? 'strips' : 'photos';
                $zip->addFile($file, $entry = "{$folder}/{$photo->downloadName($event->name)}");

                // Stored, not deflated. These are JPEGs — already compressed, so
                // deflate spends real CPU per file to save almost nothing, and a
                // busy night is thousands of them. Measured on the 4000-photo
                // event: see the note in DEPLOY.md.
                $zip->setCompressionName($entry, ZipArchive::CM_STORE);
            }

            $zip->close();

            // Under the event's own prefix, so the two places that already sweep
            // that prefix — the host's delete and the retention sweep — take the
            // archive with them without having to know it exists. An archive that
            // outlived the photos it holds would make the retention window a lie.
            $path = "events/{$event->id}/archives/".Str::uuid().'.zip';
            $written = Storage::writeStream($path, fopen($temp, 'r'));
        } finally {
            foreach ([$temp, ...$staged] as $file) {
                @unlink($file);
            }
        }

        // The disk is built with 'throw' => false, so a refused write is a bare
        // false — recording the path anyway would email the host a link to
        // nothing.
        if (! $written) {
            $this->archive->update(['status' => 'failed']);

            return;
        }

        $this->archive->update([
            'status' => 'ready',
            'path' => $path,
            'bytes' => Storage::size($path),
            'strip_count' => $counts['strip'],
            'photo_count' => $counts['original'],
            'expires_at' => now()->addDays(Archive::LIFETIME_DAYS),
        ]);

        $this->archive->requester?->notify(new ArchiveReady($this->archive->fresh()));
    }

    // One photo's bytes to a temp file, never through a string: a phone's
    // originals are as big as its camera made them. Null when the row has
    // outlived its file — the disk is built with 'throw' => false, so that
    // arrives as a null stream rather than an exception.
    private function stage(Photo $photo): ?string
    {
        $from = Storage::readStream($photo->path);

        if (! $from) {
            return null;
        }

        $file = tempnam(sys_get_temp_dir(), 'pb-photo-');
        $to = fopen($file, 'w');

        stream_copy_to_stream($from, $to);

        fclose($from);
        fclose($to);

        return $file;
    }

    // A host watching a spinner that will never stop is worse than being told
    // it went wrong, so the row records the failure rather than only the log.
    public function failed(?Throwable $exception): void
    {
        $this->archive->update(['status' => 'failed']);
    }
}

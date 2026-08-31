<?php

namespace App\Console\Commands;

use App\Models\Archive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

// A built archive is a second copy of an entire event sitting on the disk. It
// is offered for a week and then it goes, or every download-all a host ever
// asked for accumulates behind them.
class SweepArchives extends Command
{
    protected $signature = 'photobooth:sweep-archives';

    protected $description = 'Delete built archives whose download link has expired';

    public function handle(): int
    {
        $due = Archive::whereNotNull('expires_at')->where('expires_at', '<=', now())->get();

        if ($due->isEmpty()) {
            $this->info('Nothing to sweep.');

            return self::SUCCESS;
        }

        foreach ($due as $archive) {
            if ($archive->path) {
                Storage::delete($archive->path);
            }

            $this->info("Swept archive {$archive->id} for event {$archive->event_id} ({$archive->size()}).");
            $archive->delete();
        }

        return self::SUCCESS;
    }
}

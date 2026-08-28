<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;

class PurgeEvent extends Command
{
    protected $signature = 'photobooth:purge-event {code} {--force : Delete without asking, for scheduled runs}';

    protected $description = 'Delete an event, its photos, and their files';

    public function handle(): int
    {
        $event = Event::where('code', strtoupper($this->argument('code')))->first();
        if (! $event) {
            $this->error('No event with that code.');

            return self::FAILURE;
        }

        $photoCount = $event->photos()->count();
        if (! $this->option('force') && ! $this->confirm("Delete '{$event->name}' and its {$photoCount} photos?")) {
            return self::SUCCESS;
        }

        $event->purge();

        $this->info("Purged '{$event->name}'.");

        return self::SUCCESS;
    }
}

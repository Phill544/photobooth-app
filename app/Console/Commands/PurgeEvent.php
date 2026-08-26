<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeEvent extends Command
{
    protected $signature = 'photobooth:purge-event {code}';

    protected $description = 'Delete an event, its photos, and their files';

    public function handle(): int
    {
        $event = Event::where('code', strtoupper($this->argument('code')))->first();
        if (! $event) {
            $this->error('No event with that code.');

            return self::FAILURE;
        }

        $photoCount = $event->photos()->count();
        if (! $this->confirm("Delete '{$event->name}' and its {$photoCount} photos?")) {
            return self::SUCCESS;
        }

        Storage::delete($event->photos()->get()->flatMap->paths()->all());
        $event->delete(); // photo rows cascade

        $this->info("Purged '{$event->name}'.");

        return self::SUCCESS;
    }
}

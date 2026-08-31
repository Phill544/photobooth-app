<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;

class SweepExpired extends Command
{
    protected $signature = 'photobooth:sweep-expired';

    protected $description = 'Delete the photos of events whose retention window and grace period have both passed';

    public function handle(): int
    {
        // Two conditions, and the second one matters as much as the first: the
        // grace period is what lets a host who has already missed the date ask
        // for more time and still get their album back.
        $due = Event::whereNotNull('photos_expire_at')
            ->where('photos_expire_at', '<=', now()->subDays(Event::PURGE_GRACE_DAYS))
            // Having photos is what makes an album due. An album already swept
            // has none, so it drops out here rather than costing an object-store
            // round trip every night for the rest of its life.
            ->whereHas('photos')
            ->get();

        if ($due->isEmpty()) {
            $this->info('Nothing to sweep.');

            return self::SUCCESS;
        }

        // Nobody is watching this run, so it says what it took.
        foreach ($due as $event) {
            $count = $event->photos()->count();
            $event->purgePhotos();

            $this->info("Swept {$event->code} ({$event->name}) — {$count} photos, expired {$event->photos_expire_at->format('j M Y')}.");
        }

        return self::SUCCESS;
    }
}

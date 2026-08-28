<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

// The two albums worth measuring against, kept out of the default seed because
// they are ~9,500 real JPEGs and ~280MB of disk (about 15 seconds — drawing the
// images is a constant, it is the writing that scales):
//
//     php artisan db:seed --class=BigEventSeeder
//
// The big one is the night that started all this — 4000 photos, which the album
// used to render into one page of 3997 <img> tags.
class BigEventSeeder extends Seeder
{
    use SeedsAlbums;

    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        // The demo host owns these too, so they show up on its dashboard.
        $this->call(DatabaseSeeder::class);
        $host = User::where('email', 'demo@example.com')->firstOrFail();

        // A busy launch: five hours, a 2×2 grid template, 750 photos.
        $this->fillAlbum(Event::firstOrCreate(['code' => 'SUNSET'], [
            'name' => 'Sunset Rooftop Launch',
            'owner_id' => $host->id,
            'template' => 'grid',
            'theme' => 'champagne',
            'caption' => 'Sunset Rooftop · Level 12',
        ]), sessions: 150, openedAt: Carbon::parse('2026-08-23 18:00'), hours: 5);

        // The one that stalled a dev server for 45 seconds.
        $this->fillAlbum(Event::firstOrCreate(['code' => 'NEWYRS'], [
            'name' => "New Year's Eve at The Foundry",
            'owner_id' => $host->id,
            'template' => 'classic',
            'theme' => 'midnight',
            'caption' => 'The Foundry · NYE',
        ]), sessions: 1000, openedAt: Carbon::parse('2025-12-31 21:00'), hours: 6);
    }
}

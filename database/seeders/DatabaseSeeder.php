<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    use SeedsAlbums;

    public function run(): void
    {
        // Demo data is for local development only — never plant it in production
        // (a deploy pipeline running db:seed must not create this account).
        if (! app()->environment('local')) {
            return;
        }

        // Dev login: demo@example.com / password (admin, so it sees every event).
        $host = User::firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo Host', 'password' => 'password'],
        );
        // is_admin is intentionally not mass-assignable (no register-time escalation),
        // so grant it explicitly here.
        $host->forceFill(['is_admin' => true])->save();

        // The booth to shoot into, and the empty album that goes with it.
        Event::firstOrCreate(['code' => 'PARTY2'], [
            'name' => 'Demo Event',
            'owner_id' => $host->id,
            'template' => 'classic',
            'theme' => 'midnight',
        ]);

        // Two albums that have actually been used. They are small on purpose:
        // this seeder runs on every `db:seed`, and the sizes worth measuring
        // against — a 750-photo launch and a 4000-photo New Year's — are ~9,500
        // files and ~280MB of them, so they live in BigEventSeeder.
        $this->fillAlbum(Event::firstOrCreate(['code' => 'BREKKY'], [
            'name' => 'Sam & Ali — Engagement Drinks',
            'owner_id' => $host->id,
            'template' => 'quad',
            'theme' => 'blush',
            'caption' => 'Sam & Ali · 26.08.26',
        ]), sessions: 1, openedAt: Carbon::parse('2026-08-26 19:30'), hours: 2);

        // Closed, the way an event looks the week after: ~46 tags of album,
        // which is what a normal night used to weigh before this had pages.
        $this->fillAlbum(Event::firstOrCreate(['code' => 'GARDEN'], [
            'name' => 'Nguyen Garden Party',
            'owner_id' => $host->id,
            'template' => 'classic',
            'theme' => 'forest',
            'caption' => 'The Nguyens at home',
            'closed_at' => Carbon::parse('2026-08-08 18:30'),
        ]), sessions: 12, openedAt: Carbon::parse('2026-08-08 14:00'), hours: 4);
    }
}

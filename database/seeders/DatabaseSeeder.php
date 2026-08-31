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
        // is_admin and email_verified_at are both intentionally not mass-assignable
        // (no register-time escalation, no self-declared address), so set them here.
        $host->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();

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

        // The two album states that are otherwise a chore to reach by hand — a
        // PIN gate, and a night whose window has run out. Both matter on a phone
        // (the PIN screen is a form a guest types into; the expired page is what
        // a guest meets after everyone has gone home), and neither can be made
        // by shooting into a booth: an expired event refuses uploads.
        $this->fillAlbum(Event::firstOrCreate(['code' => 'SECRET'], [
            'name' => 'Marsh Wedding',
            'owner_id' => $host->id,
            'template' => 'grid',
            'theme' => 'champagne',
            'caption' => 'Ana & Rob',
            'album_privacy' => 'pin',
            'album_pin' => 'bridesmaids',
        ]), sessions: 5, openedAt: Carbon::parse('2026-08-15 20:00'), hours: 3);

        // Expired, but inside the grace period: the host still sees the album
        // and the countdown, a guest gets the expired page, and the sweep is
        // still a fortnight off.
        $this->fillAlbum(Event::firstOrCreate(['code' => 'LAPSED'], [
            'name' => 'Winter Staff Party',
            'owner_id' => $host->id,
            'template' => 'classic',
            'theme' => 'sand',
            'closed_at' => Carbon::parse('2026-06-01 23:00'),
            'photos_expire_at' => Carbon::now()->subDays(Event::PURGE_GRACE_DAYS - 14),
        ]), sessions: 4, openedAt: Carbon::parse('2026-06-01 19:00'), hours: 3);

        // And the state after the sweep has been through: the code still answers,
        // the album says what happened to the photos, and no date brings them
        // back. No fillAlbum call — the point of this one is that it is empty.
        $swept = Event::firstOrCreate(['code' => 'SWEPT2'], [
            'name' => 'Spring Fundraiser',
            'owner_id' => $host->id,
            'closed_at' => Carbon::parse('2026-03-14 22:00'),
            'photos_expire_at' => Carbon::parse('2026-06-12 23:59'),
        ]);
        $swept->photos_purged_at ??= Carbon::parse('2026-07-12 03:15');
        $swept->save();
    }
}

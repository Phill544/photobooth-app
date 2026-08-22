<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
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

        Event::firstOrCreate(['code' => 'PARTY2'], ['name' => 'Demo Event', 'owner_id' => $host->id]);
    }
}

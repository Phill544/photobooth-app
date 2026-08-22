<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Dev login: demo@example.com / password (admin, so it sees every event).
        $host = User::firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo Host', 'password' => 'password', 'is_admin' => true],
        );

        Event::firstOrCreate(['code' => 'PARTY2'], ['name' => 'Demo Event', 'owner_id' => $host->id]);
    }
}

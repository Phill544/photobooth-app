<?php

namespace Database\Seeders;

use App\Models\Event;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Event::firstOrCreate(['code' => 'PARTY2'], ['name' => 'Demo Event']);
    }
}

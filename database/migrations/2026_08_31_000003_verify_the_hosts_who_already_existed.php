<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // The column has existed since the framework's first migration and has
    // never been written, so every host account is currently unverified. Once
    // verification gates event creation, deploying without this would gate all
    // of them at once — hosts who were told nothing about it, some of them
    // mid-event. They verified themselves the day they were let in; only new
    // registrations have to prove it now.
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    // Irreversible on purpose: rolling back cannot know which of these were
    // grandfathered and which were verified for real, and guessing wrong locks
    // a host out of their own event.
    public function down(): void
    {
        //
    }
};

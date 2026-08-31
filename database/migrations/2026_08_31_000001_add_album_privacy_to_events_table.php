<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Every album that already exists is one guests are already reading
            // links to, so the column arrives set to what they have today.
            $table->string('album_privacy', 8)->default('open');
            // Kept in the clear on purpose: the host reads it out to a room, so
            // they have to be able to read it back off their own phone. It is a
            // door code sitting beside another door code (the event code, also
            // in the clear, in the next column but one) guarding the same album
            // — hashing one and not the other would be theatre.
            $table->string('album_pin', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['album_privacy', 'album_pin']);
        });
    }
};

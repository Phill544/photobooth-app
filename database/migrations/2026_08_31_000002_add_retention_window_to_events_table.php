<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Null on every event that already exists, and only on those: their
            // guests shared photos having been told nothing about a window, so
            // backfilling ninety days here would schedule the deletion of an
            // album somebody thinks is permanent. New events get the window
            // from Event::booted() instead, where it can be counted from now.
            $table->timestamp('photos_expire_at')->nullable();
            // When the sweep actually took them. Recorded rather than inferred
            // from an empty album, because a host who deleted every session by
            // hand has not had theirs swept — and because after this is set no
            // date brings the photos back, so no date may reopen the album.
            $table->timestamp('photos_purged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['photos_expire_at', 'photos_purged_at']);
        });
    }
};

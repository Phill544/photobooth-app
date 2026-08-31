<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A row per download-all a host has asked for. It exists because the build
    // is queued: without it there is nothing to show the host while the job
    // runs, nothing to hang a lifetime on, and nothing for the nightly sweep to
    // find when the link has expired.
    public function up(): void
    {
        Schema::create('archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            // Who to email when it is ready. Nulled rather than cascaded if that
            // host's account goes: the archive is the event's, not theirs.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 8)->default('pending'); // pending | ready | failed
            $table->string('path')->nullable();              // only once it is built
            $table->unsignedBigInteger('bytes')->nullable();
            $table->unsignedInteger('photo_count')->nullable();
            $table->unsignedInteger('strip_count')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archives');
    }
};

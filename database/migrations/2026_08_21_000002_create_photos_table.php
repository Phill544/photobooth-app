<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->uuid('group_uuid');
            $table->unsignedTinyInteger('slot');
            $table->string('path');
            $table->timestamps();

            // Retried uploads over flaky wifi must not duplicate photos.
            $table->unique(['group_uuid', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photos');
    }
};

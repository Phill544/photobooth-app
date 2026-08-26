<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Photo extends Model
{
    protected $fillable = ['kind', 'group_uuid', 'slot', 'path'];

    protected $casts = ['slot' => 'integer'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    // What a phone should call this file once it's saved. The stored path is a
    // random hash, and a browser prefers the name the server states over an
    // <a download> attribute — so saying nothing puts that hash in a camera roll.
    public function downloadName(string $eventName): string
    {
        $kind = $this->kind === 'strip' ? 'strip' : 'photo';

        return Str::slug($eventName)."-{$kind}-{$this->id}.".pathinfo($this->path, PATHINFO_EXTENSION);
    }
}

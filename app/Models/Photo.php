<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Photo extends Model
{
    protected $fillable = ['kind', 'group_uuid', 'slot', 'path', 'thumb_path'];

    protected $casts = ['slot' => 'integer'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    // Every file this row owns: the original, and the derivative once the queued
    // job has written one. Deleting a photo means deleting both.
    public function paths(): array
    {
        return array_filter([$this->path, $this->thumb_path]);
    }

    public function url(string $eventCode): string
    {
        return "/e/{$eventCode}/photos/{$this->id}";
    }

    // What an album grid asks for: the derivative once there is one, the
    // original until the queue catches up. Enlarging always shows the original.
    public function gridUrl(string $eventCode): string
    {
        return $this->url($eventCode).($this->thumb_path ? '/thumb' : '');
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

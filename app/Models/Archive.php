<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class Archive extends Model
{
    // A built archive is offered for a week. Long enough that a host who asked
    // on the night can still fetch it the following weekend, short enough that
    // a copy of every guest's photos is not sitting on the disk indefinitely.
    public const LIFETIME_DAYS = 7;

    protected $fillable = ['event_id', 'requested_by', 'status', 'path', 'bytes', 'photo_count', 'strip_count', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->expires_at?->isFuture();
    }

    // The signature is the credential: this link is emailed, and gets opened on
    // whatever device the host reads mail on rather than the one they log in
    // with. It carries its own expiry, matched to the file's.
    public function downloadUrl(): string
    {
        return URL::temporarySignedRoute('archive.download', $this->expires_at, ['archive' => $this->id]);
    }

    // "1.4 GB" rather than a number of bytes: this goes in an email so a host
    // knows what they are about to pull down over hotel wifi.
    public function size(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = (float) $this->bytes;

        for ($unit = 0; $bytes >= 1024 && $unit < count($units) - 1; $unit++) {
            $bytes /= 1024;
        }

        return round($bytes, $bytes < 10 && $unit > 0 ? 1 : 0).' '.$units[$unit];
    }
}

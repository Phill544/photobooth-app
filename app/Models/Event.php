<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Event extends Model
{
    // No 0, O, 1, I — codes get read aloud and typed by hand at events.
    public const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    // Strip template keys the owner can pick. The geometry for each lives in
    // resources/js/templates.ts (canvas needs it); these keys must stay in sync.
    public const TEMPLATES = [
        'classic' => 'Classic strip · 3 photos',
        'quad' => 'Tall strip · 4 photos',
        'grid' => 'Grid · 2×2',
        'single' => 'Single shot',
    ];

    // Strip colour themes; hex values live in resources/js/strip-theme.ts.
    public const STRIP_THEMES = [
        'midnight' => 'Midnight',
        'blush' => 'Blush',
        'forest' => 'Forest',
        'sand' => 'Sand',
        'champagne' => 'Champagne',
    ];

    protected $fillable = ['name', 'code', 'closed_at', 'template', 'theme', 'caption', 'logo_path', 'owner_id'];

    protected $casts = ['closed_at' => 'datetime'];

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    // One stable route per event, but the host can replace the file behind it —
    // and images are served with a year of immutable caching. So the URL carries
    // the stored file's fingerprint: a swapped logo is simply a different URL.
    // Only meaningful for an event that has one; every call site checks first.
    public function logoUrl(): string
    {
        return "/e/{$this->code}/logo?v=".substr(md5($this->logo_path), 0, 8);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function managedBy(?User $user): bool
    {
        return $user !== null && ($user->is_admin || $user->id === $this->owner_id);
    }

    // Rows cascade, bytes never do, so both the owner's delete button and
    // photobooth:purge-event come through here; a second place that knew an
    // event's files would drift from this one.
    //
    // The photos go by prefix, not one path at a time. Storage::delete() spends
    // an object-store round trip per file and a busy night is thousands of them
    // — a request that would still be deleting when the gateway gives up, where
    // clearing the prefix is a handful of batched calls. It is also the only way
    // to catch a derivative GenerateThumbnail wrote but never recorded, since no
    // row names that file. Correct only while every photo is written under this
    // prefix, which PhotoController::store does and EventDeleteTest pins.
    //
    // The logo is separate: logos are not per-event, so it goes by path.
    public function purge(): void
    {
        Storage::deleteDirectory("events/{$this->id}");
        if ($this->logo_path) {
            Storage::delete($this->logo_path);
        }

        $this->delete(); // photo rows cascade
    }

    protected static function booted(): void
    {
        static::creating(function (Event $event) {
            $event->code = $event->code ? strtoupper($event->code) : self::freshCode();
        });
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, strtoupper($value), $field);
    }

    public static function freshCode(): string
    {
        do {
            $code = '';
            foreach (range(1, 6) as $i) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (self::where('code', $code)->exists());

        return $code;
    }
}

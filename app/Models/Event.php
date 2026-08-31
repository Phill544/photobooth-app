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

    // A host types a word the room already knows, so this is prose, not digits.
    // Both ends are load-bearing in four places — the column, the validator, the
    // host's field and the guest's — and the guest's field is the one that
    // silently truncates, so they are one constant rather than four literals.
    public const PIN_MIN_LENGTH = 4;

    public const PIN_MAX_LENGTH = 16;

    // Who can open the album. The booth is unaffected by all three: a guest can
    // always shoot, always see their own strip, and always save it to their
    // phone — this is only about the wall of everyone else's.
    public const ALBUM_PRIVACY = [
        'open' => 'Anyone with the link',
        'pin' => 'Guests who know a PIN',
        'hidden' => 'Only me',
    ];

    // How long a new event's photos are kept, and how long after that date the
    // files actually go. The gap is what lets a host who has already missed the
    // date ask for more time and still get their album back — extending inside
    // the grace period undoes the expiry, because nothing has been deleted yet.
    public const RETENTION_DAYS = 90;

    public const PURGE_GRACE_DAYS = 30;

    protected $fillable = ['name', 'code', 'closed_at', 'template', 'theme', 'caption', 'logo_path', 'owner_id', 'album_privacy', 'album_pin', 'photos_expire_at'];

    protected $casts = [
        'closed_at' => 'datetime',
        'photos_expire_at' => 'datetime',
        'photos_purged_at' => 'datetime',
    ];

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function albumIsHidden(): bool
    {
        return $this->album_privacy === 'hidden';
    }

    public function albumNeedsPin(): bool
    {
        return $this->album_privacy === 'pin';
    }

    // A PIN gets read across a noisy room and typed by somebody who didn't
    // catch the capitals, so it is matched the way the event code is.
    public function pinMatches(?string $attempt): bool
    {
        return $this->album_pin !== null
            && strcasecmp(trim((string) $attempt), $this->album_pin) === 0;
    }

    // The photos are gone. Recorded rather than inferred from an empty album: a
    // host who deleted every session by hand has not had theirs swept, and must
    // not be told they have.
    public function photosWerePurged(): bool
    {
        return $this->photos_purged_at !== null;
    }

    // The album is over — either its window ran out, or the sweep has already
    // been through. Between those two the photos are still there, which is
    // exactly the window in which a host can ask for more time; after the sweep
    // no date brings them back, so no date reopens the album either.
    public function hasExpired(): bool
    {
        return $this->photosWerePurged()
            || ($this->photos_expire_at !== null && $this->photos_expire_at->isPast());
    }

    public function acceptsUploads(): bool
    {
        return ! $this->isClosed() && ! $this->hasExpired();
    }

    // One word for the state every screen labels. There are three of them now,
    // and a closed booth and a finished one are not the same thing to say.
    public function status(): string
    {
        return $this->hasExpired() ? 'finished' : ($this->isClosed() ? 'closed' : 'live');
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
        $this->purgePhotos();

        if ($this->logo_path) {
            Storage::delete($this->logo_path);
        }

        $this->delete();
    }

    // What the retention sweep does, and the half of purge() that is about
    // guests rather than the host: every photo and every file behind one, with
    // the event row left standing so its code keeps explaining itself. A host's
    // logo is their own branding, not a guest's photo, so it stays.
    public function purgePhotos(): void
    {
        Storage::deleteDirectory("events/{$this->id}");

        $this->photos()->delete();

        // Set here rather than mass-assigned: nothing a request sends may claim
        // an album's photos were deleted, because that claim shuts the album.
        $this->photos_purged_at = now();
        $this->save();
    }

    protected static function booted(): void
    {
        static::creating(function (Event $event) {
            $event->code = $event->code ? strtoupper($event->code) : self::freshCode();
            // Counted from now, not backfilled by the migration: only events
            // created after the window existed have guests who were told about
            // one. A host can move it or clear it from the event page.
            $event->photos_expire_at ??= now()->addDays(self::RETENTION_DAYS);
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

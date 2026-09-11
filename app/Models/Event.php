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

    protected $fillable = ['name', 'code', 'closed_at', 'template', 'theme', 'caption', 'caption_hidden', 'logo_path', 'background_path', 'owner_id', 'album_privacy', 'album_pin', 'photos_expire_at'];

    protected $casts = [
        'caption_hidden' => 'boolean',
        'closed_at' => 'datetime',
        'photos_expire_at' => 'datetime',
        'photos_purged_at' => 'datetime',
    ];

    // What the strip actually prints in its footer, decided here rather than on
    // the phone: "the caption, or failing that the event name" was a rule the
    // booth carried, and it left a host with a background whose artwork already
    // says their name no way at all to stop a second one printing over it. An
    // empty field cannot mean that — ConvertEmptyStringsToNull hands the server
    // the same null for "cleared" and "never typed" — so the choice is its own
    // column, and the booth is simply told the answer.
    //
    // A logo still wins over both: it replaces the caption in the footer, and a
    // host who wants neither removes the logo as well.
    public function stripCaption(): string
    {
        return $this->caption_hidden ? '' : ($this->caption ?: $this->name);
    }

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

    // What an unlock is remembered as, so that changing the PIN ends every
    // unlock the old one bought — the host who changes it is doing so because
    // the wrong people have the old one. Trimmed and case-folded, so that
    // re-saving the same word with a stray space or a capital does not shut a
    // room out over a PIN that still opens the album.
    public function pinFingerprint(): string
    {
        return substr(hash('sha256', strtolower(trim((string) $this->album_pin))), 0, 12);
    }

    // The photos are gone. Recorded rather than inferred from an empty album: a
    // host who deleted every session by hand has not had theirs swept, and must
    // not be told they have.
    public function photosWerePurged(): bool
    {
        return $this->photos_purged_at !== null;
    }

    // The window runs from the night, not from the setup. It used to be stamped
    // in the `creating` hook, which meant a host who put a wedding in four weeks
    // early had burned a month of it before anybody arrived — and the consent
    // line promised guests a date that was already running down. The window is a
    // promise made at the moment a guest consents, so it starts when there is
    // something to keep.
    //
    // Called once per upload and does nothing after the first: an album that has
    // already started counting keeps the date its guests were shown, and a host
    // who deliberately cleared the window (an album kept for good) does not get
    // it silently handed back by the next guest through the booth.
    public function startRetentionWindow(): void
    {
        if ($this->photos_expire_at !== null || $this->photos()->exists()) {
            return;
        }

        $this->photos_expire_at = now()->addDays(self::RETENTION_DAYS);
        $this->save();
    }

    // Null means two different things, and the screens have to tell them apart:
    // an album nobody has shot into yet is waiting for its window, while an
    // album with photos and no date is one somebody chose to keep for good.
    public function awaitingFirstPhoto(): bool
    {
        return $this->photos_expire_at === null && ! $this->photos()->exists();
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

    public function archives(): HasMany
    {
        return $this->hasMany(Archive::class);
    }

    // One stable route per event, but the host can replace the file behind it —
    // and images are served with a year of immutable caching. So the URL carries
    // the stored file's fingerprint: a swapped logo is simply a different URL.
    // Only meaningful for an event that has one; every call site checks first.
    public function logoUrl(): string
    {
        return "/e/{$this->code}/logo?v=".substr(md5($this->logo_path), 0, 8);
    }

    // The same stable-route-plus-fingerprint arrangement as the logo, and the
    // fingerprint matters more here: a stale logo is a wrong mark in the footer,
    // a stale background is the whole strip.
    public function backgroundUrl(): string
    {
        return "/e/{$this->code}/background?v=".substr(md5($this->background_path), 0, 8);
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
    // The host's own artwork is separate: a logo and a background are not
    // per-event files, so they go by path.
    public function purge(): void
    {
        $this->purgePhotos();

        foreach ([$this->logo_path, $this->background_path] as $file) {
            if ($file) {
                Storage::delete($file);
            }
        }

        $this->delete();
    }

    // What the retention sweep does, and the half of purge() that is about
    // guests rather than the host: every photo and every file behind one, with
    // the event row left standing so its code keeps explaining itself. A host's
    // logo is their own branding, not a guest's photo, so it stays.
    //
    // A host's background is theirs too, and it lives outside this prefix for
    // exactly that reason — an album whose photos have gone still belongs to a
    // host who may put it back together next year.
    //
    // Built archives are photos too, in one file — they live under the same
    // prefix so the sweep above already takes their bytes, and their rows go
    // here. A retention window that deleted the photos and left a zip of them
    // on the same disk would not be a retention window at all.
    public function purgePhotos(): void
    {
        Storage::deleteDirectory("events/{$this->id}");

        $this->archives()->delete();
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

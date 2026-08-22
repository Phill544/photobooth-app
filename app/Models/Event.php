<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    protected $fillable = ['name', 'code', 'closed_at', 'template', 'theme', 'caption', 'logo_path'];

    protected $casts = ['closed_at' => 'datetime'];

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
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

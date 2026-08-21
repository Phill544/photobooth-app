<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Photo extends Model
{
    protected $fillable = ['kind', 'group_uuid', 'slot', 'path'];

    protected $casts = ['slot' => 'integer'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}

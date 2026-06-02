<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Webhook extends Model
{
    use HasUuids;

    protected $fillable = ['source_id', 'payload', 'headers', 'event_type', 'status'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(Destination::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'headers' => 'encrypted:array',
        ];

    }
}

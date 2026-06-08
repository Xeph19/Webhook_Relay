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

    public function destinations(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(
            Destination::class,
            Source::class,
            'id',          // Local key on sources table
            'source_id',   // Foreign key on destinations table
            'source_id',   // Foreign key on webhooks table
            'id'           // Local key on sources table
        );
    }

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'headers' => 'encrypted:array',
        ];

    }
}

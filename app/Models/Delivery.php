<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    use HasUuids;
    protected $guarded = [];
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'destination_id');
    }
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
    protected function casts(): array
    {
        return [
            'request_headers' => 'encrypted:array',
            'request_payload' => 'encrypted:array',
            'response_headers' => 'array',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Destination extends Model
{
    use HasUuids;
    protected $fillable = ['source_id', 'url', 'is_active','rate_limit_per_minute', 'retry_count'];
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function delivery(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }
    protected function casts(): array
    {
        return [
            'circuit_breaker_opened_at' => 'datetime',
        ];
    }
    /**
     * Check if the circuit breaker is currently open (blocking deliveries)
     */
    public function isCircuitOpen(): bool
    {
        if ($this->circuit_breaker_opened_at === null) {
            return false;
        }
        // Let the circuit cool down after 5 minutes
        if ($this->circuit_breaker_opened_at->addMinutes(5)->isPast()) {
            $this->circuit_breaker_opened_at = null;
            $this->circuit_breaker_failures = 4;
            $this->save();
            return false;
        }
        return true;
    }
}

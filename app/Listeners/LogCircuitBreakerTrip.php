<?php

namespace App\Listeners;

use App\Events\CircuitBreakerTripped;
use Illuminate\Support\Facades\Log;

class LogCircuitBreakerTrip
{
    /**
     * Handle the event.
     */
    public function handle(CircuitBreakerTripped $event): void
    {
        Log::error("CRITICAL: Circuit breaker tripped for destination: {$event->destination->url}. All outgoing webhooks to this destination will be blocked for 5 minutes.");
    }
}

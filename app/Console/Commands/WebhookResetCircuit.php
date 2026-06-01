<?php

namespace App\Console\Commands;

use App\Models\Destination;
use Illuminate\Console\Command;

class WebhookResetCircuit extends Command
{
    protected $signature = 'webhook:reset-circuit {destination_id}';

    protected $description = 'Manually reset the circuit breaker for a destination';

    public function handle()
    {
        $destinationId = $this->argument('destination_id');
        $destination = Destination::find($destinationId);

        if (!$destination) {
            $this->error("Destination with ID [{$destinationId}] not found.");
            return 1;
        }

        $destination->circuit_breaker_failures = 0;
        $destination->circuit_breaker_opened_at = null;
        $destination->save();

        $this->info("Successfully reset circuit breaker for destination: {$destination->url}");

        return 0;
    }
}

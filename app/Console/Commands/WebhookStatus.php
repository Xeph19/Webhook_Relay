<?php

namespace App\Console\Commands;

use App\Models\Destination;
use App\Models\Webhook;
use Illuminate\Console\Command;

class WebhookStatus extends Command
{
    protected $signature = 'webhook:status';

    protected $description = 'Display the status of all sources, destinations, and circuit breakers';

    public function handle()
    {
        $this->info("=== Webhook Relay Engine Status ===");

        // Fetch database stats
        $pending = Webhook::where('status', 'pending')->count();
        $completed = Webhook::where('status', 'completed')->count();
        $failed = Webhook::where('status', 'failed')->count();

        $this->line("Webhooks: <fg=yellow>{$pending} Pending</> | <fg=green>{$completed} Completed</> | <fg=red>{$failed} Failed</>");
        $this->newLine();

        $destinations = Destination::with('source')->get();

        if ($destinations->isEmpty()) {
            $this->warn("No destinations registered.");
            return 0;
        }

        $headers = ['ID', 'Source', 'URL', 'Active', 'Circuit Status', 'Failures', 'Cooldown Remaining'];
        $rows = [];

        foreach ($destinations as $destination) {
            $isCircuitOpen = $destination->isCircuitOpen();
            $circuitStatus = $isCircuitOpen ? '<fg=red;options=bold>OPEN</>' : '<fg=green;options=bold>CLOSED</>';
            
            $cooldown = 'N/A';
            if ($isCircuitOpen && $destination->circuit_breaker_opened_at) {
                $secondsPassed = now()->diffInSeconds($destination->circuit_breaker_opened_at);
                $remaining = 300 - $secondsPassed;
                $cooldown = $remaining > 0 ? "{$remaining}s" : 'Expired (next request will test)';
            }

            $rows[] = [
                $destination->id,
                $destination->source->name ?? 'N/A',
                $destination->url,
                $destination->is_active ? 'Yes' : 'No',
                $circuitStatus,
                $destination->circuit_breaker_failures,
                $cooldown
            ];
        }

        $this->table($headers, $rows);

        return 0;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Delivery;
use App\Jobs\DeliveryWebhookJob;
use Illuminate\Console\Command;

class WebhookRetry extends Command
{
    protected $signature = 'webhook:retry {delivery_id}';

    protected $description = 'Manually retry a failed webhook delivery';

    public function handle()
    {
        $deliveryId = $this->argument('delivery_id');
        $delivery = Delivery::with(['webhook', 'destination'])->find($deliveryId);

        if (!$delivery) {
            $this->error("Delivery log with ID [{$deliveryId}] not found.");
            return 1;
        }

        if (!$delivery->webhook || !$delivery->destination) {
            $this->error("Associated webhook or destination not found for this delivery log.");
            return 1;
        }

        // Re-dispatch the job
        DeliveryWebhookJob::dispatch($delivery->webhook, $delivery->destination);

        $this->info("Successfully re-queued webhook delivery job for destination: {$delivery->destination->url}");

        return 0;
    }
}

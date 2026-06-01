<?php

namespace App\Actions;

use App\Models\Source;
use App\Models\Webhook;
use App\Jobs\DeliveryWebhookJob;
use Exception;
use Symfony\Component\HttpFoundation\Response;

class IngestWebhookAction
{
    /**
     * Execute the webhook ingestion business logic.
     *
     * @param Source $source
     * @param array $payload
     * @param array $headers
     * @param string $eventType
     * @return Webhook
     * @throws Exception
     */
    public function execute(Source $source, array $payload, array $headers, string $eventType): Webhook
    {
        // STEP 1: Check if the source is inactive ($source->is_active).
        // If it is inactive, throw a new Exception with the message: "This webhook source is inactive."
        // and an HTTP status code of 403.
        if(!$source->is_active){
            throw new Exception('This webhook source is inactive', 403);
        };

        // STEP 2: Write the webhook to the database using Webhook::create([ ... ]).
        // Pass: source_id, payload, headers, event_type, and status as 'pending'.
        // Store the returned model in a variable called $webhook.
        $webhook = Webhook::create([
            'source_id'=>$source->id,
            'payload'=>$payload,
            'headers'=>$headers,
            'event_type'=>$eventType,
            'status'=>'pending'
        ]);
        // STEP 3: Fetch all active destinations that belong to this source.
        // Hint: Query $source->destinations() where 'is_active' is true, and call ->get().
        // Store this collection in a variable called $destinations.
        $activeDestinations = $source->destinations()->where('is_active', true)->get();

        // STEP 4: If there are no destinations (check if the collection is empty):
        // - Update the $webhook status to 'completed'.
        // - Return the $webhook model.
        if($activeDestinations->isEmpty()){
            $webhook->update(['status'=>'completed']);
            return $webhook;
        }
        // STEP 5: Loop through each destination in the $destinations collection.
        // Inside the loop, dispatch the DeliveryWebhookJob passing ($webhook, $destination).
        foreach($activeDestinations as $destination){
            DeliveryWebhookJob::dispatch($webhook, $destination);
        }

        // STEP 6: Return the $webhook model.
        return $webhook;
    }
}

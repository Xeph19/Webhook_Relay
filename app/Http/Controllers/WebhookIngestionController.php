<?php

namespace App\Http\Controllers;

use App\Actions\IngestWebhookAction;
use App\Models\Source;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookIngestionController extends Controller
{
    public function __invoke(
        Request $request,
        Source $source,
        IngestWebhookAction $ingestWebhook
    ): JsonResponse {
        // STEP 1: Validate HTTP payload. Check if the raw JSON array from $request is empty.
        // Hint: use empty($request->json()->all()).
        // If it is empty, return a JSON response with the message: 'Empty or Invalid JSON payload'
        // and an HTTP status code of 400.
        if (empty($request->json()->all())) {
            return response()->json(['message' => 'Empty or Invalid JSON Payload'], 400);
        }

        // STEP 2: Wrap the next steps in a try-catch block to handle errors thrown by the Action.
        try {
            $webhook = $ingestWebhook->execute(
                $source,
                $request->json()->all(),
                $request->headers->all(),
                $request->header('X-Event-Type', 'generic.event')
            );

            // B. Get the count of active destinations connected to this source.
            $activeDestinationCount = $source->destinations()->where('is_active', true)->count();

            // C. If the count is 0:
            // Return a JSON response with the message: 'Webhook received, but no active destinations configured',
            // the webhook_id, and an HTTP status code of 200.
            if ($activeDestinationCount === 0) {
                return response()->json([
                    'webhook_id' => $webhook->id,
                    'message' => 'Webhook received, but no active destinations configured',
                ], 200);
            }

            // D. If destinations exist (count > 0):
            // Return a JSON response with the message: 'Webhook accepted and Queued for delivery',
            // the webhook_id, the number of queued jobs, and an HTTP status code of 202.
            return response()->json([
                'webhook_id' => $webhook->id,
                'queued_jobs' => $activeDestinationCount,
                'message' => 'Webhook accepted and Queued for delivery',
            ], 202);

        } catch (Exception $e) {
            // STEP 3: In the catch block:
            // Get the exception status code. If $e->getCode() is 0/empty, fallback to 500.
            // Return a JSON response with the message from the exception ($e->getMessage())
            // and the calculated status code.
            $statusCode = $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR;

            return response()->json([
                'message' => $e->getMessage(),
            ], $statusCode);
        }
    }
}

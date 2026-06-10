<?php

namespace App\Jobs;

use App\Models\Delivery;
use App\Models\Destination;
use App\Models\Webhook;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Events\CircuitBreakerTripped;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class DeliveryWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Delete the job if the model is missing (e.g., if a user deletes a source mid-queue)
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Webhook $webhook,
        public Destination $destination
    ) {}

    public function handle(): void
    {
        if (! $this->destination->source || ! $this->destination->source->is_active) {
            Log::info("Skipping webhook delivery: Source is inactive.");
            return;
        }

        if (! $this->destination->is_active) {
            Log::info("Skipping webhook delivery: Destination is inactive.");
            return;
        }

        // Rate Limiter
        $limiterKey = 'destination-limit:'.$this->destination->id;
        $maxAttempts = $this->destination->rate_limit_per_minute;
        $executed = RateLimiter::attempt(
            $limiterKey,
            $maxAttempts,
            fn () => true,
            60
        );

        if (! $executed) {
            $this->release(10);

            return;
        }

        if (! str_starts_with($this->destination->url, 'https://')) {
            throw new Exception('Insecure destination URL: must use HTTPS.');
        }
        // STEP 1: Circuit Breaker Guard Clause.
        if ($this->destination->isCircuitOpen()) {
            $this->release(300); // 5-minute delay

            return;
        }

        // STEP 2: Gather dynamic data for execution.
        $attempt = $this->attempts();
        $payload = $this->webhook->payload;

        // STEP 3: Cryptographic signing for security.
        $signature = hash_hmac('sha256', json_encode($payload), $this->destination->source->signing_secret);

        // STEP 4: Define the HTTP request headers.
        $headers = [
            'Content-Type' => 'application/json',
            'X-Webhook-Signature' => $signature,
            'X-Webhook-Event' => $this->webhook->event_type,
            'X-Webhook-Attempt' => (string) $attempt,
        ];

        // STEP 5: Record the start time in microseconds.
        $startTime = microtime(true);

        // STEP 6: Execute the HTTP request inside a try-catch block.
        try {

            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->post($this->destination->url, $payload);

            $duration = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $this->logDelivery($attempt, $headers, $duration, $response, 'success');
                $this->handleSuccess();
            } else {
                $errorMessage = 'Server returned status code: '.$response->status();
                $this->logDelivery($attempt, $headers, $duration, $response, 'failed', $errorMessage);
                $this->handleFailure($attempt, $errorMessage);
            }

        } catch (Exception $e) {
            $duration = (int) ((microtime(true) - $startTime) * 1000);
            $this->logDelivery($attempt, $headers, $duration, null, 'failed', $e->getMessage());
            $this->handleFailure($attempt, $e->getMessage());
        }
    }

    /**
     * What to do on a successful delivery
     */
    protected function handleSuccess(): void
    {
        // STEP 7: Reset circuit breaker state (Direct assignment + save to bypass mass-assignment limits).
        if ($this->destination->circuit_breaker_failures > 0) {
            $this->destination->circuit_breaker_failures = 0;
            $this->destination->circuit_breaker_opened_at = null;
            $this->destination->save();
        }

        // STEP 8: Update overall Webhook status to 'completed' in the database.
        $this->webhook->update([
            'status' => 'completed',
        ]);
    }

    /**
     * What to do on a failed delivery (exponential backoff & circuit breaking)
     */
    protected function handleFailure(int $attempt, string $error): void
    {
        // STEP 9: Increment the circuit breaker failures counter on $this->destination.
        $this->destination->increment('circuit_breaker_failures');

        // Refresh model from database to get the updated failures count in memory
        $this->destination->refresh();

        // STEP 10: Trip the circuit breaker if consecutive failures reach 5.
        if ($this->destination->circuit_breaker_failures >= 5) {
            $this->destination->circuit_breaker_opened_at = now();
            $this->destination->save();
            Log::warning('Circuit breaker tripped for destination: '.$this->destination->id);
            CircuitBreakerTripped::dispatch($this->destination);
        }

        // STEP 11: Implement retry logic with Exponential Backoff + Jitter.
        if ($attempt < $this->destination->retry_count) {
            $delay = (int) (pow(2, $attempt) * 5) + rand(0, 2);
            $this->release($delay);
        } else {
            $this->webhook->update(['status' => 'failed']);
            $this->fail(new Exception($error));
        }
    }

    /**
     * Helper to write attempt logs to the database
     */
    protected function logDelivery(
        int $attempt,
        array $requestHeaders,
        int $durationMs,
        $response,
        string $status,
        ?string $errorMessage = null
    ): void {
        // STEP 12: Write a log to the deliveries table.
        Delivery::create([
            'webhook_id' => $this->webhook->id,
            'destination_id' => $this->destination->id,
            'attempt_number' => $attempt,
            'request_headers' => $requestHeaders,
            'request_payload' => $this->webhook->payload,
            'response_headers' => $response ? $response->headers() : null,
            'response_body' => $response ? substr($response->body(), 0, 1000) : null,
            'response_status' => $response ? $response->status() : null,
            'duration_ms' => $durationMs,
            'status' => $status,
            'error_message' => $errorMessage,
        ]);
    }
}

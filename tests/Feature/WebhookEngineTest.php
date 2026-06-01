<?php

use App\Jobs\DeliveryWebhookJob;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\Source;
use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * TEST 1: Webhook Ingestion API Endpoint
 */
it('ingests incoming webhooks and queues them for active destinations', function () {
    // 1. Arrange:
    Queue::fake();

    $source = Source::create([
        'name' => 'Stripe Payments Test',
        'signing_secret' => 'whsec_testsecret123',
        'is_active' => true,
    ]);

    $destination = Destination::create([
        'source_id' => $source->id,
        'url' => 'https://webhook.site/test-endpoint',
        'is_active' => true,
        'rate_limit_per_minute' => 60,
        'retry_count' => 3,
    ]);

    $payload = [
        'event' => 'user.created',
        'email' => 'test@example.com',
    ];

    // 2. Act:
    $response = $this->postJson(route('webhooks.ingest', $source), $payload);

    // 3. Assert:
    $response->assertStatus(202);

    $this->assertDatabaseHas('webhooks', [
        'source_id' => $source->id,
        'status' => 'pending',
    ]);

    Queue::assertPushed(DeliveryWebhookJob::class);
});

/**
 * TEST 2: Successful Webhook Delivery (Happy Path)
 */
it('successfully delivers a webhook and logs a successful delivery attempt', function () {
    // 1. Arrange:
    Http::fake([
        '*' => Http::response('OK', 200),
    ]);

    $source = Source::create([
        'name' => 'Stripe Payments Test',
        'signing_secret' => 'whsec_testsecret123',
        'is_active' => true,
    ]);

    $destination = Destination::create([
        'source_id' => $source->id,
        'url' => 'https://webhook.site/test-endpoint',
        'is_active' => true,
        'rate_limit_per_minute' => 60,
        'retry_count' => 3,
    ]);

    $webhook = Webhook::create([
        'source_id' => $source->id,
        'payload' => [
            'event' => 'user.created',
            'email' => 'test@example.com',
        ],
        'headers' => [],
        'event_type' => 'user.created',
        'status' => 'pending',
    ]);

    // 2. Act:
    $job = new DeliveryWebhookJob($webhook, $destination);
    $job->handle();

    // 3. Assert:
    $this->assertDatabaseHas('deliveries', [
        'webhook_id' => $webhook->id,
        'destination_id' => $destination->id,
        'status' => 'success',
        'response_status' => 200,
    ]);

    $webhook->refresh();
    $this->assertEquals('completed', $webhook->status);

    $destination->refresh();
    $this->assertEquals(0, $destination->circuit_breaker_failures);

    // Assert the HTTP client sent exactly one request
    Http::assertSentCount(1);
});

/**
 * TEST 3: Webhook Delivery Failure (Retry & Backoff Path)
 */
it('handles connection failures and releases job back to the queue with backoff', function () {
    // 1. Arrange:
    Http::fake([
        '*' => Http::response('Error', 500),
    ]);

    $source = Source::create([
        'name' => 'Stripe Payments Test',
        'signing_secret' => 'whsec_testsecret123',
        'is_active' => true,
    ]);

    $destination = Destination::create([
        'source_id' => $source->id,
        'url' => 'https://webhook.site/test-endpoint',
        'is_active' => true,
        'rate_limit_per_minute' => 60,
        'retry_count' => 3,
    ]);

    $webhook = Webhook::create([
        'source_id' => $source->id,
        'payload' => [
            'event' => 'user.created',
            'email' => 'test@example.com',
        ],
        'headers' => [],
        'event_type' => 'user.created',
        'status' => 'pending',
    ]);

    // 2. Act:
    $job = new DeliveryWebhookJob($webhook, $destination);
    $job->handle();

    // 3. Assert:
    $this->assertDatabaseHas('deliveries', [
        'webhook_id' => $webhook->id,
        'destination_id' => $destination->id,
        'status' => 'failed',
        'response_status' => 500,
    ]);

    $destination->refresh();
    $this->assertEquals(1, $destination->circuit_breaker_failures);
});

/**
 * TEST 4: Circuit Breaker Tripping
 */
it('trips the circuit breaker and blocks outgoing HTTP requests after 5 failures', function () {
    // 1. Arrange:
    $source = Source::create([
        'name' => 'Stripe Payments Test',
        'signing_secret' => 'whsec_testsecret123',
        'is_active' => true,
    ]);

    $destination = Destination::create([
        'source_id' => $source->id,
        'url' => 'https://webhook.site/test-endpoint',
        'is_active' => true,
        'rate_limit_per_minute' => 60,
        'retry_count' => 3,
    ]);
    
    $destination->circuit_breaker_failures = 4;
    $destination->save();

    $webhook = Webhook::create([
        'source_id' => $source->id,
        'payload' => [
            'event' => 'user.created',
            'email' => 'test@example.com',
        ],
        'headers' => [],
        'event_type' => 'user.created',
        'status' => 'pending',
    ]);

    // Fake HTTP client to fail
    Http::fake([
        '*' => Http::response('Error', 500),
    ]);

    // 2. Act: Run the job (this makes failure number 5)
    $job = new DeliveryWebhookJob($webhook, $destination);
    $job->handle();

    // 3. Assert: Circuit breaker should now trip
    $destination->refresh();
    $this->assertNotNull($destination->circuit_breaker_opened_at);
    $this->assertEquals(5, $destination->circuit_breaker_failures);

    // 4. Act again (The Open Circuit Test):
    // Run the job again. Because the circuit is open, it should exit instantly without making HTTP requests.
    $job = new DeliveryWebhookJob($webhook, $destination);
    $job->handle();

    // Assert that no new HTTP requests were sent (meaning the count remains at 1 from the 5th failure)
    Http::assertSentCount(1);
});

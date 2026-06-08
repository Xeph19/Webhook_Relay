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
    $timestamp = time();
    $rawPayload = json_encode($payload);
    $signature = hash_hmac('sha256', $timestamp.'.'.$rawPayload, $source->signing_secret);

    $response = $this->postJson(route('webhooks.ingest', $source), $payload, ['X-Webhook-Signature' => $signature,
        'X-Webhook-Timestamp' => $timestamp, ]);

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
it('rejects webhooks with missing security headers', function () {
    $source = Source::create([
        'name' => 'Stripe Payments Test',
        'signing_secret' => 'whsec_testsecret123',
        'is_active' => true,
    ]);

    $response = $this->postJson(route('webhooks.ingest', $source), ['data' => 'test']);
    $response->assertStatus(401);
});

it('rejects webhooks with invalid signatures', function () {
    $source = Source::create([
        'name' => 'Stripe Payments Test',
        'signing_secret' => 'whsec_testsecret123',
        'is_active' => true,
    ]);

    $response = $this->postJson(
        route('webhooks.ingest', $source),
        ['data' => 'test'],
        [
            'X-Webhook-Signature' => 'invalid-sig',
            'X-Webhook-Timestamp' => time(),
        ]
    );
    $response->assertStatus(401);
});
it('rate limits incoming per source', function () {
    // Arrange
    Queue::fake();
    $source = Source::create([
        'name' => 'Stripe Payment Test',
        'signing_secret' => 'signing_secret',
        'is_active' => true,
    ]);
    $destination = Destination::create([
        'source_id' => $source->id,
        'url' => 'https://webhook.site/test-endpoint',
        'is_active' => true,
        'rate_limit_per_minute' => 60,
        'retry_count' => 3,
    ]);
    $payload = ['event' => 'user.created'];
    $timestamp = time();
    $rawPayload = json_encode($payload);
    $signature = hash_hmac('sha256', $timestamp.'.'.$rawPayload, $source->signing_secret);
    for ($i = 0; $i < 60; $i++) {
        $response = $this->postJson(
            route('webhooks.ingest', $source),
            $payload,
            [
                'X-Webhook-Signature' => $signature,
                'X-Webhook-Timestamp' => $timestamp,
            ]
        );
        $response->assertStatus(202);
    }
    // 3. Act & Assert: The 61st request should fail with 429 Too Many Requests
    $response = $this->postJson(
        route('webhooks.ingest', $source),
        $payload,
        [
            'X-Webhook-Signature' => $signature,
            'X-Webhook-Timestamp' => $timestamp,
        ]
    );
    $response->assertStatus(429);
});
it('fails the job if the destination URL is not HTTPS', function () {
    // 1. Arrange
    $source = Source::create([
        'name' => 'Stripe Payments Test',
        'signing_secret' => 'whsec_testsecret123',
        'is_active' => true,
    ]);
    // Use insecure HTTP url
    $destination = Destination::create([
        'source_id' => $source->id,
        'url' => 'http://insecure-webhook.site',
        'is_active' => true,
        'rate_limit_per_minute' => 60,
        'retry_count' => 3,
    ]);
    $webhook = Webhook::create([
        'source_id' => $source->id,
        'payload' => ['event' => 'user.created'],
        'headers' => [],
        'event_type' => 'user.created',
        'status' => 'pending',
    ]);
    // 2. Act
    $job = new DeliveryWebhookJob($webhook, $destination);
    // We expect the job to throw an Exception because of the HTTP URL
    expect(fn () => $job->handle())->toThrow(Exception::class, 'Insecure destination URL: must use HTTPS.');
});

it('verifies that Webhook model destinations relationship is resolved correctly using HasManyThrough', function () {
    $source = Source::create([
        'name' => 'Stripe Payments Test',
        'signing_secret' => 'whsec_testsecret123',
        'is_active' => true,
    ]);

    $destination1 = Destination::create([
        'source_id' => $source->id,
        'url' => 'https://webhook.site/endpoint-1',
        'is_active' => true,
        'rate_limit_per_minute' => 60,
        'retry_count' => 3,
    ]);

    $destination2 = Destination::create([
        'source_id' => $source->id,
        'url' => 'https://webhook.site/endpoint-2',
        'is_active' => true,
        'rate_limit_per_minute' => 60,
        'retry_count' => 3,
    ]);

    $webhook = Webhook::create([
        'source_id' => $source->id,
        'payload' => ['event' => 'user.created'],
        'headers' => [],
        'event_type' => 'user.created',
        'status' => 'pending',
    ]);

    expect($webhook->destinations)->toHaveCount(2);
    expect($webhook->destinations->pluck('id'))->toContain($destination1->id, $destination2->id);
});

it('rate limits DeliveryWebhookJob based on destination limits', function () {
    Http::fake([
        '*' => Http::response('OK', 200),
    ]);

    $source = Source::create([
        'name' => 'Stripe Payments Test',
        'signing_secret' => 'whsec_testsecret123',
        'is_active' => true,
    ]);

    // Destination with rate limit = 1 per minute
    $destination = Destination::create([
        'source_id' => $source->id,
        'url' => 'https://webhook.site/rate-limited-endpoint',
        'is_active' => true,
        'rate_limit_per_minute' => 1,
        'retry_count' => 3,
    ]);

    $webhook1 = Webhook::create([
        'source_id' => $source->id,
        'payload' => ['event' => 'user.created'],
        'headers' => [],
        'event_type' => 'user.created',
        'status' => 'pending',
    ]);

    $webhook2 = Webhook::create([
        'source_id' => $source->id,
        'payload' => ['event' => 'user.updated'],
        'headers' => [],
        'event_type' => 'user.updated',
        'status' => 'pending',
    ]);

    // 1st request should pass through rate limiter
    $job1 = new DeliveryWebhookJob($webhook1, $destination);
    $job1->handle();
    $this->assertDatabaseHas('deliveries', [
        'webhook_id' => $webhook1->id,
        'status' => 'success',
    ]);

    // 2nd request within same minute should be rate limited
    $job2 = new DeliveryWebhookJob($webhook2, $destination);
    $job2->handle();

    // The second delivery log should NOT exist because the job was released and returned early
    $this->assertDatabaseMissing('deliveries', [
        'webhook_id' => $webhook2->id,
    ]);
});

it('dispatches CircuitBreakerTripped event and logs an error when the circuit breaker trips', function () {
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
        'url' => 'https://webhook.site/fail-endpoint',
        'is_active' => true,
        'rate_limit_per_minute' => 60,
        'retry_count' => 3,
    ]);

    $destination->circuit_breaker_failures = 4;
    $destination->save();

    $loggedMessage = null;
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($event) use (&$loggedMessage) {
        if ($event->level === 'error') {
            $loggedMessage = $event->message;
        }
    });

    $webhook = Webhook::create([
        'source_id' => $source->id,
        'payload' => ['event' => 'user.created'],
        'headers' => [],
        'event_type' => 'user.created',
        'status' => 'pending',
    ]);

    $job = new DeliveryWebhookJob($webhook, $destination);
    $job->handle();

    expect($loggedMessage)->not->toBeNull();
    expect($loggedMessage)->toContain('CRITICAL: Circuit breaker tripped');
    expect($loggedMessage)->toContain($destination->url);
});

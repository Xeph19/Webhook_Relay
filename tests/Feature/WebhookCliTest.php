<?php

use App\Jobs\DeliveryWebhookJob;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\Source;
use App\Models\Webhook;
use Illuminate\Support\Facades\Queue;

it('displays the status of webhooks and destinations', function () {
    $source = Source::create([
        'name' => 'Stripe Payments Test',
        'signing_secret' => 'whsec_testsecret123',
        'is_active' => true,
    ]);

    $destination = Destination::create([
        'source_id' => $source->id,
        'url' => 'https://webhook.site/status-test',
        'is_active' => true,
        'rate_limit_per_minute' => 60,
        'retry_count' => 3,
    ]);

    Webhook::create([
        'source_id' => $source->id,
        'payload' => ['event' => 'user.created'],
        'headers' => [],
        'event_type' => 'user.created',
        'status' => 'pending',
    ]);

    Webhook::create([
        'source_id' => $source->id,
        'payload' => ['event' => 'user.created'],
        'headers' => [],
        'event_type' => 'user.created',
        'status' => 'completed',
    ]);

    $exitCode = \Illuminate\Support\Facades\Artisan::call('webhook:status');
    $output = \Illuminate\Support\Facades\Artisan::output();
    expect($exitCode)->toBe(0);
    expect($output)->toContain('1 Pending');
    expect($output)->toContain('1 Completed');
    expect($output)->toContain('https://webhook.site/status-test');
});

it('resets a tripped circuit breaker for a destination', function () {
    $source = Source::create([
        'name' => 'Stripe Payments Test',
        'signing_secret' => 'whsec_testsecret123',
        'is_active' => true,
    ]);

    $destination = Destination::create([
        'source_id' => $source->id,
        'url' => 'https://webhook.site/reset-test',
        'is_active' => true,
        'rate_limit_per_minute' => 60,
        'retry_count' => 3,
    ]);

    $destination->circuit_breaker_failures = 5;
    $destination->circuit_breaker_opened_at = now();
    $destination->save();

    $this->artisan('webhook:reset-circuit', ['destination_id' => $destination->id])
        ->assertExitCode(0)
        ->expectsOutputToContain('Successfully reset circuit breaker');

    $destination->refresh();
    expect($destination->circuit_breaker_failures)->toBe(0);
    expect($destination->circuit_breaker_opened_at)->toBeNull();
});

it('manually retries a failed delivery and queues the job', function () {
    Queue::fake();

    $source = Source::create([
        'name' => 'Stripe Payments Test',
        'signing_secret' => 'whsec_testsecret123',
        'is_active' => true,
    ]);

    $destination = Destination::create([
        'source_id' => $source->id,
        'url' => 'https://webhook.site/retry-test',
        'is_active' => true,
        'rate_limit_per_minute' => 60,
        'retry_count' => 3,
    ]);

    $webhook = Webhook::create([
        'source_id' => $source->id,
        'payload' => ['event' => 'user.created'],
        'headers' => [],
        'event_type' => 'user.created',
        'status' => 'failed',
    ]);

    $delivery = Delivery::create([
        'webhook_id' => $webhook->id,
        'destination_id' => $destination->id,
        'attempt_number' => 1,
        'request_headers' => [],
        'request_payload' => [],
        'status' => 'failed',
    ]);

    $this->artisan('webhook:retry', ['delivery_id' => $delivery->id])
        ->assertExitCode(0)
        ->expectsOutputToContain('Successfully re-queued webhook');

    Queue::assertPushed(DeliveryWebhookJob::class, function ($job) use ($webhook, $destination) {
        return $job->webhook->id === $webhook->id && $job->destination->id === $destination->id;
    });
});


<?php

use App\Jobs\SourceJob;
use App\Models\Destination;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

it('denies unauthenticated access to sources', function () {
    $response = $this->getJson(route('sources.index'));
    $response->assertStatus(401);
});

it('shows list of sources', function () {
    // Arrange
    Sanctum::actingAs(User::factory()->create());
    $sources = Source::factory()->count(3)->create();

    // Act
    $response = $this->getJson(route('sources.index'));

    // Assert
    $response->assertStatus(200)
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => ['name', 'signing_secret', 'is_active'],
            ],
        ]);
});

it('creates a source and dispatches SourceJob', function () {
    // Arrange
    Sanctum::actingAs(User::factory()->create());
    Queue::fake();
    $data = [
        'name' => 'Stripe Ingestion',
        'signing_secret' => 'supersecret123',
        'is_active' => true,
    ];

    // Act
    $response = $this->postJson(route('sources.store'), $data);

    // Assert
    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Stripe Ingestion');

    $this->assertDatabaseHas('sources', [
        'name' => 'Stripe Ingestion',
        'is_active' => true,
    ]);

    Queue::assertPushed(SourceJob::class);
});

it('shows a specific source', function () {
    // Arrange
    Sanctum::actingAs(User::factory()->create());
    $source = Source::factory()->create();

    // Act
    $response = $this->getJson(route('sources.show', $source));

    // Assert
    $response->assertStatus(200)
        ->assertJsonPath('name', $source->name);
});

it('updates a source and dispatches SourceJob', function () {
    // Arrange
    Sanctum::actingAs(User::factory()->create());
    Queue::fake();
    $source = Source::factory()->create(['name' => 'Old Name']);
    $data = [
        'name' => 'New Name',
        'signing_secret' => 'newsecret123',
        'is_active' => false,
    ];

    // Act
    $response = $this->putJson(route('sources.update', $source), $data);

    // Assert
    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'New Name');

    $this->assertDatabaseHas('sources', [
        'id' => $source->id,
        'name' => 'New Name',
        'is_active' => false,
    ]);

    Queue::assertPushed(SourceJob::class);
});

it('deletes a source', function () {
    // Arrange
    Sanctum::actingAs(User::factory()->create());
    $source = Source::factory()->create();

    // Act
    $response = $this->deleteJson(route('sources.destroy', $source));

    // Assert
    $response->assertStatus(200)
        ->assertJson(['message' => 'Source deleted successfully']);

    $this->assertDatabaseMissing('sources', [
        'id' => $source->id,
    ]);
});

it('activates and deactivates destinations when SourceJob runs', function () {
    // Arrange
    $source = Source::factory()->create(['is_active' => false]);
    $destination = Destination::create([
        'source_id' => $source->id,
        'url' => 'https://example.com/webhook',
        'is_active' => true,
        'rate_limit_per_minute' => 60,
        'retry_count' => 3,
    ]);

    // Act - Deactivation path
    $job = new SourceJob($source);
    $job->handle();

    // Assert deactivation
    $destination->refresh();
    expect($destination->is_active)->toBeFalse();

    // Act - Activation path
    $source->update(['is_active' => true]);
    $job = new SourceJob($source);
    $job->handle();

    // Assert activation
    $destination->refresh();
    expect($destination->is_active)->toBeTrue();
});

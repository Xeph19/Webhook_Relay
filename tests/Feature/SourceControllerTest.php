<?php
 
use App\Models\Source;
use App\Jobs\SourceJob;
use Illuminate\Support\Facades\Queue;
 
it('shows list of sources', function () {
    // Arrange
    $sources = Source::factory()->count(3)->create();
 
    // Act
    $response = $this->getJson(route('sources.index'));
 
    // Assert
    $response->assertStatus(200)
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => ['name', 'signing_secret', 'is_active']
            ]
        ]);
});
 
it('creates a source and dispatches SourceJob', function () {
    // Arrange
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
    $source = Source::factory()->create();
 
    // Act
    $response = $this->getJson(route('sources.show', $source));
 
    // Assert
    $response->assertStatus(200)
        ->assertJsonPath('name', $source->name);
});
 
it('updates a source and dispatches SourceJob', function () {
    // Arrange
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


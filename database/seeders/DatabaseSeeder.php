<?php

namespace Database\Seeders;

use App\Models\Source;
use App\Models\Destination;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create a test source
        $source = Source::create([
            'id' => (string) Str::uuid(),
            'name' => 'Stripe Payments Integration',
            'signing_secret' => 'whsec_' . Str::random(32),
            'is_active' => true,
        ]);

        // Create a test destination targeting a mock receiver URL
        Destination::create([
            'id' => (string) Str::uuid(),
            'source_id' => $source->id,
            'url' => 'https://webhook.site/placeholder-change-this', // We will update this during testing
            'is_active' => true,
            'rate_limit_per_minute' => 60,
            'retry_count' => 3,
        ]);

        $this->command->info("Test Source UUID: {$source->id}");
        $this->command->info("Signing Secret: {$source->signing_secret}");
    }
}

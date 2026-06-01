<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('webhook_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('destination_id')->constrained()->cascadeOnDelete();
            $table->integer('attempt_number');
            $table->json('request_headers');
            $table->json('request_payload');
            $table->json('response_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->integer('response_status')->nullable(); // HTTP status code
            $table->integer('duration_ms')->default(0);
            $table->string('status'); // success, failed
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};

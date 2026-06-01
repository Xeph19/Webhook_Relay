<?php

use App\Http\Controllers\WebhookIngestionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('/v1')->group(function(){
    Route::post('/ingestion/{source}', WebhookIngestionController::class)->name('webhooks.ingest');
});


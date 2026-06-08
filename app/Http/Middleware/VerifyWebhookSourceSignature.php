<?php

namespace App\Http\Middleware;

use App\Models\Source;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSourceSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $source = $request->route('source');
        if (! $source || ! $source instanceof Source) {
            abort(500, 'Invalid source configuration');
        }

        $signature = $request->header('X-Ingestion-Signature');
        if (empty($signature)) {
            return response()->json(['message' => 'Signature header missing'], 401);
        }

        $rawPayload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $rawPayload, $source->signing_secret);

        if (! hash_equals($expectedSignature, $signature)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        return $next($request);
    }
}

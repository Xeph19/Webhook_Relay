<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Retrieve the source from the request route.
        $source = $request->route('source');
        // 2. Validate that the source exists and is active.1
        if (! $source || ! $source->is_active) {
            return response()->json(['message' => 'Webhook source invalid or inactive'], 401);
        }
        // 3. Extract the signature and timestamp from the request headers.
        $signature = $request->header('X-Webhook-Signature');
        $timestamp = $request->header('X-Webhook-Timestamp');
        // 4. Ensure both the signature and timestamp headers are present.
        if (! $signature || ! $timestamp) {
            return response()->json(['message' => 'Missing Security Headers'], 401);
        }
        // 5. Verify the timestamp is within the allowed window to prevent replay attacks.
        if (abs(time() - (int) $timestamp) > 300) {
            return response()->json(['message' => 'Request expired'], 401);
        }

        // 6. Generate the local signature using the source's secret key and the request details.
        $rawBody = $request->getContent();
        $signingPayload = $timestamp.'.'.$rawBody;
        $localSignature = hash_hmac('sha256', $signingPayload, $source->signing_secret);
        // 7. Securely compare the generated signature with the header signature.
        if (! hash_equals($localSignature, $signature)) {
            return response()->json(['message' => 'Signature do not match'], 401);
        }

        // 8. If valid, proceed; otherwise, return an unauthorized response.
        return $next($request);
    }
}

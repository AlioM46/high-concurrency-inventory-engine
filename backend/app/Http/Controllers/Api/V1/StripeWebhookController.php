<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Payment\StripeWebhookSignatureVerifier;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StripeWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected StripeWebhookSignatureVerifier $signatureVerifier
    ) {}

    /**
     * Handle incoming Stripe webhook requests with cryptographic signature verification.
     */
    public function handle(Request $request): JsonResponse
    {
        // get the RawBody (not converting the incoming request to array or change its shape, because this affects the hashing result)
        $payload = $request->getContent();
        $signatureHeader = $request->header('Stripe-Signature');

        // Verify HMAC-SHA256 signature and replay tolerance
        $verifiedData = $this->signatureVerifier->verify($payload, $signatureHeader);

        $event = $verifiedData['payload'];
        $eventType = $event['type'] ?? 'unknown';
        $eventId = $event['id'] ?? 'unknown';

        Log::info("Stripe Webhook received and signature verified: [{$eventType}] (ID: {$eventId})");

        return $this->successResponse(
            data: [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'verified' => true,
            ],
            message: 'Webhook received and verified successfully.',
            statusCode: 200
        );
    }
}

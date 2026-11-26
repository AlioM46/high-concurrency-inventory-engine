<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Payment\StripeWebhookProcessor;
use App\Services\Payment\StripeWebhookSignatureVerifier;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected StripeWebhookSignatureVerifier $signatureVerifier,
        protected StripeWebhookProcessor $webhookProcessor
    ) {}

    /**
     * Handle incoming Stripe webhook requests with cryptographic signature verification and deduplication.
     */

    public function handle(Request $request): JsonResponse
    {
        // 1. Get raw payload (unparsed body string required for exact HMAC calculation)
        $payload = $request->getContent();
        $signatureHeader = $request->header('Stripe-Signature');

        // 2. Cryptographic signature and replay attack verification
        $verifiedData = $this->signatureVerifier->verify($payload, $signatureHeader);

        // 3. Deduplicate and ingest event via payment_webhook_events
        $result = $this->webhookProcessor->process($verifiedData['payload']);

        $message = $result['is_duplicate']
            ? 'Webhook event already processed (idempotent duplicate).'
            : 'Webhook received and processed successfully.';

        return $this->successResponse(
            data: $result,
            message: $message,
            statusCode: 200
        );
    }
}

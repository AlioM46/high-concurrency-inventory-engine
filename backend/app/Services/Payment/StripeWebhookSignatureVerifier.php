<?php

namespace App\Services\Payment;

use App\Exceptions\PaymentException;
use Illuminate\Support\Facades\Log;

class StripeWebhookSignatureVerifier
{
    /**
     * Default tolerance window in seconds (5 minutes) to protect against replay attacks.
     */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Verify the Stripe webhook signature header against the raw payload.
     *
     * @param string $payload The raw, unparsed request body
     * @param string|null $signatureHeader The 'Stripe-Signature' header string
     * @param string|null $secret The webhook signing secret (whsec_...)
     * @param int $tolerance Allowed time drift in seconds
     * @return array{timestamp: int, signature: string, payload: array}
     * @throws PaymentException
     */
    public function verify(
        string $payload,
        ?string $signatureHeader,
        ?string $secret = null,
        int $tolerance = self::DEFAULT_TOLERANCE_SECONDS
    ): array {
        $secret = $secret ?: (string) config('services.stripe.webhook_secret');

        if (empty($signatureHeader)) {
            Log::warning('Stripe webhook rejected: Missing Stripe-Signature header.');
            throw new PaymentException(
                message: 'Missing Stripe-Signature header.',
                errorCode: 'MISSING_WEBHOOK_SIGNATURE',
                statusCode: 400
            );
        }

        if (empty($secret)) {
            Log::error('Stripe webhook signing secret is not configured.');
            throw new PaymentException(
                message: 'Stripe webhook secret is not configured on the server.',
                errorCode: 'MISCONFIGURED_WEBHOOK_SECRET',
                statusCode: 500
            );
        }

        // 1. Parse signature header (format: t=1700000000,v1=5257a8...,v0=...)
        $parsedHeader = $this->parseSignatureHeader($signatureHeader);
        $timestamp = $parsedHeader['timestamp'];
        $signatures = $parsedHeader['signatures'];

        if ($timestamp === null || empty($signatures)) {
            Log::warning('Stripe webhook rejected: Malformed Stripe-Signature header.', ['header' => $signatureHeader]);
            throw new PaymentException(
                message: 'Malformed Stripe-Signature header format.',
                errorCode: 'MALFORMED_WEBHOOK_SIGNATURE',
                statusCode: 400
            );
        }

        // 2. Replay attack protection: ensure timestamp is within tolerance window
        $now = time();
        if (abs($now - $timestamp) > $tolerance) {
            Log::warning('Stripe webhook rejected: Timestamp outside tolerance window.', [
                'header_time' => $timestamp,
                'server_time' => $now,
                'diff' => abs($now - $timestamp),
                'tolerance' => $tolerance,
            ]);

            throw new PaymentException(
                message: "Webhook timestamp [{$timestamp}] is outside the allowed {$tolerance}s tolerance window.",
                errorCode: 'WEBHOOK_TIMESTAMP_EXPIRED',
                statusCode: 400
            );
        }

        // 3. Compute expected cryptographic HMAC-SHA256 signature
        $signedPayload = "{$timestamp}.{$payload}";
        $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

        // 4. Compare using timing-safe string comparison to prevent timing attacks
        $matched = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expectedSignature, $signature)) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            Log::warning('Stripe webhook rejected: Signature mismatch.', [
                'expected' => $expectedSignature,
                'provided' => $signatures,
            ]);

            throw new PaymentException(
                message: 'Invalid Stripe webhook signature.',
                errorCode: 'INVALID_WEBHOOK_SIGNATURE',
                statusCode: 400
            );
        }

        // 5. Decode JSON payload
        $decoded = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            Log::warning('Stripe webhook rejected: Invalid JSON body despite valid signature.');
            throw new PaymentException(
                message: 'Invalid JSON payload in webhook body.',
                errorCode: 'INVALID_WEBHOOK_JSON',
                statusCode: 400
            );
        }

        return [
            'timestamp' => $timestamp,
            'signature' => $expectedSignature,
            'payload' => $decoded,
        ];
    }

    /**
     * Parse the Stripe-Signature header into timestamp and signature elements.
     *
     * @return array{timestamp: ?int, signatures: list<string>}
     */
    protected function parseSignatureHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        $items = explode(',', $header);
        foreach ($items as $item) {
            $parts = explode('=', trim($item), 2);
            if (count($parts) !== 2) {
                continue;
            }

            $prefix = trim($parts[0]);
            $value = trim($parts[1]);

            if ($prefix === 't') {
                $timestamp = is_numeric($value) ? (int) $value : null;
            } elseif ($prefix === 'v1') {
                $signatures[] = $value;
            }
        }

        return [
            'timestamp' => $timestamp,
            'signatures' => $signatures,
        ];
    }

    /**
     * Helper to generate a valid signature header string (useful for testing and simulators).
     */
    public function generateSignatureHeader(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?: time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return "t={$timestamp},v1={$signature}";
    }
}

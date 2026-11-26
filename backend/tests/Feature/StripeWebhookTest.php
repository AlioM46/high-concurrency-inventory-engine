<?php

namespace Tests\Feature;

use App\Services\Payment\StripeWebhookSignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;
    private string $webhookSecret = 'whsec_test_secret_for_automated_testing_12345';
    private StripeWebhookSignatureVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.webhook_secret' => $this->webhookSecret,
        ]);

        $this->verifier = app(StripeWebhookSignatureVerifier::class);
    }

    public function test_accepts_webhook_with_valid_signature_and_returns_200(): void
    {
        $payloadArray = [
            'id' => 'evt_test_payment_succeeded_001',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test_123456',
                    'amount' => 79900,
                    'currency' => 'usd',
                    'status' => 'succeeded',
                ],
            ],
        ];

        $rawBody = json_encode($payloadArray);
        $signatureHeader = $this->verifier->generateSignatureHeader($rawBody, $this->webhookSecret, time());

        $response = $this->call(
            method: 'POST',
            uri: '/api/v1/webhooks/stripe',
            parameters: [],
            cookies: [],
            files: [],
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signatureHeader,
            ],
            content: $rawBody
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_duplicate', false)
            ->assertJsonPath('data.event_type', 'payment_intent.succeeded')
            ->assertJsonPath('data.event_id', 'evt_test_payment_succeeded_001');
    }

    public function test_rejects_webhook_when_signature_header_is_missing(): void
    {
        $rawBody = json_encode(['id' => 'evt_fake', 'type' => 'payment_intent.succeeded']);

        $response = $this->call(
            method: 'POST',
            uri: '/api/v1/webhooks/stripe',
            server: [
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $rawBody
        );

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'MISSING_WEBHOOK_SIGNATURE');
    }

    public function test_rejects_webhook_with_tampered_payload_or_invalid_signature(): void
    {
        $originalPayload = json_encode(['amount' => 1000]);
        $tamperedPayload = json_encode(['amount' => 999999]); // Attacker modified the body!

        // Generate signature for original, but send tampered body
        $signatureHeader = $this->verifier->generateSignatureHeader($originalPayload, $this->webhookSecret, time());

        $response = $this->call(
            method: 'POST',
            uri: '/api/v1/webhooks/stripe',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signatureHeader,
            ],
            content: $tamperedPayload
        );

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'INVALID_WEBHOOK_SIGNATURE');
    }

    public function test_rejects_webhook_when_timestamp_exceeds_tolerance_window(): void
    {
        $rawBody = json_encode(['id' => 'evt_expired_replay', 'type' => 'payment_intent.succeeded']);

        // Timestamp 10 minutes in the past (600 seconds ago, tolerance is 300)
        $expiredTimestamp = time() - 600;
        $signatureHeader = $this->verifier->generateSignatureHeader($rawBody, $this->webhookSecret, $expiredTimestamp);

        $response = $this->call(
            method: 'POST',
            uri: '/api/v1/webhooks/stripe',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signatureHeader,
            ],
            content: $rawBody
        );

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'WEBHOOK_TIMESTAMP_EXPIRED');
    }

    public function test_rejects_webhook_with_malformed_signature_header(): void
    {
        $rawBody = json_encode(['id' => 'evt_malformed']);

        $response = $this->call(
            method: 'POST',
            uri: '/api/v1/webhooks/stripe',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => 'invalid_header_without_equals_sign',
            ],
            content: $rawBody
        );

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'MALFORMED_WEBHOOK_SIGNATURE');
    }
}

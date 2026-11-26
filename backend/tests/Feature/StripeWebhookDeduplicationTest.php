<?php

namespace Tests\Feature;

use App\Models\PaymentWebhookEvent;
use App\Services\Payment\StripeWebhookSignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeWebhookDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookSecret = 'whsec_test_deduplication_secret_123';
    private StripeWebhookSignatureVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.webhook_secret' => $this->webhookSecret,
        ]);

        $this->verifier = app(StripeWebhookSignatureVerifier::class);
    }

    public function test_creates_event_record_in_database_upon_first_webhook_arrival(): void
    {
        $eventId = 'evt_unique_first_arrival_001';
        $payloadArray = [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test_abc123',
                    'amount' => 50000,
                    'currency' => 'usd',
                ],
            ],
        ];

        $rawBody = json_encode($payloadArray);
        $signatureHeader = $this->verifier->generateSignatureHeader($rawBody, $this->webhookSecret, time());

        $response = $this->call(
            method: 'POST',
            uri: '/api/v1/webhooks/stripe',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signatureHeader,
            ],
            content: $rawBody
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_duplicate', false)
            ->assertJsonPath('data.status', 'processed')
            ->assertJsonPath('data.event_id', $eventId);

        // Verify row exists in payment_webhook_events
        $this->assertDatabaseHas('payment_webhook_events', [
            'provider' => 'STRIPE',
            'event_id' => $eventId,
            'event_type' => 'payment_intent.succeeded',
            'processing_status' => PaymentWebhookEvent::STATUS_PROCESSED,
        ]);
    }

    public function test_deduplicates_second_delivery_of_identical_event_id(): void
    {
        $eventId = 'evt_duplicate_test_002';
        $payloadArray = [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test_dup999',
                    'amount' => 79900,
                ],
            ],
        ];

        $rawBody = json_encode($payloadArray);
        $signatureHeader = $this->verifier->generateSignatureHeader($rawBody, $this->webhookSecret, time());

        // First delivery: should process normally
        $firstResponse = $this->call(
            method: 'POST',
            uri: '/api/v1/webhooks/stripe',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signatureHeader,
            ],
            content: $rawBody
        );

        $firstResponse->assertStatus(200)
            ->assertJsonPath('data.is_duplicate', false)
            ->assertJsonPath('data.status', 'processed');

        // Second delivery (e.g. Stripe network retry with identical payload):
        $secondResponse = $this->call(
            method: 'POST',
            uri: '/api/v1/webhooks/stripe',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signatureHeader,
            ],
            content: $rawBody
        );

        $secondResponse->assertStatus(200)
            ->assertJsonPath('data.is_duplicate', true)
            ->assertJsonPath('data.status', 'duplicate_ignored')
            ->assertJsonPath('data.event_id', $eventId);

        // Ensure database table only has 1 record for this event_id
        $this->assertEquals(1, PaymentWebhookEvent::where('event_id', $eventId)->count());
    }

    public function test_marks_unsupported_event_types_as_ignored(): void
    {
        $eventId = 'evt_unsupported_type_003';
        $payloadArray = [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'customer.subscription.created', // unsupported event
            'data' => [
                'object' => [
                    'id' => 'sub_12345',
                ],
            ],
        ];

        $rawBody = json_encode($payloadArray);
        $signatureHeader = $this->verifier->generateSignatureHeader($rawBody, $this->webhookSecret, time());

        $response = $this->call(
            method: 'POST',
            uri: '/api/v1/webhooks/stripe',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signatureHeader,
            ],
            content: $rawBody
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.is_duplicate', false)
            ->assertJsonPath('data.status', 'ignored');

        $this->assertDatabaseHas('payment_webhook_events', [
            'event_id' => $eventId,
            'processing_status' => PaymentWebhookEvent::STATUS_IGNORED,
        ]);
    }
}

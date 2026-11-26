<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Reservation;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\OrderService;
use App\Services\Payment\StripeWebhookSignatureVerifier;
use App\Services\PessimisticLockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeWebhookPaymentSuccessTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookSecret = 'whsec_test_secret_for_payment_success_testing';
    private StripeWebhookSignatureVerifier $verifier;
    private Organization $org;
    private Warehouse $warehouse;
    private Product $product;
    private ProductWarehouse $pw;
    private Reservation $reservation;
    private Order $order;
    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.webhook_secret' => $this->webhookSecret,
        ]);

        $this->verifier = app(StripeWebhookSignatureVerifier::class);

        // 1. Create Organization & Warehouse
        $this->org = Organization::create([
            'name' => 'Apex Retail Global',
            'slug' => 'apex-retail',
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Riyadh Central Hub',
            'code' => 'RUH-01',
            'is_active' => true,
        ]);

        // 2. Create Product with initial stock of 10
        $this->product = Product::create([
            'organization_id' => $this->org->id,
            'sku' => 'PS5-PRO-TEST',
            'name' => 'PlayStation 5 Pro',
            'price_minor_units' => 79900,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->pw = ProductWarehouse::create([
            'organization_id' => $this->org->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'available_quantity' => 10,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);

        // 3. Create active reservation for 2 units (available: 8, reserved: 2, sold: 0)
        $resService = app(PessimisticLockReservationService::class);
        $this->reservation = $resService->createReservation([
            'organization_id' => $this->org->id,
            'customer_email' => 'ahmed@example.com',
            'items' => [
                [
                    'product_warehouse_id' => $this->pw->id,
                    'quantity' => 2,
                ],
            ],
            'ttl_minutes' => 10,
        ]);

        // 4. Create Order from Reservation
        $orderService = app(OrderService::class);
        $this->order = $orderService->createFromReservation([
            'reservation_id' => $this->reservation->id,
            'customer_email' => 'ahmed@example.com',
        ]);

        // 5. Create Payment record (PENDING)
        $this->payment = Payment::create([
            'organization_id' => $this->org->id,
            'order_id' => $this->order->id,
            'provider' => 'STRIPE',
            'provider_payment_intent_id' => 'pi_test_success_999',
            'amount_minor_units' => 159800, // 2 x 799.00
            'currency' => 'USD',
            'status' => Payment::STATUS_PENDING,
        ]);
    }

    public function test_payment_succeeded_transitions_order_to_paid_and_commits_stock_sale(): void
    {
        $payloadArray = [
            'id' => 'evt_test_payment_intent_succeeded_001',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test_success_999',
                    'amount' => 159800,
                    'currency' => 'usd',
                    'status' => 'succeeded',
                    'metadata' => [
                        'order_id' => $this->order->id,
                        'order_number' => $this->order->order_number,
                    ],
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
            ->assertJsonPath('data.status', 'paid_and_confirmed')
            ->assertJsonPath('data.requires_refund', false);

        // 1. Verify Order transitioned to PAID with timestamp
        $this->order->refresh();
        $this->assertEquals(Order::STATUS_PAID, $this->order->status);
        $this->assertNotNull($this->order->paid_at);

        // 2. Verify Reservation transitioned to CONFIRMED with timestamp
        $this->reservation->refresh();
        $this->assertEquals(Reservation::STATUS_CONFIRMED, $this->reservation->status);
        $this->assertNotNull($this->reservation->confirmed_at);

        // 3. Verify Payment transitioned to SUCCEEDED
        $this->payment->refresh();
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $this->payment->status);

        // 4. Verify Inventory was atomically shifted: reserved -> sold
        $this->pw->refresh();
        $this->assertEquals(8, $this->pw->available_quantity);
        $this->assertEquals(0, $this->pw->reserved_quantity);
        $this->assertEquals(2, $this->pw->sold_quantity);

        // 5. Verify Immutable double-entry StockMovement ledger entry
        $this->assertDatabaseHas('stock_movements', [
            'product_warehouse_id' => $this->pw->id,
            'movement_type' => StockMovement::TYPE_SALE,
            'reference_type' => 'Order',
            'reference_id' => $this->order->id,
            'available_delta' => 0,
            'reserved_delta' => -2,
            'sold_delta' => 2,
        ]);
    }

    public function test_late_payment_on_expired_reservation_triggers_race_guard_without_overselling(): void
    {
        // SIMULATE THE LATE PAYMENT RACE CONDITION:
        // 1. The 10-minute TTL expires, and the worker cancels the reservation
        $this->reservation->update([
            'status' => Reservation::STATUS_EXPIRED,
            'released_at' => now(),
        ]);

        // Held units return to available stock (available: 10, reserved: 0, sold: 0)
        $this->pw->update([
            'available_quantity' => 10,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);

        // 2. Another customer immediately buys all 10 available units!
        $this->pw->update([
            'available_quantity' => 0,
            'reserved_quantity' => 0,
            'sold_quantity' => 10,
        ]);

        // 3. Now the late webhook arrives from Stripe for the expired order!
        $payloadArray = [
            'id' => 'evt_late_payment_succeeded_002',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test_success_999',
                    'amount' => 159800,
                    'metadata' => [
                        'order_id' => $this->order->id,
                        'order_number' => $this->order->order_number,
                    ],
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

        // 4. Verify race guard response: marked for immediate refund!
        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'expired_late_payment')
            ->assertJsonPath('data.requires_refund', true);

        // 5. Order is marked EXPIRED, NOT PAID!
        $this->order->refresh();
        $this->assertEquals(Order::STATUS_EXPIRED, $this->order->status);

        // 6. IRONCLAD INVARIANT CHECK: INVENTORY IS COMPLETELY UNTOUCHED!
        $this->pw->refresh();
        $this->assertEquals(0, $this->pw->available_quantity);
        $this->assertEquals(0, $this->pw->reserved_quantity);
        $this->assertEquals(10, $this->pw->sold_quantity); // Zero overselling!

        // 7. Ensure NO sale movement was appended for the expired order
        $this->assertDatabaseMissing('stock_movements', [
            'movement_type' => StockMovement::TYPE_SALE,
            'reference_id' => $this->order->id,
        ]);
    }

    public function test_payment_failed_event_updates_payment_and_order_status(): void
    {
        $payloadArray = [
            'id' => 'evt_payment_failed_003',
            'object' => 'event',
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'pi_test_success_999',
                    'amount' => 159800,
                    'last_payment_error' => [
                        'message' => 'Your card has insufficient funds.',
                    ],
                    'metadata' => [
                        'order_id' => $this->order->id,
                    ],
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
            ->assertJsonPath('data.status', 'payment_failed');

        $this->order->refresh();
        $this->assertEquals(Order::STATUS_PAYMENT_FAILED, $this->order->status);

        $this->payment->refresh();
        $this->assertEquals(Payment::STATUS_FAILED, $this->payment->status);
        $this->assertEquals('Your card has insufficient funds.', $this->payment->failure_reason);
    }
}

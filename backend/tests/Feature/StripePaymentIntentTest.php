<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Reservation;
use App\Models\Warehouse;
use App\Services\OrderService;
use App\Services\PessimisticLockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StripePaymentIntentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Warehouse $warehouse;
    private Product $product;
    private ProductWarehouse $pw;
    private Order $order;
    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret' => 'sk_test_mock_secret_key_123',
            'services.stripe.key' => 'pk_test_mock_public_key_123',
        ]);

        $this->org = Organization::create([
            'name' => 'Apex Retail Global',
            'slug' => 'apex-retail',
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Main Fulfillment Center',
            'code' => 'MFC-01',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'organization_id' => $this->org->id,
            'sku' => 'CONSOLE-PS5-PRO',
            'name' => 'PlayStation 5 Pro',
            'price_minor_units' => 79900, // $799.00
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->pw = ProductWarehouse::create([
            'organization_id' => $this->org->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'available_quantity' => 20,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);

        // Create an active reservation
        $resService = app(PessimisticLockReservationService::class);
        $this->reservation = $resService->createReservation([
            'organization_id' => $this->org->id,
            'customer_email' => 'customer@example.com',
            'items' => [
                [
                    'product_warehouse_id' => $this->pw->id,
                    'quantity' => 1,
                ],
            ],
            'ttl_minutes' => 10,
        ]);

        // Create an order from that active reservation
        $orderService = app(OrderService::class);
        $this->order = $orderService->createFromReservation([
            'reservation_id' => $this->reservation->id,
            'customer_email' => 'customer@example.com',
        ]);
    }

    public function test_creates_stripe_payment_intent_successfully_for_pending_order(): void
    {
        $mockIntentId = 'pi_3MtwBwLkdIwHu7ix28a3tqPa';
        $mockClientSecret = 'pi_3MtwBwLkdIwHu7ix28a3tqPa_secret_YrKJ2R53E9j0BvGf7';

        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => Http::response([
                'id' => $mockIntentId,
                'object' => 'payment_intent',
                'amount' => 79900,
                'currency' => 'usd',
                'status' => 'requires_payment_method',
                'client_secret' => $mockClientSecret,
                'metadata' => [
                    'order_id' => $this->order->id,
                    'order_number' => $this->order->order_number,
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/orders/{$this->order->id}/payments/stripe-intent", [], [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment_intent_id', $mockIntentId)
            ->assertJsonPath('data.client_secret', $mockClientSecret)
            ->assertJsonPath('data.amount_minor_units', 79900)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.status', 'PENDING');

        // Verify Payment row in database
        $this->assertDatabaseHas('payments', [
            'order_id' => $this->order->id,
            'provider' => 'STRIPE',
            'provider_payment_intent_id' => $mockIntentId,
            'amount_minor_units' => 79900,
            'currency' => 'USD',
            'status' => Payment::STATUS_PENDING,
        ]);
    }

    public function test_reuses_existing_payment_intent_if_already_pending(): void
    {
        $mockIntentId = 'pi_3MtwBwLkdIwHu7ix28a3tqPa';
        $mockClientSecret = 'pi_3MtwBwLkdIwHu7ix28a3tqPa_secret_YrKJ2R53E9j0BvGf7';

        // Pre-create pending payment
        Payment::create([
            'organization_id' => $this->org->id,
            'order_id' => $this->order->id,
            'provider' => 'STRIPE',
            'provider_payment_intent_id' => $mockIntentId,
            'amount_minor_units' => 79900,
            'currency' => 'USD',
            'status' => Payment::STATUS_PENDING,
        ]);

        Http::fake([
            "https://api.stripe.com/v1/payment_intents/{$mockIntentId}" => Http::response([
                'id' => $mockIntentId,
                'status' => 'requires_payment_method',
                'client_secret' => $mockClientSecret,
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/orders/{$this->order->id}/payments/stripe-intent", [], [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.payment_intent_id', $mockIntentId)
            ->assertJsonPath('data.client_secret', $mockClientSecret);

        // Ensure no second payment record was created
        $this->assertEquals(1, Payment::where('order_id', $this->order->id)->count());
    }

    public function test_cannot_create_intent_for_non_existent_order(): void
    {
        $randomUuid = (string) Str::uuid();

        $response = $this->postJson("/api/v1/orders/{$randomUuid}/payments/stripe-intent", [], [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'ORDER_NOT_FOUND');
    }

    public function test_cannot_create_intent_for_already_paid_order(): void
    {
        $this->order->update(['status' => Order::STATUS_PAID]);

        $response = $this->postJson("/api/v1/orders/{$this->order->id}/payments/stripe-intent", [], [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'INVALID_ORDER_STATE');
    }

    public function test_cannot_create_intent_if_reservation_has_expired(): void
    {
        // Expire the reservation
        $this->reservation->update([
            'expires_at' => now()->subMinutes(1),
        ]);

        $response = $this->postJson("/api/v1/orders/{$this->order->id}/payments/stripe-intent", [], [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'RESERVATION_EXPIRED');
    }

    public function test_handles_stripe_api_failure_gracefully(): void
    {
        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => Http::response([
                'error' => [
                    'message' => 'Your card was declined or currency not supported.',
                    'type' => 'invalid_request_error',
                ],
            ], 400),
        ]);

        $response = $this->postJson("/api/v1/orders/{$this->order->id}/payments/stripe-intent", [], [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'STRIPE_API_ERROR');
    }
}

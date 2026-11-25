<?php

namespace App\Services\Payment;

use App\Exceptions\PaymentException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StripePaymentService
{
    protected string $secretKey;
    protected string $apiBaseUrl = 'https://api.stripe.com/v1';

    public function __construct()
    {
        $this->secretKey = (string) config('services.stripe.secret');
    }

    /**
     * Create or retrieve an active Stripe PaymentIntent for the given Order.
     *
     * @return array{
     *     payment_id: string,
     *     payment_intent_id: string,
     *     client_secret: string,
     *     amount_minor_units: int,
     *     currency: string,
     *     status: string
     * }
     * @throws PaymentException
     */
    public function createPaymentIntent(string $orderId): array
    {
        return DB::transaction(function () use ($orderId) {
            // 1. Lock the order row and load its associated reservation
            $order = Order::with('reservation')
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new PaymentException(
                    message: "Order [{$orderId}] not found.",
                    errorCode: 'ORDER_NOT_FOUND',
                    statusCode: 404
                );
            }

            // 2. Invariant guard: Order must be in PENDING_PAYMENT status
            if ($order->status !== Order::STATUS_PENDING_PAYMENT) {
                throw new PaymentException(
                    message: "Cannot initiate payment: Order [{$order->order_number}] is in status '{$order->status}'.",
                    errorCode: 'INVALID_ORDER_STATE',
                    statusCode: 422
                );
            }

            // 3. Invariant guard: Reservation must exist, be ACTIVE, and not expired
            $reservation = $order->reservation;
            if (! $reservation) {
                throw new PaymentException(
                    message: "Cannot initiate payment: Order [{$order->order_number}] has no associated reservation.",
                    errorCode: 'RESERVATION_NOT_FOUND',
                    statusCode: 422
                );
            }

            if ($reservation->status !== Reservation::STATUS_ACTIVE) {
                throw new PaymentException(
                    message: "Cannot initiate payment: Associated reservation [{$reservation->id}] is in status '{$reservation->status}'.",
                    errorCode: 'RESERVATION_NOT_ACTIVE',
                    statusCode: 422
                );
            }

            if ($reservation->expires_at && $reservation->expires_at->isPast()) {
                throw new PaymentException(
                    message: "Cannot initiate payment: Associated reservation [{$reservation->id}] expired at {$reservation->expires_at->toIso8601String()}.",
                    errorCode: 'RESERVATION_EXPIRED',
                    statusCode: 422
                );
            }

            // 4. Duplicate intent guard: check if a PENDING Stripe payment already exists for this order
            $existingPayment = Payment::where('order_id', $order->id)
                ->where('provider', 'STRIPE')
                ->where('status', Payment::STATUS_PENDING)
                ->whereNotNull('provider_payment_intent_id')
                ->first();

            if ($existingPayment) {
                // Retrieve the existing PaymentIntent from Stripe to get its latest client_secret
                $retrievedIntent = $this->retrieveStripeIntent($existingPayment->provider_payment_intent_id);
                if ($retrievedIntent && isset($retrievedIntent['client_secret'])) {
                    return [
                        'payment_id' => $existingPayment->id,
                        'payment_intent_id' => $existingPayment->provider_payment_intent_id,
                        'client_secret' => $retrievedIntent['client_secret'],
                        'amount_minor_units' => $existingPayment->amount_minor_units,
                        'currency' => $existingPayment->currency,
                        'status' => $existingPayment->status,
                    ];
                }
            }

            // 5. Call Stripe API to create a new PaymentIntent
            $stripeResponse = Http::withToken($this->secretKey)
                ->asForm()
                ->post("{$this->apiBaseUrl}/payment_intents", [
                    'amount' => $order->total_amount_minor_units,
                    'currency' => strtolower($order->currency),
                    'description' => "StockForge Order {$order->order_number} payment",
                    'automatic_payment_methods[enabled]' => 'true',
                    'metadata' => [
                        'organization_id' => $order->organization_id,
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'reservation_id' => $order->reservation_id,
                        'customer_email' => $order->customer_email,
                    ],
                ]);

            if (! $stripeResponse->successful()) {
                $errorMessage = $stripeResponse->json('error.message') ?? 'Unknown error from Stripe API';
                Log::error('Stripe PaymentIntent creation failed', [
                    'order_id' => $order->id,
                    'status' => $stripeResponse->status(),
                    'error' => $stripeResponse->json(),
                ]);

                throw new PaymentException(
                    message: "Stripe error: {$errorMessage}",
                    errorCode: 'STRIPE_API_ERROR',
                    statusCode: 502
                );
            }

            $intentData = $stripeResponse->json();
            $paymentIntentId = $intentData['id'];
            $clientSecret = $intentData['client_secret'];

            // 6. Persist Payment record in local database
            $payment = Payment::create([
                'organization_id' => $order->organization_id,
                'order_id' => $order->id,
                'provider' => 'STRIPE',
                'provider_payment_intent_id' => $paymentIntentId,
                'amount_minor_units' => $order->total_amount_minor_units,
                'currency' => strtoupper($order->currency),
                'status' => Payment::STATUS_PENDING,
            ]);

            return [
                'payment_id' => $payment->id,
                'payment_intent_id' => $paymentIntentId,
                'client_secret' => $clientSecret,
                'amount_minor_units' => $order->total_amount_minor_units,
                'currency' => $order->currency,
                'status' => $payment->status,
            ];
        });
    }

    /**
     * Retrieve an existing PaymentIntent from Stripe.
     *
     * @return array<string, mixed>|null
     */
    protected function retrieveStripeIntent(string $paymentIntentId): ?array
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->get("{$this->apiBaseUrl}/payment_intents/{$paymentIntentId}");

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning("Stripe PaymentIntent retrieve returned status {$response->status()}", [
                'payment_intent_id' => $paymentIntentId,
            ]);
        } catch (\Throwable $e) {
            Log::warning("Failed to retrieve Stripe PaymentIntent [{$paymentIntentId}]: {$e->getMessage()}");
        }

        return null;
    }
}

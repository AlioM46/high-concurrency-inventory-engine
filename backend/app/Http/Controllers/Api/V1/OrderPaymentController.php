<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Payment\StripePaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class OrderPaymentController extends Controller
{
    use ApiResponse;

    /**
     * Create or retrieve a Stripe PaymentIntent for the given Order.
     */
    public function createStripeIntent(string $id, StripePaymentService $stripeService): JsonResponse
    {
        $result = $stripeService->createPaymentIntent($id);

        return $this->successResponse(
            $result,
            'Stripe PaymentIntent created successfully.',
            201
        );
    }
}

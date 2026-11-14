<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsufficientStockException extends Exception
{
    public function __construct(
        string $message = 'Insufficient stock available to complete this operation.',
        protected int $requested = 0,
        protected int $available = 0,
        int $code = 422
    ) {
        parent::__construct($message, $code);
    }

    // convert the Exception into HTTP response
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'error_code' => 'INSUFFICIENT_STOCK',
            'details' => [
                'requested_quantity' => $this->requested,
                'available_quantity' => $this->available,
            ],
        ], 422);
    }
}

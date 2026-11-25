<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentException extends Exception
{
    public function __construct(
        string $message = 'Payment processing error.',
        protected string $errorCode = 'PAYMENT_ERROR',
        int $statusCode = 422
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->getCode() ?: 422);
    }
}

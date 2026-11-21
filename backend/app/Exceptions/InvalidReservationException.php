<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvalidReservationException extends Exception
{
    public function __construct(
        string $message = 'The requested reservation cannot be converted into an order.',
        protected string $errorCode = 'INVALID_RESERVATION',
        int $statusCode = 422
    ) {
        parent::__construct($message, $statusCode);
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

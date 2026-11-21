<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reservation_id' => ['required', 'uuid',"exists:reservations,id"],
            'customer_email' => ['required', 'email', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }

    /**
     * Custom error messages for validation.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reservation_id.required' => 'The reservation_id is required to create an order.',
            'reservation_id.uuid' => 'The reservation_id must be a valid UUID.',
            'reservation_id.exists' => 'The specified reservation was not found.',
            'customer_email.required' => 'A valid customer email is required for the order.',
            'customer_email.email' => 'Please provide a valid email address.',
            'currency.size' => 'The currency must be a 3-letter ISO code (e.g. USD).',
        ];
    }
}

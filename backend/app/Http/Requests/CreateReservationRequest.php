<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateReservationRequest extends FormRequest
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
            'customer_id' => ['required', 'string', 'max:100'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_warehouse_id' => ['required', 'uuid', 'exists:product_warehouses,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price_minor_units' => ['required', 'integer', 'min:0'],
            'expires_in_minutes' => ['nullable', 'integer', 'min:1', 'max:60'],
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
            'customer_id.required' => 'The customer_id field is required.',
            'items.required' => 'At least one item must be included in the reservation cart.',
            'items.min' => 'At least one item must be included in the reservation cart.',
            'items.*.product_warehouse_id.required' => 'Each item must specify a valid product_warehouse_id.',
            'items.*.product_warehouse_id.exists' => 'One or more selected product warehouse items do not exist.',
            'items.*.quantity.min' => 'Each item quantity must be at least 1.',
            'items.*.unit_price_minor_units.min' => 'Unit price cannot be negative.',
        ];
    }
}

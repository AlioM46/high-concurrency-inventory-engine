<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateStockAdjustmentRequest extends FormRequest
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
            'product_warehouse_id' => ['required', 'uuid', 'exists:product_warehouses,id'],
            'quantity_delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'actor' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_warehouse_id.required' => 'The product_warehouse_id field is required.',
            'product_warehouse_id.exists' => 'The selected product warehouse link does not exist.',
            'quantity_delta.required' => 'The quantity_delta field is required.',
            'quantity_delta.integer' => 'The quantity_delta must be an integer (positive or negative).',
            'quantity_delta.not_in' => 'The quantity_delta cannot be zero.',
            'reason.required' => 'An audit reason is required for every manual stock adjustment.',
            'reason.min' => 'The reason must be at least 3 characters long.',
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'mess_id' => 'required|exists:messes,id',
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:0.01|max:999999.99',
            'payment_date' => 'required|date|before_or_equal:today',
            'payment_method' => ['required', Rule::in(['cash', 'bkash', 'nagad', 'card', 'bank_transfer'])],
            'transaction_id' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'receipt_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'mess_id.required' => 'Mess ID is required',
            'mess_id.exists' => 'Selected mess does not exist',
            'user_id.required' => 'User ID is required',
            'user_id.exists' => 'Selected user does not exist',
            'amount.required' => 'Payment amount is required',
            'amount.numeric' => 'Payment amount must be a number',
            'amount.min' => 'Payment amount must be at least 0.01',
            'amount.max' => 'Payment amount cannot exceed 999,999.99',
            'payment_date.required' => 'Payment date is required',
            'payment_date.date' => 'Payment date must be a valid date',
            'payment_date.before_or_equal' => 'Payment date cannot be in the future',
            'payment_method.required' => 'Payment method is required',
            'payment_method.in' => 'Invalid payment method selected',
            'transaction_id.string' => 'Transaction ID must be a string',
            'transaction_id.max' => 'Transaction ID cannot exceed 255 characters',
            'notes.string' => 'Notes must be a string',
            'notes.max' => 'Notes cannot exceed 1000 characters',
            'receipt_image.image' => 'Receipt must be an image file',
            'receipt_image.mimes' => 'Receipt must be a JPEG, PNG, JPG, or GIF file',
            'receipt_image.max' => 'Receipt image cannot exceed 2MB',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array
     */
    public function attributes(): array
    {
        return [
            'mess_id' => 'Mess',
            'user_id' => 'User',
            'amount' => 'Payment Amount',
            'payment_date' => 'Payment Date',
            'payment_method' => 'Payment Method',
            'transaction_id' => 'Transaction ID',
            'notes' => 'Notes',
            'receipt_image' => 'Receipt Image',
        ];
    }
}

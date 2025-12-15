<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentRequest extends FormRequest
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
            'amount' => 'sometimes|required|numeric|min:0.01|max:999999.99',
            'payment_method' => ['sometimes', 'required', Rule::in(['cash', 'bkash', 'nagad', 'card', 'bank_transfer'])],
            'transaction_id' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
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
            'amount.required' => 'Payment amount is required when provided',
            'amount.numeric' => 'Payment amount must be a number',
            'amount.min' => 'Payment amount must be at least 0.01',
            'amount.max' => 'Payment amount cannot exceed 999,999.99',
            'payment_method.required' => 'Payment method is required when provided',
            'payment_method.in' => 'Invalid payment method selected',
            'transaction_id.string' => 'Transaction ID must be a string',
            'transaction_id.max' => 'Transaction ID cannot exceed 255 characters',
            'notes.string' => 'Notes must be a string',
            'notes.max' => 'Notes cannot exceed 1000 characters',
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
            'amount' => 'Payment Amount',
            'payment_method' => 'Payment Method',
            'transaction_id' => 'Transaction ID',
            'notes' => 'Notes',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $payment = $this->route('payment');

            // Check if payment is already approved
            if ($payment && $payment->isApproved()) {
                $validator->errors()->add('payment', 'Cannot update an approved payment');
            }
        });
    }
}

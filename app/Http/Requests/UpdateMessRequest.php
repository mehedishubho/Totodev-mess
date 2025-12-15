<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMessRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $mess = $this->route('mess');
        return auth()->user()->can('update', $mess);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'address' => 'sometimes|required|string',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'breakfast_rate' => 'sometimes|required|numeric|min:0',
            'lunch_rate' => 'sometimes|required|numeric|min:0',
            'dinner_rate' => 'sometimes|required|numeric|min:0',
            'payment_cycle' => 'sometimes|required|in:weekly,monthly',
            'meal_cutoff_time' => 'sometimes|required|date_format:H:i',
            'max_members' => 'nullable|integer|min:1',
            'auto_bazar_rotation' => 'boolean',
            'bazar_rotation_days' => 'nullable|array',
            'bazar_rotation_days.*' => 'integer|min:1|max:7',
            'settings' => 'nullable|array',
            'status' => 'boolean'
        ];
    }

    /**
     * Get custom error messages for validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Mess name is required',
            'address.required' => 'Mess address is required',
            'breakfast_rate.required' => 'Breakfast rate is required',
            'breakfast_rate.numeric' => 'Breakfast rate must be a number',
            'breakfast_rate.min' => 'Breakfast rate cannot be negative',
            'lunch_rate.required' => 'Lunch rate is required',
            'lunch_rate.numeric' => 'Lunch rate must be a number',
            'lunch_rate.min' => 'Lunch rate cannot be negative',
            'dinner_rate.required' => 'Dinner rate is required',
            'dinner_rate.numeric' => 'Dinner rate must be a number',
            'dinner_rate.min' => 'Dinner rate cannot be negative',
            'payment_cycle.required' => 'Payment cycle is required',
            'payment_cycle.in' => 'Payment cycle must be either weekly or monthly',
            'meal_cutoff_time.required' => 'Meal cutoff time is required',
            'meal_cutoff_time.date_format' => 'Meal cutoff time must be in HH:MM format',
            'max_members.integer' => 'Maximum members must be a number',
            'max_members.min' => 'Maximum members must be at least 1',
            'logo.image' => 'Logo must be an image file',
            'logo.mimes' => 'Logo must be a file of type: jpeg, png, jpg, gif',
            'logo.max' => 'Logo may not be greater than 2MB',
            'bazar_rotation_days.*.integer' => 'Bazar rotation days must be numbers',
            'bazar_rotation_days.*.min' => 'Bazar rotation days must be between 1 and 7',
            'bazar_rotation_days.*.max' => 'Bazar rotation days must be between 1 and 7'
        ];
    }
}

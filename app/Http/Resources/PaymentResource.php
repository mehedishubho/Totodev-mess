<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mess_id' => $this->mess_id,
            'user_id' => $this->user_id,
            'amount' => $this->amount,
            'payment_date' => $this->payment_date,
            'payment_method' => $this->payment_method,
            'transaction_id' => $this->transaction_id,
            'receipt_image' => $this->receipt_image,
            'receipt_url' => $this->when($this->receipt_image, $this->receipt_url),
            'notes' => $this->notes,
            'status' => $this->status,
            'approved_at' => $this->approved_at,
            'approved_by' => $this->approved_by,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Relationships
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'phone' => $this->user->phone,
                ];
            }),

            'approved_by_user' => $this->whenLoaded('approvedBy', function () {
                return [
                    'id' => $this->approvedBy->id,
                    'name' => $this->approvedBy->name,
                    'email' => $this->approvedBy->email,
                ];
            }),

            'created_by_user' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                    'email' => $this->createdBy->email,
                ];
            }),

            'mess' => $this->whenLoaded('mess', function () {
                return [
                    'id' => $this->mess->id,
                    'name' => $this->mess->name,
                ];
            }),

            // Computed attributes
            'is_approved' => $this->isApproved(),
            'is_pending' => $this->isPending(),
            'status_display' => $this->status_display,
            'payment_method_display' => $this->payment_method_display,
            'formatted_amount' => $this->formatted_amount,
            'formatted_payment_date' => $this->formatted_payment_date,
            'time_ago' => $this->time_ago,
        ];
    }
}

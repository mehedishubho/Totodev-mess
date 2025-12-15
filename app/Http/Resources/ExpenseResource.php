<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
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
            'mess' => [
                'id' => $this->mess->id,
                'name' => $this->mess->name,
            ],
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'avatar' => $this->user->avatar_url ?? null,
            ],
            'category' => $this->when($this->category, [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'description' => $this->category->description,
                'color' => $this->category->color,
                'icon' => $this->category->icon,
            ]),
            'expense_date' => $this->expense_date,
            'formatted_expense_date' => $this->formatted_expense_date,
            'description' => $this->description,
            'amount' => (float) $this->amount,
            'receipt' => [
                'image' => $this->receipt_image,
                'url' => $this->receipt_url,
            ],
            'notes' => $this->notes,
            'status' => [
                'is_approved' => $this->is_approved,
                'approved_at' => $this->approved_at,
                'approved_by' => $this->when($this->approved_by, [
                    'id' => $this->approvedBy->id,
                    'name' => $this->approvedBy->name,
                ]),
            ],
            'created_by' => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
                'email' => $this->createdBy->email,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

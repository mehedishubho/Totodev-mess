<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BazarResource extends JsonResource
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
            'bazar_person' => [
                'id' => $this->bazarPerson->id,
                'name' => $this->bazarPerson->name,
                'email' => $this->bazarPerson->email,
                'avatar' => $this->bazarPerson->avatar_url ?? null,
            ],
            'bazar_date' => $this->bazar_date,
            'formatted_bazar_date' => $this->formatted_bazar_date,
            'item_list' => $this->formatted_item_list,
            'total_cost' => (float) $this->total_cost,
            'calculated_total_cost' => (float) $this->calculated_total_cost,
            'cost_difference' => (float) abs($this->total_cost - $this->calculated_total_cost),
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

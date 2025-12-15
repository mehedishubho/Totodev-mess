<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MealResource extends JsonResource
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
            'meal_date' => $this->meal_date,
            'formatted_meal_date' => $this->meal_date->format('M d, Y'),
            'meals' => [
                'breakfast' => $this->breakfast,
                'lunch' => $this->lunch,
                'dinner' => $this->dinner,
                'total' => $this->total_meals,
            ],
            'extra_items' => $this->formatted_extra_items,
            'extra_items_total_cost' => $this->extra_items_total_cost,
            'notes' => $this->notes,
            'cost' => [
                'breakfast_cost' => $this->breakfast * $this->mess->breakfast_rate,
                'lunch_cost' => $this->lunch * $this->mess->lunch_rate,
                'dinner_cost' => $this->dinner * $this->mess->dinner_rate,
                'meal_cost' => ($this->breakfast * $this->mess->breakfast_rate) +
                    ($this->lunch * $this->mess->lunch_rate) +
                    ($this->dinner * $this->mess->dinner_rate),
                'total_cost' => $this->total_cost,
            ],
            'rates' => [
                'breakfast_rate' => $this->mess->breakfast_rate,
                'lunch_rate' => $this->mess->lunch_rate,
                'dinner_rate' => $this->mess->dinner_rate,
            ],
            'status' => [
                'is_locked' => $this->is_locked,
                'locked_at' => $this->locked_at,
                'locked_by' => $this->when($this->locked_by, [
                    'id' => $this->lockedBy->id,
                    'name' => $this->lockedBy->name,
                ]),
            ],
            'entered_by' => [
                'id' => $this->enteredBy->id,
                'name' => $this->enteredBy->name,
                'email' => $this->enteredBy->email,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

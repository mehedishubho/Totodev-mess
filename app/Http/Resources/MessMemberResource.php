<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessMemberResource extends JsonResource
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
                'address' => $this->mess->address,
            ],
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
                'avatar' => $this->user->avatar_url ?? null,
            ],
            'role' => $this->role,
            'room_number' => $this->room_number,
            'status' => $this->status,
            'is_active' => $this->isActive(),
            'is_pending' => $this->isPending(),
            'joined_at' => $this->joined_at,
            'left_at' => $this->left_at,
            'member_since' => $this->joined_at ? $this->joined_at->format('M d, Y') : null,
            'membership_duration' => $this->joined_at ? $this->joined_at->diffForHumans(now(), true) : null,
            'approved_by' => $this->approvedBy ? [
                'id' => $this->approvedBy->id,
                'name' => $this->approvedBy->name,
                'email' => $this->approvedBy->email,
            ] : null,
            'approved_at' => $this->approved_at,
            'monthly_fixed_cost' => $this->monthly_fixed_cost,
            'deposit_amount' => $this->deposit_amount,
            'notes' => $this->notes,
            'settings' => $this->settings,
            'statistics' => $this->when(
                $request->routeIs('messes.members.show') || $request->has('include_statistics'),
                $this->getStatistics()
            ),
            'meal_summary' => $this->when(
                $request->has('include_meal_summary'),
                [
                    'total_meals_this_month' => $this->getTotalMealsForMonth(now()->year, now()->month),
                    'meal_cost_this_month' => $this->getTotalMealCostForMonth(now()->year, now()->month),
                ]
            ),
            'financial_summary' => $this->when(
                $request->has('include_financial_summary'),
                [
                    'current_balance' => $this->getCurrentBalance(),
                    'total_payments_this_month' => $this->getTotalPaymentsForMonth(now()->year, now()->month),
                    'total_bazar_cost_this_month' => $this->getTotalBazarCostForMonth(now()->year, now()->month),
                ]
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

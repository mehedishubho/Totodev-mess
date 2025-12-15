<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessResource extends JsonResource
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
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'logo' => $this->logo_url,
            'manager' => [
                'id' => $this->manager->id,
                'name' => $this->manager->name,
                'email' => $this->manager->email,
                'phone' => $this->manager->phone,
            ],
            'meal_rates' => [
                'breakfast' => (float) $this->breakfast_rate,
                'lunch' => (float) $this->lunch_rate,
                'dinner' => (float) $this->dinner_rate,
                'total_daily' => (float) $this->getTotalDailyMealRate(),
                'total_monthly' => (float) $this->getTotalMonthlyMealRate(),
            ],
            'payment_cycle' => $this->payment_cycle,
            'meal_cutoff_time' => $this->formatted_meal_cutoff_time,
            'max_members' => $this->max_members,
            'auto_bazar_rotation' => $this->auto_bazar_rotation,
            'bazar_rotation_days' => $this->bazar_rotation_days,
            'settings' => $this->settings,
            'status' => $this->status,
            'is_active' => $this->isActive(),
            'available_slots' => $this->getAvailableSlots(),
            'has_reached_max_members' => $this->hasReachedMaxMembers(),
            'statistics' => [
                'total_members' => $this->members_count,
                'active_members' => $this->activeMembers()->count(),
                'pending_members' => $this->pendingMembers()->count(),
                'total_meals_today' => $this->getTotalMealsForDate(now()),
                'total_bazar_this_month' => $this->getTotalBazarCostForMonth(now()->year, now()->month),
            ],
            'next_bazar_person' => $this->getNextBazarPerson() ? [
                'id' => $this->getNextBazarPerson()->id,
                'name' => $this->getNextBazarPerson()->name,
                'email' => $this->getNextBazarPerson()->email,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

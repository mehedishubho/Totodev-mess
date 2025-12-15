<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;

class Mess extends Model
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'name',
        'address',
        'phone',
        'email',
        'logo',
        'manager_id',
        'breakfast_rate',
        'lunch_rate',
        'dinner_rate',
        'payment_cycle',
        'meal_cutoff_time',
        'max_members',
        'auto_bazar_rotation',
        'bazar_rotation_days',
        'settings',
        'status'
    ];

    protected $casts = [
        'breakfast_rate' => 'decimal:2',
        'lunch_rate' => 'decimal:2',
        'dinner_rate' => 'decimal:2',
        'meal_cutoff_time' => 'datetime:H:i',
        'auto_bazar_rotation' => 'boolean',
        'bazar_rotation_days' => 'array',
        'settings' => 'array',
        'status' => 'boolean'
    ];

    /**
     * Get the manager that owns the mess.
     */
    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * Get the members for the mess.
     */
    public function members()
    {
        return $this->hasMany(MessMember::class);
    }

    /**
     * Get the active members for the mess.
     */
    public function activeMembers()
    {
        return $this->members()->active();
    }

    /**
     * Get the pending members for the mess.
     */
    public function pendingMembers()
    {
        return $this->members()->pending();
    }

    /**
     * Get the meals for the mess.
     */
    public function meals()
    {
        return $this->hasMany(Meal::class);
    }

    /**
     * Get the bazars for the mess.
     */
    public function bazars()
    {
        return $this->hasMany(Bazar::class);
    }

    /**
     * Get the expenses for the mess.
     */
    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Get the payments for the mess.
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get the announcements for the mess.
     */
    public function announcements()
    {
        return $this->hasMany(Announcement::class);
    }

    /**
     * Get the inventories for the mess.
     */
    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }

    /**
     * Scope a query to only include active messes.
     */
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    /**
     * Scope a query to only include messes managed by specific user.
     */
    public function scopeManagedBy($query, $userId)
    {
        return $query->where('manager_id', $userId);
    }

    /**
     * Check if mess is active.
     */
    public function isActive()
    {
        return $this->status === true;
    }

    /**
     * Get total daily meal rate.
     */
    public function getTotalDailyMealRate()
    {
        return $this->breakfast_rate + $this->lunch_rate + $this->dinner_rate;
    }

    /**
     * Get total monthly meal rate (assuming 30 days).
     */
    public function getTotalMonthlyMealRate()
    {
        return $this->getTotalDailyMealRate() * 30;
    }

    /**
     * Check if mess has reached maximum members.
     */
    public function hasReachedMaxMembers()
    {
        if (!$this->max_members) {
            return false;
        }

        return $this->activeMembers()->count() >= $this->max_members;
    }

    /**
     * Get available slots for new members.
     */
    public function getAvailableSlots()
    {
        if (!$this->max_members) {
            return null; // Unlimited
        }

        return max(0, $this->max_members - $this->activeMembers()->count());
    }

    /**
     * Get next bazar person based on rotation.
     */
    public function getNextBazarPerson()
    {
        if (!$this->auto_bazar_rotation) {
            return null;
        }

        $activeMembers = $this->activeMembers()->get();

        if ($activeMembers->isEmpty()) {
            return null;
        }

        $lastBazar = $this->bazars()
            ->orderBy('bazar_date', 'desc')
            ->first();

        if (!$lastBazar) {
            return $activeMembers->first()->user;
        }

        $currentIndex = $activeMembers->search(function ($member) use ($lastBazar) {
            return $member->user_id === $lastBazar->bazar_person_id;
        });

        $nextIndex = ($currentIndex + 1) % $activeMembers->count();
        $nextMember = $activeMembers->get($nextIndex);

        return $nextMember ? $nextMember->user : null;
    }

    /**
     * Get total meals for a specific date.
     */
    public function getTotalMealsForDate($date)
    {
        return $this->meals()
            ->whereDate('meal_date', $date)
            ->selectRaw('SUM(breakfast + lunch + dinner) as total_meals')
            ->value('total_meals') ?? 0;
    }

    /**
     * Get total meals for a specific month.
     */
    public function getTotalMealsForMonth($year, $month)
    {
        return $this->meals()
            ->whereYear('meal_date', $year)
            ->whereMonth('meal_date', $month)
            ->selectRaw('SUM(breakfast + lunch + dinner) as total_meals')
            ->value('total_meals') ?? 0;
    }

    /**
     * Get total bazar cost for a specific month.
     */
    public function getTotalBazarCostForMonth($year, $month)
    {
        return $this->bazars()
            ->whereYear('bazar_date', $year)
            ->whereMonth('bazar_date', $month)
            ->sum('total_cost');
    }

    /**
     * Get total expense cost for a specific month.
     */
    public function getTotalExpenseCostForMonth($year, $month)
    {
        return $this->expenses()
            ->whereYear('expense_date', $year)
            ->whereMonth('expense_date', $month)
            ->sum('amount');
    }

    /**
     * Get total payment received for a specific month.
     */
    public function getTotalPaymentsForMonth($year, $month)
    {
        return $this->payments()
            ->whereYear('payment_date', $year)
            ->whereMonth('payment_date', $month)
            ->where('status', 'completed')
            ->sum('amount');
    }

    /**
     * Get mess statistics for current month.
     */
    public function getCurrentMonthStatistics()
    {
        $currentMonth = now();

        return [
            'total_members' => $this->activeMembers()->count(),
            'pending_members' => $this->pendingMembers()->count(),
            'total_meals' => $this->getTotalMealsForMonth($currentMonth->year, $currentMonth->month),
            'total_bazar_cost' => $this->getTotalBazarCostForMonth($currentMonth->year, $currentMonth->month),
            'total_expense_cost' => $this->getTotalExpenseCostForMonth($currentMonth->year, $currentMonth->month),
            'total_payments' => $this->getTotalPaymentsForMonth($currentMonth->year, $currentMonth->month),
            'daily_meal_rate' => $this->getTotalDailyMealRate(),
            'monthly_meal_rate' => $this->getTotalMonthlyMealRate(),
            'available_slots' => $this->getAvailableSlots()
        ];
    }

    /**
     * Get meal summary for a specific date range.
     */
    public function getMealSummary($startDate, $endDate)
    {
        return $this->meals()
            ->whereBetween('meal_date', [$startDate, $endDate])
            ->selectRaw('
                meal_date,
                SUM(breakfast) as total_breakfast,
                SUM(lunch) as total_lunch,
                SUM(dinner) as total_dinner,
                SUM(breakfast + lunch + dinner) as total_meals,
                COUNT(DISTINCT user_id) as unique_members
            ')
            ->groupBy('meal_date')
            ->orderBy('meal_date')
            ->get();
    }

    /**
     * Check if meal entry is allowed for a specific date.
     */
    public function isMealEntryAllowed($date)
    {
        if (!$date) {
            return false;
        }

        $mealDate = is_string($date) ? \Carbon\Carbon::parse($date) : $date;
        $now = now();

        // Don't allow meal entry for past dates beyond cutoff time
        if ($mealDate->isToday() && $now->gt($this->meal_cutoff_time)) {
            return false;
        }

        // Don't allow meal entry for past dates
        if ($mealDate->isPast() && !$mealDate->isToday()) {
            return false;
        }

        return true;
    }

    /**
     * Get logo URL.
     */
    public function getLogoUrlAttribute()
    {
        if (!$this->logo) {
            return null;
        }

        return asset('storage/' . $this->logo);
    }

    /**
     * Get formatted meal cutoff time.
     */
    public function getFormattedMealCutoffTimeAttribute()
    {
        return $this->meal_cutoff_time ? $this->meal_cutoff_time->format('h:i A') : null;
    }

    /**
     * Get members count relationship.
     */
    public function membersCount()
    {
        return $this->hasOne(MessMember::class)
            ->selectRaw('mess_id, count(*) as aggregate')
            ->where('status', 'approved')
            ->groupBy('mess_id');
    }

    /**
     * Get the members count attribute.
     */
    public function getMembersCountAttribute()
    {
        if (!$this->relationLoaded('membersCount')) {
            $this->load('membersCount');
        }

        $related = $this->getRelation('membersCount');

        return $related ? (int) $related->aggregate : 0;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;

class Meal extends Model
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'mess_id',
        'user_id',
        'meal_date',
        'breakfast',
        'lunch',
        'dinner',
        'extra_items',
        'notes',
        'entered_by',
        'locked_at',
        'locked_by'
    ];

    protected $casts = [
        'meal_date' => 'date',
        'breakfast' => 'integer',
        'lunch' => 'integer',
        'dinner' => 'integer',
        'extra_items' => 'array',
        'locked_at' => 'datetime',
        'notes' => 'string'
    ];

    /**
     * Get the mess that owns the meal.
     */
    public function mess()
    {
        return $this->belongsTo(Mess::class);
    }

    /**
     * Get the user that owns the meal.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who entered the meal.
     */
    public function enteredBy()
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /**
     * Get the user who locked the meal.
     */
    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /**
     * Scope a query to only include meals for a specific date.
     */
    public function scopeForDate($query, $date)
    {
        return $query->whereDate('meal_date', $date);
    }

    /**
     * Scope a query to only include meals for a specific month.
     */
    public function scopeForMonth($query, $year, $month)
    {
        return $query->whereYear('meal_date', $year)
            ->whereMonth('meal_date', $month);
    }

    /**
     * Scope a query to only include meals for today.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('meal_date', today());
    }

    /**
     * Scope a query to only include unlocked meals.
     */
    public function scopeUnlocked($query)
    {
        return $query->whereNull('locked_at');
    }

    /**
     * Scope a query to only include locked meals.
     */
    public function scopeLocked($query)
    {
        return $query->whereNotNull('locked_at');
    }

    /**
     * Get total meals for the day.
     */
    public function getTotalMealsAttribute()
    {
        return $this->breakfast + $this->lunch + $this->dinner;
    }

    /**
     * Get total meal cost for the day.
     */
    public function getTotalCostAttribute()
    {
        $mess = $this->mess;
        $totalCost = ($this->breakfast * $mess->breakfast_rate) +
            ($this->lunch * $mess->lunch_rate) +
            ($this->dinner * $mess->dinner_rate);

        // Add extra items cost if any
        if ($this->extra_items) {
            foreach ($this->extra_items as $item) {
                $totalCost += ($item['quantity'] ?? 1) * ($item['price'] ?? 0);
            }
        }

        return $totalCost;
    }

    /**
     * Check if meal is locked.
     */
    public function isLocked()
    {
        return !is_null($this->locked_at);
    }

    /**
     * Lock the meal.
     */
    public function lock($lockedBy = null)
    {
        $this->update([
            'locked_at' => now(),
            'locked_by' => $lockedBy ?? auth()->id()
        ]);

        return $this;
    }

    /**
     * Unlock the meal.
     */
    public function unlock()
    {
        $this->update([
            'locked_at' => null,
            'locked_by' => null
        ]);

        return $this;
    }

    /**
     * Get formatted extra items.
     */
    public function getFormattedExtraItemsAttribute()
    {
        if (!$this->extra_items) {
            return [];
        }

        return collect($this->extra_items)->map(function ($item) {
            return [
                'name' => $item['name'] ?? 'Unknown',
                'quantity' => $item['quantity'] ?? 1,
                'price' => $item['price'] ?? 0,
                'total_cost' => ($item['quantity'] ?? 1) * ($item['price'] ?? 0)
            ];
        });
    }

    /**
     * Get extra items total cost.
     */
    public function getExtraItemsTotalCostAttribute()
    {
        if (!$this->extra_items) {
            return 0;
        }

        return collect($this->extra_items)->sum(function ($item) {
            return ($item['quantity'] ?? 1) * ($item['price'] ?? 0);
        });
    }

    /**
     * Get meal summary for a specific date.
     */
    public static function getMealSummary($messId, $date)
    {
        return self::where('mess_id', $messId)
            ->forDate($date)
            ->with(['user', 'enteredBy'])
            ->get()
            ->groupBy('user_id');
    }

    /**
     * Get daily meal totals for a mess.
     */
    public static function getDailyMealTotals($messId, $startDate, $endDate)
    {
        return self::where('mess_id', $messId)
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
     * Get monthly meal report for a user.
     */
    public static function getMonthlyMealReport($messId, $userId, $year, $month)
    {
        return self::where('mess_id', $messId)
            ->where('user_id', $userId)
            ->forMonth($year, $month)
            ->orderBy('meal_date')
            ->get();
    }

    /**
     * Check if meal entry is allowed for a specific date.
     */
    public static function isMealEntryAllowed($messId, $date)
    {
        $mess = Mess::find($messId);
        if (!$mess) {
            return false;
        }

        $mealDate = is_string($date) ? \Carbon\Carbon::parse($date) : $date;
        $now = now();

        // Don't allow meal entry for past dates beyond cutoff time
        if ($mealDate->isToday() && $now->gt($mess->meal_cutoff_time)) {
            return false;
        }

        // Don't allow meal entry for past dates
        if ($mealDate->isPast() && !$mealDate->isToday()) {
            return false;
        }

        return true;
    }

    /**
     * Get meal statistics for a user in a specific month.
     */
    public static function getUserMealStatistics($messId, $userId, $year, $month)
    {
        $meals = self::where('mess_id', $messId)
            ->where('user_id', $userId)
            ->forMonth($year, $month)
            ->get();

        $mess = Mess::find($messId);

        return [
            'total_breakfast' => $meals->sum('breakfast'),
            'total_lunch' => $meals->sum('lunch'),
            'total_dinner' => $meals->sum('dinner'),
            'total_meals' => $meals->sum(function ($meal) {
                return $meal->breakfast + $meal->lunch + $meal->dinner;
            }),
            'total_meal_cost' => $meals->sum('total_cost'),
            'extra_items_cost' => $meals->sum('extra_items_total_cost'),
            'days_with_meals' => $meals->count(),
            'average_meals_per_day' => $meals->count() > 0 ?
                $meals->sum(function ($meal) {
                    return $meal->breakfast + $meal->lunch + $meal->dinner;
                }) / $meals->count() : 0,
            'meal_rates' => [
                'breakfast' => $mess->breakfast_rate,
                'lunch' => $mess->lunch_rate,
                'dinner' => $mess->dinner_rate,
            ]
        ];
    }

    /**
     * Get mess meal statistics for a specific month.
     */
    public static function getMessMealStatistics($messId, $year, $month)
    {
        $meals = self::where('mess_id', $messId)
            ->forMonth($year, $month)
            ->get();

        return [
            'total_breakfast' => $meals->sum('breakfast'),
            'total_lunch' => $meals->sum('lunch'),
            'total_dinner' => $meals->sum('dinner'),
            'total_meals' => $meals->sum(function ($meal) {
                return $meal->breakfast + $meal->lunch + $meal->dinner;
            }),
            'unique_members' => $meals->pluck('user_id')->unique()->count(),
            'days_with_meals' => $meals->pluck('meal_date')->unique()->count(),
            'average_meals_per_day' => $meals->pluck('meal_date')->unique()->count() > 0 ?
                $meals->sum(function ($meal) {
                    return $meal->breakfast + $meal->lunch + $meal->dinner;
                }) / $meals->pluck('meal_date')->unique()->count() : 0,
            'total_extra_items_cost' => $meals->sum('extra_items_total_cost'),
        ];
    }
}

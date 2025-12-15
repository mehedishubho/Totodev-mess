<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;

class MessMember extends Model
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'mess_id',
        'user_id',
        'role',
        'room_number',
        'status',
        'joined_at',
        'left_at',
        'approved_by',
        'approved_at',
        'notes',
        'monthly_fixed_cost',
        'deposit_amount',
        'settings'
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'approved_at' => 'datetime',
        'monthly_fixed_cost' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'settings' => 'array',
        'status' => 'string'
    ];

    /**
     * Get the mess that owns the member.
     */
    public function mess()
    {
        return $this->belongsTo(Mess::class);
    }

    /**
     * Get the user that is the member.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who approved this member.
     */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the meals for this member.
     */
    public function meals()
    {
        return $this->hasMany(Meal::class, 'user_id', 'user_id')
            ->where('mess_id', $this->mess_id);
    }

    /**
     * Get the bazars assigned to this member.
     */
    public function bazars()
    {
        return $this->hasMany(Bazar::class, 'bazar_person_id', 'user_id')
            ->where('mess_id', $this->mess_id);
    }

    /**
     * Get the payments for this member.
     */
    public function payments()
    {
        return $this->hasMany(Payment::class, 'user_id', 'user_id')
            ->where('mess_id', $this->mess_id);
    }

    /**
     * Get the expenses for this member.
     */
    public function expenses()
    {
        return $this->hasMany(Expense::class, 'user_id', 'user_id')
            ->where('mess_id', $this->mess_id);
    }

    /**
     * Scope a query to only include active members.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'approved')
            ->whereNull('left_at');
    }

    /**
     * Scope a query to only include pending members.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include members with specific role.
     */
    public function scopeRole($query, $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Check if member is active.
     */
    public function isActive()
    {
        return $this->status === 'approved' && is_null($this->left_at);
    }

    /**
     * Check if member is pending approval.
     */
    public function isPending()
    {
        return $this->status === 'pending';
    }

    /**
     * Approve the member.
     */
    public function approve($approvedBy = null)
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $approvedBy ?? auth()->id(),
            'approved_at' => now()
        ]);

        return $this;
    }

    /**
     * Reject the member.
     */
    public function reject()
    {
        $this->update([
            'status' => 'rejected',
            'left_at' => now()
        ]);

        return $this;
    }

    /**
     * Leave the mess.
     */
    public function leave()
    {
        $this->update([
            'status' => 'left',
            'left_at' => now()
        ]);

        return $this;
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
     * Get total meal cost for a specific month.
     */
    public function getTotalMealCostForMonth($year, $month)
    {
        $totalMeals = $this->getTotalMealsForMonth($year, $month);
        $mess = $this->mess;

        return $totalMeals * ($mess->breakfast_rate + $mess->lunch_rate + $mess->dinner_rate);
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
     * Get total payments for a specific month.
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
     * Get current balance (due or advance).
     */
    public function getCurrentBalance()
    {
        $currentMonth = now();
        $totalCost = $this->getTotalMealCostForMonth($currentMonth->year, $currentMonth->month);
        $totalPaid = $this->getTotalPaymentsForMonth($currentMonth->year, $currentMonth->month);

        return $totalPaid - $totalCost;
    }

    /**
     * Get member statistics.
     */
    public function getStatistics()
    {
        $currentMonth = now();

        return [
            'total_meals_this_month' => $this->getTotalMealsForMonth($currentMonth->year, $currentMonth->month),
            'meal_cost_this_month' => $this->getTotalMealCostForMonth($currentMonth->year, $currentMonth->month),
            'bazar_cost_this_month' => $this->getTotalBazarCostForMonth($currentMonth->year, $currentMonth->month),
            'total_payments_this_month' => $this->getTotalPaymentsForMonth($currentMonth->year, $currentMonth->month),
            'current_balance' => $this->getCurrentBalance(),
            'total_bazar_assigned' => $this->bazars()->count(),
            'last_bazar_date' => $this->bazars()->max('bazar_date'),
            'membership_duration' => $this->joined_at ? $this->joined_at->diffForHumans(now(), true) : null
        ];
    }
}

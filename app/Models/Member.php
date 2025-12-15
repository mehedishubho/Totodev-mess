<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'mess_id',
        'room_number',
        'joining_date',
        'leaving_date',
        'status',
    ];

    /**
     * Get the user associated with the member.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the mess associated with the member.
     */
    public function mess()
    {
        return $this->belongsTo(Mess::class);
    }

    /**
     * Get the meals for the member.
     */
    public function meals()
    {
        return $this->hasMany(Meal::class);
    }

    /**
     * Get the bills for the member.
     */
    public function bills()
    {
        return $this->hasMany(Bill::class);
    }

    /**
     * Get the payments for the member.
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get the attendances for the member.
     */
    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Get current active bill for the member.
     */
    public function getCurrentBill()
    {
        return $this->bills()
            ->where('month', now()->month)
            ->where('year', now()->year)
            ->first();
    }

    /**
     * Get total meals for current month.
     */
    public function getCurrentMonthMeals()
    {
        return $this->meals()
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->sum('breakfast_count + lunch_count + dinner_count');
    }

    /**
     * Get total due amount.
     */
    public function getTotalDueAttribute()
    {
        return $this->bills()
            ->where('status', '!=', 'fully_paid')
            ->sum('due_amount');
    }

    /**
     * Get total paid amount for current month.
     */
    public function getCurrentMonthPaid()
    {
        return $this->payments()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('amount');
    }

    /**
     * Check if member is active.
     */
    public function isActive()
    {
        return $this->status === 'active';
    }

    /**
     * Get member's full name from user.
     */
    public function getFullNameAttribute()
    {
        return $this->user ? $this->user->name : '';
    }

    /**
     * Get member's email from user.
     */
    public function getEmailAttribute()
    {
        return $this->user ? $this->user->email : '';
    }

    /**
     * Get member's phone from user.
     */
    public function getPhoneAttribute()
    {
        return $this->user ? $this->user->phone : '';
    }
}

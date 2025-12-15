<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bill extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'member_id',
        'month',
        'year',
        'total_meals',
        'meal_cost',
        'additional_cost',
        'total_amount',
        'paid_amount',
        'due_amount',
        'status',
        'generated_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'generated_at' => 'datetime',
        'total_meals' => 'integer',
        'meal_cost' => 'decimal:2',
        'additional_cost' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_amount' => 'decimal:2',
    ];

    /**
     * Get the member that owns the bill.
     */
    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Get the payments for the bill.
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get formatted month and year.
     */
    public function getFormattedMonthYearAttribute()
    {
        $months = [
            1 => 'January',
            2 => 'February',
            3 => 'March',
            4 => 'April',
            5 => 'May',
            6 => 'June',
            7 => 'July',
            8 => 'August',
            9 => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December'
        ];

        return ($months[$this->month] ?? 'Unknown') . ' ' . $this->year;
    }

    /**
     * Get human readable status.
     */
    public function getStatusTextAttribute()
    {
        return [
            'generated' => 'Generated',
            'partially_paid' => 'Partially Paid',
            'fully_paid' => 'Fully Paid',
        ][$this->status] ?? 'Unknown';
    }

    /**
     * Calculate due amount automatically.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($bill) {
            $bill->due_amount = $bill->total_amount - $bill->paid_amount;
        });
    }

    /**
     * Scope to get bills for a specific month/year.
     */
    public function scopeForMonth($query, $month, $year)
    {
        return $query->where('month', $month)->where('year', $year);
    }

    /**
     * Scope to get bills by status.
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get overdue bills.
     */
    public function scopeOverdue($query)
    {
        return $query->where('due_amount', '>', 0)
            ->where('status', '!=', 'fully_paid');
    }
}

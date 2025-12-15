<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;

class Expense extends Model
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'mess_id',
        'user_id',
        'category_id',
        'expense_date',
        'description',
        'amount',
        'receipt_image',
        'notes',
        'approved_by',
        'approved_at',
        'created_by'
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'notes' => 'string'
    ];

    /**
     * Get the mess that owns the expense.
     */
    public function mess()
    {
        return $this->belongsTo(Mess::class);
    }

    /**
     * Get the user who created the expense.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the expense category.
     */
    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    /**
     * Get the user who approved the expense.
     */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who created the expense.
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope a query to only include expenses for a specific date.
     */
    public function scopeForDate($query, $date)
    {
        return $query->whereDate('expense_date', $date);
    }

    /**
     * Scope a query to only include expenses for a specific month.
     */
    public function scopeForMonth($query, $year, $month)
    {
        return $query->whereYear('expense_date', $year)
            ->whereMonth('expense_date', $month);
    }

    /**
     * Scope a query to only include expenses for today.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('expense_date', today());
    }

    /**
     * Scope a query to only include upcoming expenses.
     */
    public function scopeUpcoming($query)
    {
        return $query->whereDate('expense_date', '>=', today());
    }

    /**
     * Scope a query to only include past expenses.
     */
    public function scopePast($query)
    {
        return $query->whereDate('expense_date', '<', today());
    }

    /**
     * Scope a query to only include approved expenses.
     */
    public function scopeApproved($query)
    {
        return $query->whereNotNull('approved_at');
    }

    /**
     * Scope a query to only include pending expenses.
     */
    public function scopePending($query)
    {
        return $query->whereNull('approved_at');
    }

    /**
     * Scope a query to only include expenses by a specific user.
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include expenses by a specific category.
     */
    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Scope a query to only include expenses for a specific mess.
     */
    public function scopeByMess($query, $messId)
    {
        return $query->where('mess_id', $messId);
    }

    /**
     * Check if expense is approved.
     */
    public function isApproved()
    {
        return !is_null($this->approved_at);
    }

    /**
     * Check if expense is pending.
     */
    public function isPending()
    {
        return is_null($this->approved_at);
    }

    /**
     * Approve the expense.
     */
    public function approve($approvedBy = null)
    {
        $this->update([
            'approved_at' => now(),
            'approved_by' => $approvedBy ?? auth()->id()
        ]);

        return $this;
    }

    /**
     * Get receipt URL.
     */
    public function getReceiptUrlAttribute()
    {
        if (!$this->receipt_image) {
            return null;
        }

        return asset('storage/' . $this->receipt_image);
    }

    /**
     * Get formatted expense date.
     */
    public function getFormattedExpenseDateAttribute()
    {
        return $this->expense_date->format('M d, Y');
    }

    /**
     * Get expense summary for a specific month.
     */
    public static function getExpenseSummary($messId, $year, $month)
    {
        return self::where('mess_id', $messId)
            ->forMonth($year, $month)
            ->with(['category', 'user', 'approvedBy'])
            ->orderBy('expense_date')
            ->get()
            ->groupBy(function ($expense) {
                return $expense->expense_date->format('Y-m-d');
            });
    }

    /**
     * Get total expense amount for a specific month.
     */
    public static function getTotalExpenseAmount($messId, $year, $month)
    {
        return self::where('mess_id', $messId)
            ->forMonth($year, $month)
            ->sum('amount');
    }

    /**
     * Get expense statistics for a user in a specific month.
     */
    public static function getUserExpenseStatistics($messId, $userId, $year, $month)
    {
        $expenses = self::where('mess_id', $messId)
            ->where('user_id', $userId)
            ->forMonth($year, $month)
            ->get();

        return [
            'total_expenses' => $expenses->count(),
            'total_amount' => $expenses->sum('amount'),
            'average_amount' => $expenses->count() > 0 ? $expenses->sum('amount') / $expenses->count() : 0,
            'pending_expenses' => $expenses->whereNull('approved_at')->count(),
            'approved_expenses' => $expenses->whereNotNull('approved_at')->count(),
            'highest_expense' => $expenses->max('amount'),
            'lowest_expense' => $expenses->min('amount'),
        ];
    }

    /**
     * Get expense statistics for mess in a specific month.
     */
    public static function getMessExpenseStatistics($messId, $year, $month)
    {
        $expenses = self::where('mess_id', $messId)
            ->forMonth($year, $month)
            ->with(['category', 'user'])
            ->get();

        return [
            'total_expenses' => $expenses->count(),
            'total_amount' => $expenses->sum('amount'),
            'average_amount' => $expenses->count() > 0 ? $expenses->sum('amount') / $expenses->count() : 0,
            'pending_expenses' => $expenses->whereNull('approved_at')->count(),
            'approved_expenses' => $expenses->whereNotNull('approved_at')->count(),
            'expenses_by_category' => $expenses->groupBy('category_id')->map(function ($categoryExpenses, $categoryId) {
                $category = $categoryExpenses->first()->category;
                return [
                    'category' => $category ? [
                        'id' => $category->id,
                        'name' => $category->name,
                        'description' => $category->description,
                    ] : null,
                    'total_amount' => $categoryExpenses->sum('amount'),
                    'count' => $categoryExpenses->count(),
                ];
            })->values(),
            'expenses_by_user' => $expenses->groupBy('user_id')->map(function ($userExpenses, $userId) {
                $user = $userExpenses->first()->user;
                return [
                    'user' => [
                        'id' => $userId,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'total_amount' => $userExpenses->sum('amount'),
                    'count' => $userExpenses->count(),
                ];
            })->values(),
        ];
    }

    /**
     * Get expense trend for last 6 months.
     */
    public static function getExpenseTrend($messId)
    {
        $months = collect();

        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $totalAmount = self::where('mess_id', $messId)
                ->whereMonth('expense_date', $month->month)
                ->whereYear('expense_date', $month->year)
                ->sum('amount');

            $months->push([
                'month' => $month->format('M Y'),
                'amount' => $totalAmount,
                'expenses_count' => self::where('mess_id', $messId)
                    ->whereMonth('expense_date', $month->month)
                    ->whereYear('expense_date', $month->year)
                    ->count()
            ]);
        }

        return $months;
    }
}

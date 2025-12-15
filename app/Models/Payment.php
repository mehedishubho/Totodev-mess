<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;

class Payment extends Model
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'mess_id',
        'user_id',
        'amount',
        'payment_date',
        'payment_method',
        'transaction_id',
        'receipt_image',
        'notes',
        'status',
        'approved_by',
        'created_by'
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'notes' => 'string',
        'status' => 'string'
    ];

    /**
     * Get the mess that owns the payment.
     */
    public function mess()
    {
        return $this->belongsTo(Mess::class);
    }

    /**
     * Get the user who made the payment.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who approved the payment.
     */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who created the payment.
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope a query to only include payments for a specific date.
     */
    public function scopeForDate($query, $date)
    {
        return $query->whereDate('payment_date', $date);
    }

    /**
     * Scope a query to only include payments for a specific month.
     */
    public function scopeForMonth($query, $year, $month)
    {
        return $query->whereYear('payment_date', $year)
            ->whereMonth('payment_date', $month);
    }

    /**
     * Scope a query to only include payments for today.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('payment_date', today());
    }

    /**
     * Scope a query to only include upcoming payments.
     */
    public function scopeUpcoming($query)
    {
        return $query->whereDate('payment_date', '>=', today());
    }

    /**
     * Scope a query to only include past payments.
     */
    public function scopePast($query)
    {
        return $query->whereDate('payment_date', '<', today());
    }

    /**
     * Scope a query to only include payments by a specific user.
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include payments by a specific mess.
     */
    public function scopeByMess($query, $messId)
    {
        return $query->where('mess_id', $messId);
    }

    /**
     * Scope a query to only include payments by a specific status.
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include payments by a specific payment method.
     */
    public function scopeByPaymentMethod($query, $paymentMethod)
    {
        return $query->where('payment_method', $paymentMethod);
    }

    /**
     * Check if payment is approved.
     */
    public function isApproved()
    {
        return $this->status === 'approved';
    }

    /**
     * Check if payment is pending.
     */
    public function isPending()
    {
        return $this->status === 'pending';
    }

    /**
     * Check if payment is completed.
     */
    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    /**
     * Get formatted payment date.
     */
    public function getFormattedPaymentDateAttribute()
    {
        return $this->payment_date->format('M d, Y');
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
     * Get payment statistics for a user in a specific month.
     */
    public static function getUserPaymentStatistics($messId, $userId, $year, $month)
    {
        $payments = self::where('mess_id', $messId)
            ->where('user_id', $userId)
            ->forMonth($year, $month)
            ->get();

        return [
            'total_payments' => $payments->count(),
            'total_amount' => $payments->sum('amount'),
            'average_amount' => $payments->count() > 0 ? $payments->sum('amount') / $payments->count() : 0,
            'completed_payments' => $payments->where('status', 'completed')->count(),
            'pending_payments' => $payments->where('status', 'pending')->count(),
            'approved_payments' => $payments->where('status', 'approved')->count(),
            'highest_payment' => $payments->max('amount'),
            'lowest_payment' => $payments->min('amount'),
            'payments_by_method' => $payments->groupBy('payment_method')->map(function ($methodPayments, $method) {
                return [
                    'payment_method' => $method,
                    'count' => $methodPayments->count(),
                    'total_amount' => $methodPayments->sum('amount'),
                ];
            })->values(),
        ];
    }

    /**
     * Get payment statistics for mess in a specific month.
     */
    public static function getMessPaymentStatistics($messId, $year, $month)
    {
        $payments = self::where('mess_id', $messId)
            ->forMonth($year, $month)
            ->with(['user', 'approvedBy'])
            ->get();

        return [
            'total_amount' => $payments->sum('amount'),
            'total_payments' => $payments->count(),
            'completed_payments' => $payments->where('status', 'completed')->count(),
            'pending_payments' => $payments->where('status', 'pending')->count(),
            'approved_payments' => $payments->where('status', 'approved')->count(),
            'payments_by_method' => $payments->groupBy('payment_method')->map(function ($methodPayments, $method) {
                return [
                    'payment_method' => $method,
                    'count' => $methodPayments->count(),
                    'total_amount' => $methodPayments->sum('amount'),
                ];
            })->values(),
            'payments_by_user' => $payments->groupBy('user_id')->map(function ($userPayments, $userId) {
                $user = $userPayments->first()->user;
                return [
                    'user' => [
                        'id' => $userId,
                        'name' => $user->name,
                        'email' => $user->email
                    ],
                    'total_amount' => $userPayments->sum('amount'),
                    'count' => $userPayments->count(),
                    'payments' => $userPayments
                ];
            })->values(),
        ];
    }

    /**
     * Get payment trend for last 6 months.
     */
    public static function getPaymentTrend($messId)
    {
        $months = collect();

        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $totalAmount = self::where('mess_id', $messId)
                ->whereMonth('payment_date', $month->month)
                ->whereYear('payment_date', $month->year)
                ->sum('amount');

            $months->push([
                'month' => $month->format('M Y'),
                'amount' => $totalAmount,
                'payments_count' => self::where('mess_id', $messId)
                    ->whereMonth('payment_date', $month->month)
                    ->whereYear('payment_date', $month->year)
                    ->count()
            ]);
        }

        return $months;
    }

    /**
     * Calculate payment performance metrics.
     */
    public static function getPaymentPerformanceMetrics($messId, $year, $month)
    {
        $payments = self::where('mess_id', $messId)
            ->forMonth($year, $month)
            ->get();

        $totalPayments = $payments->count();
        $completedPayments = $payments->where('status', 'completed')->count();
        $pendingPayments = $payments->where('status', 'pending')->count();
        $totalAmount = $payments->sum('amount');
        $averageAmount = $totalPayments > 0 ? $totalAmount / $totalPayments : 0;

        return [
            'total_payments' => $totalPayments,
            'completion_rate' => $totalPayments > 0 ? ($completedPayments / $totalPayments) * 100 : 0,
            'pending_rate' => $totalPayments > 0 ? ($pendingPayments / $totalPayments) * 100 : 0,
            'total_amount' => $totalAmount,
            'average_amount_per_payment' => $averageAmount,
            'highest_payment' => $payments->max('amount'),
            'lowest_payment' => $payments->min('amount'),
            'payment_methods' => $payments->groupBy('payment_method')->map(function ($methodPayments, $method) {
                return [
                    'payment_method' => $method,
                    'count' => $methodPayments->count(),
                    'total_amount' => $methodPayments->sum('amount'),
                ];
            })->values(),
        ];
    }

    /**
     * Get payment history for user.
     */
    public static function getUserPaymentHistory($messId, $userId, $limit = 20)
    {
        return self::where('mess_id', $messId)
            ->where('user_id', $userId)
            ->with(['approvedBy'])
            ->orderBy('payment_date', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get due amount for user in a specific month.
     */
    public static function getUserDueAmount($messId, $userId, $year, $month)
    {
        // Get total bill amount for the user
        $expenseService = app(\App\Services\ExpenseService::class);
        $monthlyBill = $expenseService->calculateMonthlyBill($messId, $userId, $year, $month);
        $totalBillAmount = $monthlyBill['total_bill'] ?? 0;

        // Get total payments made by the user
        $totalPaidAmount = self::where('mess_id', $messId)
            ->where('user_id', $userId)
            ->whereMonth('payment_date', $year, $month)
            ->where('status', 'completed')
            ->sum('amount');

        // Calculate due amount
        $dueAmount = $totalBillAmount - $totalPaidAmount;

        return [
            'total_bill' => $totalBillAmount,
            'total_paid' => $totalPaidAmount,
            'due_amount' => $dueAmount,
            'payment_status' => $dueAmount > 0 ? 'due' : 'paid'
        ];
    }

    /**
     * Get payment methods summary.
     */
    public static function getPaymentMethodsSummary($messId)
    {
        $payments = self::where('mess_id', $messId)->get();

        return $payments->groupBy('payment_method')->map(function ($methodPayments, $method) {
            return [
                'payment_method' => $method,
                'count' => $methodPayments->count(),
                'total_amount' => $methodPayments->sum('amount'),
                'average_amount' => $methodPayments->count() > 0 ? $methodPayments->sum('amount') / $methodPayments->count() : 0,
                'latest_payment_date' => $methodPayments->max('payment_date')->format('M d, Y'),
                'payments' => $methodPayments
            ];
        })->sortByDesc('total_amount')->values();
    }

    /**
     * Get payment statistics for date range.
     */
    public static function getPaymentStatistics($messId, $startDate, $endDate)
    {
        $payments = self::where('mess_id', $messId)
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->get();

        return [
            'total_amount' => $payments->sum('amount'),
            'total_payments' => $payments->count(),
            'completed_payments' => $payments->where('status', 'completed')->count(),
            'pending_payments' => $payments->where('status', 'pending')->count(),
            'average_amount' => $payments->count() > 0 ? $payments->sum('amount') / $payments->count() : 0,
            'payment_methods' => $payments->groupBy('payment_method')->map(function ($methodPayments, $method) {
                return [
                    'payment_method' => $method,
                    'count' => $methodPayments->count(),
                    'total_amount' => $methodPayments->sum('amount'),
                ];
            })->values(),
        ];
    }

    /**
     * Get daily payment summary for a month.
     */
    public static function getDailyPaymentSummary($messId, $year, $month)
    {
        $payments = self::where('mess_id', $messId)
            ->forMonth($year, $month)
            ->get()
            ->groupBy(function ($payment) {
                return $payment->payment_date->format('Y-m-d');
            })
            ->map(function ($datePayments, $date) {
                return [
                    'date' => $date,
                    'formatted_date' => \Carbon\Carbon::parse($date)->format('M d, Y'),
                    'total_amount' => $datePayments->sum('amount'),
                    'count' => $datePayments->count(),
                    'payments' => $datePayments
                ];
            })->sortBy('date')->values();
    }

    /**
     * Get overdue payments.
     */
    public static function getOverduePayments($messId)
    {
        return self::where('mess_id', $messId)
            ->where('status', 'pending')
            ->where('payment_date', '<', now()->subDays(30)) // Payments older than 30 days
            ->with(['user'])
            ->orderBy('payment_date', 'asc')
            ->get();
    }

    /**
     * Get upcoming payment reminders.
     */
    public static function getUpcomingPaymentReminders($messId)
    {
        return self::where('mess_id', $messId)
            ->where('status', 'pending')
            ->whereBetween('payment_date', [now(), now()->addDays(7)]) // Payments due in next 7 days
            ->with(['user'])
            ->orderBy('payment_date', 'asc')
            ->get();
    }

    /**
     * Get payment collection report.
     */
    public static function getPaymentCollectionReport($messId, $year, $month)
    {
        $payments = self::where('mess_id', $messId)
            ->forMonth($year, $month)
            ->with(['user', 'approvedBy'])
            ->orderBy('payment_date', 'desc')
            ->get();

        $summary = [
            'period' => [
                'year' => $year,
                'month' => $month,
                'month_name' => \Carbon\Carbon::create($year, $month, 1)->format('F Y')
            ],
            'total_collected' => $payments->where('status', 'completed')->sum('amount'),
            'total_pending' => $payments->where('status', 'pending')->sum('amount'),
            'collection_rate' => $payments->count() > 0 ? ($payments->where('status', 'completed')->count() / $payments->count()) * 100 : 0,
            'payment_methods' => $payments->groupBy('payment_method')->map(function ($methodPayments, $method) {
                return [
                    'payment_method' => $method,
                    'collected_amount' => $methodPayments->where('status', 'completed')->sum('amount'),
                    'count' => $methodPayments->where('status', 'completed')->count(),
                ];
            })->values(),
            'payments' => $payments
        ];

        return $summary;
    }
}

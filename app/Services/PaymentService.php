<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Mess;
use App\Models\User;
use App\Http\Resources\PaymentResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PaymentService
{
    /**
     * Create a new payment
     */
    public function createPayment(array $data): Payment
    {
        return DB::transaction(function () use ($data) {
            return Payment::create($data);
        });
    }

    /**
     * Approve a payment
     */
    public function approvePayment(Payment $payment, int $approvedBy): Payment
    {
        return DB::transaction(function () use ($payment, $approvedBy) {
            $payment->approve($approvedBy);
            return $payment->fresh();
        });
    }

    /**
     * Get payment statistics for a mess
     */
    public function getPaymentStatistics(int $messId, int $year, int $month, ?int $userId = null): array
    {
        $query = Payment::where('mess_id', $messId)
            ->whereYear('payment_date', $year)
            ->whereMonth('payment_date', $month);

        if ($userId) {
            $query->where('user_id', $userId);
        }

        $payments = $query->get();
        $totalPayments = $payments->count();

        return [
            'total_amount' => $payments->sum('amount'),
            'total_payments' => $totalPayments,
            'approved_amount' => $payments->where('status', 'completed')->sum('amount'),
            'approved_payments' => $payments->where('status', 'completed')->count(),
            'pending_amount' => $payments->where('status', 'pending')->sum('amount'),
            'pending_payments' => $payments->where('status', 'pending')->count(),
            'average_payment' => $totalPayments > 0 ? $payments->avg('amount') : 0,
            'payment_methods' => $payments->groupBy('payment_method')->map(function ($group) use ($totalPayments) {
                return [
                    'count' => $group->count(),
                    'amount' => $group->sum('amount'),
                    'percentage' => $totalPayments > 0 ? ($group->count() / $totalPayments) * 100 : 0
                ];
            }),
        ];
    }

    /**
     * Get payment collection report
     */
    public function getCollectionReport(int $messId, int $year, int $month, string $groupBy = 'none'): array
    {
        $query = Payment::where('mess_id', $messId)
            ->whereYear('payment_date', $year)
            ->whereMonth('payment_date', $month)
            ->with(['user']);

        switch ($groupBy) {
            case 'user':
                $report = $query->get()->groupBy('user_id')->map(function ($payments, $userId) {
                    $user = $payments->first()->user;
                    return [
                        'user' => [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                        ],
                        'total_amount' => $payments->sum('amount'),
                        'total_payments' => $payments->count(),
                        'approved_amount' => $payments->where('status', 'completed')->sum('amount'),
                        'approved_payments' => $payments->where('status', 'completed')->count(),
                        'pending_amount' => $payments->where('status', 'pending')->sum('amount'),
                        'pending_payments' => $payments->where('status', 'pending')->count(),
                        'payment_methods' => $payments->groupBy('payment_method')->map(function ($group) {
                            return [
                                'count' => $group->count(),
                                'amount' => $group->sum('amount'),
                            ];
                        }),
                    ];
                })->values();
                break;

            case 'payment_method':
                $report = $query->get()->groupBy('payment_method')->map(function ($payments, $method) {
                    return [
                        'payment_method' => $method,
                        'payment_method_display' => $payments->first()->payment_method_display,
                        'total_amount' => $payments->sum('amount'),
                        'total_payments' => $payments->count(),
                        'approved_amount' => $payments->where('status', 'completed')->sum('amount'),
                        'approved_payments' => $payments->where('status', 'completed')->count(),
                        'pending_amount' => $payments->where('status', 'pending')->sum('amount'),
                        'pending_payments' => $payments->where('status', 'pending')->count(),
                        'average_amount' => $payments->count() > 0 ? $payments->avg('amount') : 0,
                    ];
                })->values();
                break;

            default:
                $payments = $query->get();
                $report = [
                    'summary' => [
                        'total_amount' => $payments->sum('amount'),
                        'total_payments' => $payments->count(),
                        'approved_amount' => $payments->where('status', 'completed')->sum('amount'),
                        'approved_payments' => $payments->where('status', 'completed')->count(),
                        'pending_amount' => $payments->where('status', 'pending')->sum('amount'),
                        'pending_payments' => $payments->where('status', 'pending')->count(),
                        'average_amount' => $payments->count() > 0 ? $payments->avg('amount') : 0,
                    ],
                    'payments' => PaymentResource::collection($payments),
                ];
                break;
        }

        return [
            'mess_id' => $messId,
            'year' => $year,
            'month' => $month,
            'group_by' => $groupBy,
            'report' => $report,
        ];
    }

    /**
     * Get payment methods summary
     */
    public function getPaymentMethodsSummary(int $messId): array
    {
        $payments = Payment::where('mess_id', $messId)->get();
        $totalPayments = $payments->count();

        return [
            'total_payments' => $totalPayments,
            'total_amount' => $payments->sum('amount'),
            'methods' => $payments->groupBy('payment_method')->map(function ($group, $method) use ($totalPayments) {
                return [
                    'method' => $method,
                    'display_name' => $group->first()->payment_method_display,
                    'count' => $group->count(),
                    'amount' => $group->sum('amount'),
                    'percentage' => $totalPayments > 0 ? ($group->count() / $totalPayments) * 100 : 0,
                    'average_amount' => $group->count() > 0 ? $group->avg('amount') : 0,
                ];
            })->values()->sortByDesc('count')->values(),
        ];
    }

    /**
     * Get monthly payment trends
     */
    public function getMonthlyTrends(int $messId, int $months = 12): array
    {
        $trends = [];
        $startDate = now()->subMonths($months - 1)->startOfMonth();
        $endDate = now()->endOfMonth();

        $payments = Payment::where('mess_id', $messId)
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->get()
            ->groupBy(function ($payment) {
                return $payment->payment_date->format('Y-m');
            });

        for ($date = $startDate->copy(); $date <= $endDate; $date->addMonth()) {
            $monthKey = $date->format('Y-m');
            $monthPayments = $payments->get($monthKey, collect());

            $trends[] = [
                'month' => $date->format('Y-m'),
                'month_name' => $date->format('F Y'),
                'total_amount' => $monthPayments->sum('amount'),
                'total_payments' => $monthPayments->count(),
                'approved_amount' => $monthPayments->where('status', 'completed')->sum('amount'),
                'approved_payments' => $monthPayments->where('status', 'completed')->count(),
                'pending_amount' => $monthPayments->where('status', 'pending')->sum('amount'),
                'pending_payments' => $monthPayments->where('status', 'pending')->count(),
                'average_amount' => $monthPayments->count() > 0 ? $monthPayments->avg('amount') : 0,
            ];
        }

        return $trends;
    }

    /**
     * Get user payment history
     */
    public function getUserPaymentHistory(int $messId, ?int $userId = null, int $limit = 20): array
    {
        $query = Payment::where('mess_id', $messId)
            ->with(['user', 'approvedBy'])
            ->orderBy('payment_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return [
            'payments' => PaymentResource::collection($query->get()),
            'total_count' => Payment::where('mess_id', $messId)
                ->when($userId, function ($query, $userId) {
                    return $query->where('user_id', $userId);
                })
                ->count(),
        ];
    }

    /**
     * Get pending payments for approval
     */
    public function getPendingPayments(int $messId): array
    {
        $pendingPayments = Payment::where('mess_id', $messId)
            ->where('status', 'pending')
            ->with(['user'])
            ->orderBy('payment_date', 'asc')
            ->get();

        return [
            'payments' => PaymentResource::collection($pendingPayments),
            'total_amount' => $pendingPayments->sum('amount'),
            'total_count' => $pendingPayments->count(),
        ];
    }

    /**
     * Bulk approve payments
     */
    public function bulkApprovePayments(array $paymentIds, int $approvedBy): array
    {
        $results = [];
        $approvedCount = 0;
        $failedCount = 0;

        DB::transaction(function () use ($paymentIds, $approvedBy, &$results, &$approvedCount, &$failedCount) {
            foreach ($paymentIds as $paymentId) {
                try {
                    $payment = Payment::findOrFail($paymentId);

                    if ($payment->isPending()) {
                        $payment->approve($approvedBy);
                        $results[] = [
                            'payment_id' => $paymentId,
                            'status' => 'approved',
                            'message' => 'Payment approved successfully'
                        ];
                        $approvedCount++;
                    } else {
                        $results[] = [
                            'payment_id' => $paymentId,
                            'status' => 'skipped',
                            'message' => 'Payment is already approved'
                        ];
                    }
                } catch (\Exception $e) {
                    $results[] = [
                        'payment_id' => $paymentId,
                        'status' => 'failed',
                        'message' => 'Failed to approve payment: ' . $e->getMessage()
                    ];
                    $failedCount++;
                }
            }
        });

        return [
            'results' => $results,
            'approved_count' => $approvedCount,
            'failed_count' => $failedCount,
            'total_processed' => count($paymentIds),
        ];
    }

    /**
     * Delete payment with receipt cleanup
     */
    public function deletePayment(Payment $payment): bool
    {
        return DB::transaction(function () use ($payment) {
            // Delete receipt image if exists
            if ($payment->receipt_image) {
                Storage::disk('public')->delete($payment->receipt_image);
            }

            return $payment->delete();
        });
    }

    /**
     * Update payment receipt
     */
    public function updateReceipt(Payment $payment, string $receiptPath): Payment
    {
        return DB::transaction(function () use ($payment, $receiptPath) {
            // Delete old receipt if exists
            if ($payment->receipt_image) {
                Storage::disk('public')->delete($payment->receipt_image);
            }

            $payment->update(['receipt_image' => $receiptPath]);
            return $payment->fresh();
        });
    }

    /**
     * Get payment analytics for dashboard
     */
    public function getDashboardAnalytics(int $messId): array
    {
        $currentMonth = now()->month;
        $currentYear = now()->year;
        $previousMonth = now()->subMonth()->month;
        $previousYear = now()->subMonth()->year;

        // Current month payments
        $currentMonthPayments = Payment::where('mess_id', $messId)
            ->whereMonth('payment_date', $currentMonth)
            ->whereYear('payment_date', $currentYear)
            ->get();

        // Previous month payments
        $previousMonthPayments = Payment::where('mess_id', $messId)
            ->whereMonth('payment_date', $previousMonth)
            ->whereYear('payment_date', $previousYear)
            ->get();

        // Pending payments
        $pendingPayments = Payment::where('mess_id', $messId)
            ->where('status', 'pending')
            ->get();

        // Recent payments
        $recentPayments = Payment::where('mess_id', $messId)
            ->with(['user'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return [
            'current_month' => [
                'total_amount' => $currentMonthPayments->sum('amount'),
                'total_payments' => $currentMonthPayments->count(),
                'approved_amount' => $currentMonthPayments->where('status', 'completed')->sum('amount'),
                'approved_payments' => $currentMonthPayments->where('status', 'completed')->count(),
            ],
            'previous_month' => [
                'total_amount' => $previousMonthPayments->sum('amount'),
                'total_payments' => $previousMonthPayments->count(),
                'approved_amount' => $previousMonthPayments->where('status', 'completed')->sum('amount'),
                'approved_payments' => $previousMonthPayments->where('status', 'completed')->count(),
            ],
            'pending' => [
                'total_amount' => $pendingPayments->sum('amount'),
                'total_count' => $pendingPayments->count(),
            ],
            'recent_payments' => PaymentResource::collection($recentPayments),
            'growth_rate' => $this->calculateGrowthRate(
                $previousMonthPayments->sum('amount'),
                $currentMonthPayments->sum('amount')
            ),
        ];
    }

    /**
     * Calculate growth rate
     */
    private function calculateGrowthRate(float $previous, float $current): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return (($current - $previous) / $previous) * 100;
    }
}

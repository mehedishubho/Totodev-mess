<?php

namespace App\Services;

use App\Models\Mess;
use App\Models\User;
use App\Models\Meal;
use App\Models\Bazar;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\MessMember;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * Get admin dashboard data
     */
    public function getAdminDashboardData(User $user, ?int $messId, string $period, array $filters): array
    {
        $dateRange = $this->getDateRange($period, $filters);

        $query = Mess::query();

        if (!$user->hasRole('super_admin')) {
            $query->where('manager_id', $user->id);
        }

        if ($messId) {
            $query->where('id', $messId);
        }

        $messes = $query->withCount(['members' => function ($query) {
            $query->where('status', 'approved');
        }])
            ->with(['members' => function ($query) use ($dateRange) {
                $query->where('status', 'approved');
            }])
            ->get();

        $totalMesses = $messes->count();
        $totalMembers = $messes->sum('members_count');

        // Get aggregated statistics
        $mealStats = $this->getMealStats($messes->pluck('id'), $dateRange);
        $expenseStats = $this->getExpenseStats($messes->pluck('id'), $dateRange);
        $paymentStats = $this->getPaymentStats($messes->pluck('id'), $dateRange);

        return [
            'summary' => [
                'total_messes' => $totalMesses,
                'total_members' => $totalMembers,
                'active_members' => $totalMembers,
                'total_meals' => $mealStats['total_meals'],
                'total_expenses' => $expenseStats['total_amount'],
                'total_payments' => $paymentStats['total_amount'],
                'pending_payments' => $paymentStats['pending_amount'],
            ],
            'meal_statistics' => $mealStats,
            'expense_statistics' => $expenseStats,
            'payment_statistics' => $paymentStats,
            'recent_activities' => $this->getRecentActivities($messes->pluck('id'), $user),
            'messes_overview' => $messes->map(function ($mess) use ($dateRange) {
                return [
                    'id' => $mess->id,
                    'name' => $mess->name,
                    'members_count' => $mess->members_count,
                    'monthly_expenses' => $this->getMessMonthlyExpenses($mess->id),
                    'monthly_payments' => $this->getMessMonthlyPayments($mess->id),
                ];
            }),
            'period' => $period,
            'date_range' => $dateRange,
        ];
    }

    /**
     * Get member dashboard data
     */
    public function getMemberDashboardData(User $user, Mess $mess, string $period): array
    {
        $dateRange = $this->getDateRange($period);

        $member = $mess->members()->where('user_id', $user->id)->first();

        if (!$member) {
            return [];
        }

        $todayMeals = $this->getTodayMeals($user->id, $mess->id);
        $monthlyMeals = $this->getMonthlyMeals($user->id, $mess->id, $dateRange);
        $monthlyExpenses = $this->getUserMonthlyExpenses($user->id, $mess->id, $dateRange);
        $monthlyPayments = $this->getUserMonthlyPayments($user->id, $mess->id, $dateRange);
        $upcomingBazar = $this->getUpcomingBazar($user->id, $mess->id);
        $remainingDue = $this->calculateRemainingDue($user->id, $mess->id);

        return [
            'user_info' => [
                'name' => $user->name,
                'mess_name' => $mess->name,
                'member_since' => $member->created_at->format('Y-m-d'),
                'room_number' => $member->room_number,
            ],
            'today_summary' => [
                'breakfast' => $todayMeals['breakfast'] ?? 0,
                'lunch' => $todayMeals['lunch'] ?? 0,
                'dinner' => $todayMeals['dinner'] ?? 0,
                'total_meals' => $todayMeals['total'] ?? 0,
            ],
            'monthly_summary' => [
                'total_meals' => $monthlyMeals['total'],
                'meal_cost' => $monthlyMeals['cost'],
                'total_expenses' => $monthlyExpenses['total'],
                'total_payments' => $monthlyPayments['total'],
                'remaining_due' => $remainingDue,
            ],
            'upcoming_activities' => [
                'next_bazar_date' => $upcomingBazar['date'] ?? null,
                'next_bazar_items' => $upcomingBazar['items'] ?? [],
            ],
            'recent_activities' => $this->getUserRecentActivities($user->id, $mess->id),
            'period' => $period,
            'date_range' => $dateRange,
        ];
    }

    /**
     * Get mess overview
     */
    public function getMessOverview(Mess $mess, User $user): array
    {
        $currentMonth = now()->month;
        $currentYear = now()->year;

        $memberStats = $this->getMessMemberStats($mess->id);
        $mealStats = $this->getMessMealStats($mess->id, $currentMonth, $currentYear);
        $expenseStats = $this->getMessExpenseStats($mess->id, $currentMonth, $currentYear);
        $paymentStats = $this->getMessPaymentStats($mess->id, $currentMonth, $currentYear);

        return [
            'mess_info' => [
                'id' => $mess->id,
                'name' => $mess->name,
                'address' => $mess->address,
                'manager' => $mess->manager->name,
                'created_at' => $mess->created_at->format('Y-m-d'),
            ],
            'member_statistics' => $memberStats,
            'meal_statistics' => $mealStats,
            'expense_statistics' => $expenseStats,
            'payment_statistics' => $paymentStats,
            'monthly_trend' => $this->getMessMonthlyTrend($mess->id, 6),
            'recent_activities' => $this->getMessRecentActivities($mess->id, $user),
        ];
    }

    /**
     * Get financial summary
     */
    public function getFinancialSummary(Mess $mess, int $year, ?int $month, User $user): array
    {
        $dateRange = $month
            ? [Carbon::create($year, $month, 1)->startOfMonth(), Carbon::create($year, $month, 1)->endOfMonth()]
            : [Carbon::create($year, 1, 1)->startOfYear(), Carbon::create($year, 12, 31)->endOfYear()];

        $expenses = Expense::where('mess_id', $mess->id)
            ->whereBetween('expense_date', $dateRange)
            ->get();

        $payments = Payment::where('mess_id', $mess->id)
            ->whereBetween('payment_date', $dateRange)
            ->get();

        $meals = Meal::where('mess_id', $mess->id)
            ->whereBetween('meal_date', $dateRange)
            ->get();

        $totalExpenses = $expenses->sum('amount');
        $totalPayments = $payments->where('status', 'completed')->sum('amount');
        $totalMealCost = $meals->sum(function ($meal) {
            return ($meal->breakfast + $meal->lunch + $meal->dinner) * $meal->mess->breakfast_rate;
        });

        return [
            'period' => $month ? "{$year}-{$month}" : (string)$year,
            'summary' => [
                'total_expenses' => $totalExpenses,
                'total_payments' => $totalPayments,
                'total_meal_cost' => $totalMealCost,
                'net_balance' => $totalPayments - $totalExpenses,
                'pending_payments' => $payments->where('status', 'pending')->sum('amount'),
            ],
            'expense_breakdown' => $expenses->groupBy('category.name')->map(function ($categoryExpenses) {
                return [
                    'category' => $categoryExpenses->first()->category->name,
                    'total' => $categoryExpenses->sum('amount'),
                    'count' => $categoryExpenses->count(),
                ];
            })->values(),
            'payment_breakdown' => $payments->groupBy('payment_method')->map(function ($methodPayments) {
                return [
                    'method' => $methodPayments->first()->payment_method,
                    'total' => $methodPayments->sum('amount'),
                    'count' => $methodPayments->count(),
                ];
            })->values(),
            'monthly_breakdown' => $month ? [] : $this->getMonthlyFinancialBreakdown($mess->id, $year),
        ];
    }

    /**
     * Get meal statistics
     */
    public function getMealStatistics(Mess $mess, string $period, ?int $year, ?int $month, User $user): array
    {
        $dateRange = $this->getDateRange($period, compact('year', 'month'));

        $mealsQuery = Meal::where('mess_id', $mess->id)
            ->whereBetween('meal_date', $dateRange);

        // If user is not manager, only show their meals
        if (!$user->hasRole('super_admin') && $mess->manager_id !== $user->id) {
            $mealsQuery->where('user_id', $user->id);
        }

        $meals = $mealsQuery->get();

        return [
            'period' => $period,
            'summary' => [
                'total_meals' => $meals->count(),
                'total_breakfast' => $meals->sum('breakfast'),
                'total_lunch' => $meals->sum('lunch'),
                'total_dinner' => $meals->sum('dinner'),
                'average_daily_meals' => $meals->count() > 0 ? ($meals->sum('breakfast') + $meals->sum('lunch') + $meals->sum('dinner')) / $meals->count() : 0,
            ],
            'daily_breakdown' => $meals->groupBy(function ($meal) {
                return $meal->meal_date->format('Y-m-d');
            })->map(function ($dayMeals) {
                return [
                    'date' => $dayMeals->first()->meal_date->format('Y-m-d'),
                    'total_breakfast' => $dayMeals->sum('breakfast'),
                    'total_lunch' => $dayMeals->sum('lunch'),
                    'total_dinner' => $dayMeals->sum('dinner'),
                    'member_count' => $dayMeals->count(),
                ];
            })->values(),
            'member_breakdown' => $user->hasRole('super_admin') || $mess->manager_id === $user->id
                ? $this->getMemberMealBreakdown($mess->id, $dateRange)
                : [],
        ];
    }

    /**
     * Get expense analytics
     */
    public function getExpenseAnalytics(Mess $mess, string $period, ?int $year, ?int $month, User $user): array
    {
        $dateRange = $this->getDateRange($period, compact('year', 'month'));

        $expensesQuery = Expense::where('mess_id', $mess->id)
            ->whereBetween('expense_date', $dateRange)
            ->with('category');

        // If user is not manager, only show their expenses
        if (!$user->hasRole('super_admin') && $mess->manager_id !== $user->id) {
            $expensesQuery->where('user_id', $user->id);
        }

        $expenses = $expensesQuery->get();

        return [
            'period' => $period,
            'summary' => [
                'total_expenses' => $expenses->count(),
                'total_amount' => $expenses->sum('amount'),
                'average_amount' => $expenses->count() > 0 ? $expenses->avg('amount') : 0,
                'approved_amount' => $expenses->where('status', 'approved')->sum('amount'),
                'pending_amount' => $expenses->where('status', 'pending')->sum('amount'),
            ],
            'category_breakdown' => $expenses->groupBy('category.name')->map(function ($categoryExpenses) {
                return [
                    'category' => $categoryExpenses->first()->category->name,
                    'total' => $categoryExpenses->sum('amount'),
                    'count' => $categoryExpenses->count(),
                    'average' => $categoryExpenses->avg('amount'),
                ];
            })->values(),
            'daily_breakdown' => $expenses->groupBy(function ($expense) {
                return $expense->expense_date->format('Y-m-d');
            })->map(function ($dayExpenses) {
                return [
                    'date' => $dayExpenses->first()->expense_date->format('Y-m-d'),
                    'total_amount' => $dayExpenses->sum('amount'),
                    'count' => $dayExpenses->count(),
                ];
            })->values(),
        ];
    }

    /**
     * Get payment analytics
     */
    public function getPaymentAnalytics(Mess $mess, string $period, ?int $year, ?int $month, User $user): array
    {
        $dateRange = $this->getDateRange($period, compact('year', 'month'));

        $paymentsQuery = Payment::where('mess_id', $mess->id)
            ->whereBetween('payment_date', $dateRange);

        // If user is not manager, only show their payments
        if (!$user->hasRole('super_admin') && $mess->manager_id !== $user->id) {
            $paymentsQuery->where('user_id', $user->id);
        }

        $payments = $paymentsQuery->get();

        return [
            'period' => $period,
            'summary' => [
                'total_payments' => $payments->count(),
                'total_amount' => $payments->sum('amount'),
                'average_amount' => $payments->count() > 0 ? $payments->avg('amount') : 0,
                'completed_amount' => $payments->where('status', 'completed')->sum('amount'),
                'pending_amount' => $payments->where('status', 'pending')->sum('amount'),
            ],
            'method_breakdown' => $payments->groupBy('payment_method')->map(function ($methodPayments) {
                return [
                    'method' => $methodPayments->first()->payment_method,
                    'total' => $methodPayments->sum('amount'),
                    'count' => $methodPayments->count(),
                    'average' => $methodPayments->avg('amount'),
                ];
            })->values(),
            'daily_breakdown' => $payments->groupBy(function ($payment) {
                return $payment->payment_date->format('Y-m-d');
            })->map(function ($dayPayments) {
                return [
                    'date' => $dayPayments->first()->payment_date->format('Y-m-d'),
                    'total_amount' => $dayPayments->sum('amount'),
                    'count' => $dayPayments->count(),
                ];
            })->values(),
        ];
    }

    /**
     * Get member activity
     */
    public function getMemberActivity(Mess $mess, int $userId, string $period, User $user): array
    {
        $dateRange = $this->getDateRange($period);

        $member = User::findOrFail($userId);

        $meals = Meal::where('mess_id', $mess->id)
            ->where('user_id', $userId)
            ->whereBetween('meal_date', $dateRange)
            ->get();

        $expenses = Expense::where('mess_id', $mess->id)
            ->where('user_id', $userId)
            ->whereBetween('expense_date', $dateRange)
            ->get();

        $payments = Payment::where('mess_id', $mess->id)
            ->where('user_id', $userId)
            ->whereBetween('payment_date', $dateRange)
            ->get();

        return [
            'member_info' => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
            ],
            'period' => $period,
            'summary' => [
                'total_meals' => $meals->count(),
                'total_expenses' => $expenses->count(),
                'total_payments' => $payments->count(),
                'total_expense_amount' => $expenses->sum('amount'),
                'total_payment_amount' => $payments->sum('amount'),
            ],
            'meal_activity' => $meals->map(function ($meal) {
                return [
                    'date' => $meal->meal_date->format('Y-m-d'),
                    'breakfast' => $meal->breakfast,
                    'lunch' => $meal->lunch,
                    'dinner' => $meal->dinner,
                    'extra_items' => $meal->extra_items,
                ];
            }),
            'expense_activity' => $expenses->map(function ($expense) {
                return [
                    'date' => $expense->expense_date->format('Y-m-d'),
                    'amount' => $expense->amount,
                    'category' => $expense->category->name,
                    'description' => $expense->description,
                    'status' => $expense->status,
                ];
            }),
            'payment_activity' => $payments->map(function ($payment) {
                return [
                    'date' => $payment->payment_date->format('Y-m-d'),
                    'amount' => $payment->amount,
                    'method' => $payment->payment_method,
                    'status' => $payment->status,
                ];
            }),
        ];
    }

    /**
     * Get system statistics (Super Admin only)
     */
    public function getSystemStatistics(): array
    {
        return [
            'overview' => [
                'total_messes' => Mess::count(),
                'total_users' => User::count(),
                'total_members' => MessMember::where('status', 'approved')->count(),
                'active_messes' => Mess::whereHas('members', function ($query) {
                    $query->where('status', 'approved');
                })->count(),
            ],
            'monthly_statistics' => $this->getSystemMonthlyStats(),
            'top_messes' => $this->getTopMesses(),
            'recent_registrations' => $this->getRecentRegistrations(),
        ];
    }

    /**
     * Get quick stats for dashboard widgets
     */
    public function getQuickStats(User $user, ?int $messId): array
    {
        $currentMonth = now()->month;
        $currentYear = now()->year;

        if ($user->hasRole('super_admin')) {
            return $this->getSuperAdminQuickStats($currentMonth, $currentYear);
        }

        if ($messId) {
            $mess = Mess::findOrFail($messId);
            if ($mess->manager_id === $user->id) {
                return $this->getManagerQuickStats($mess, $currentMonth, $currentYear);
            }
        }

        return $this->getMemberQuickStats($user, $currentMonth, $currentYear);
    }

    /**
     * Get trends and comparisons
     */
    public function getTrends(Mess $mess, string $metric, string $period, string $compareWith, User $user): array
    {
        $currentPeriod = $this->getPeriodData($mess, $metric, $period);
        $previousPeriod = $this->getComparisonData($mess, $metric, $period, $compareWith);

        return [
            'metric' => $metric,
            'period' => $period,
            'current_period' => $currentPeriod,
            'previous_period' => $previousPeriod,
            'comparison' => [
                'change_percentage' => $this->calculateChangePercentage($currentPeriod, $previousPeriod),
                'trend' => $this->determineTrend($currentPeriod, $previousPeriod),
            ],
            'chart_data' => $this->getChartData($mess, $metric, $period),
        ];
    }

    // Helper methods
    private function getDateRange(string $period, array $filters = []): array
    {
        $now = now();

        switch ($period) {
            case 'today':
                return [$now->startOfDay(), $now->endOfDay()];
            case 'week':
                return [$now->startOfWeek(), $now->endOfWeek()];
            case 'month':
                return [$now->startOfMonth(), $now->endOfMonth()];
            case 'year':
                return [$now->startOfYear(), $now->endOfYear()];
            default:
                if (isset($filters['date_from']) && isset($filters['date_to'])) {
                    return [
                        Carbon::parse($filters['date_from'])->startOfDay(),
                        Carbon::parse($filters['date_to'])->endOfDay()
                    ];
                }
                return [$now->startOfMonth(), $now->endOfMonth()];
        }
    }

    private function getMealStats($messIds, $dateRange): array
    {
        $meals = Meal::whereIn('mess_id', $messIds)
            ->whereBetween('meal_date', $dateRange)
            ->get();

        return [
            'total_meals' => $meals->count(),
            'total_breakfast' => $meals->sum('breakfast'),
            'total_lunch' => $meals->sum('lunch'),
            'total_dinner' => $meals->sum('dinner'),
        ];
    }

    private function getExpenseStats($messIds, $dateRange): array
    {
        $expenses = Expense::whereIn('mess_id', $messIds)
            ->whereBetween('expense_date', $dateRange)
            ->get();

        return [
            'total_amount' => $expenses->sum('amount'),
            'total_count' => $expenses->count(),
            'approved_amount' => $expenses->where('status', 'approved')->sum('amount'),
            'pending_amount' => $expenses->where('status', 'pending')->sum('amount'),
        ];
    }

    private function getPaymentStats($messIds, $dateRange): array
    {
        $payments = Payment::whereIn('mess_id', $messIds)
            ->whereBetween('payment_date', $dateRange)
            ->get();

        return [
            'total_amount' => $payments->sum('amount'),
            'total_count' => $payments->count(),
            'completed_amount' => $payments->where('status', 'completed')->sum('amount'),
            'pending_amount' => $payments->where('status', 'pending')->sum('amount'),
        ];
    }

    private function getRecentActivities($messIds, $user)
    {
        // Implementation would fetch recent activities across all messes
        return [];
    }

    private function getMessMonthlyExpenses($messId)
    {
        return Expense::where('mess_id', $messId)
            ->whereMonth('expense_date', now()->month)
            ->whereYear('expense_date', now()->year)
            ->sum('amount');
    }

    private function getMessMonthlyPayments($messId)
    {
        return Payment::where('mess_id', $messId)
            ->whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year)
            ->where('status', 'completed')
            ->sum('amount');
    }

    private function getTodayMeals($userId, $messId)
    {
        $today = now()->format('Y-m-d');
        $meal = Meal::where('user_id', $userId)
            ->where('mess_id', $messId)
            ->where('meal_date', $today)
            ->first();

        return $meal ? [
            'breakfast' => $meal->breakfast,
            'lunch' => $meal->lunch,
            'dinner' => $meal->dinner,
            'total' => $meal->breakfast + $meal->lunch + $meal->dinner,
        ] : ['breakfast' => 0, 'lunch' => 0, 'dinner' => 0, 'total' => 0];
    }

    private function getMonthlyMeals($userId, $messId, $dateRange)
    {
        $meals = Meal::where('user_id', $userId)
            ->where('mess_id', $messId)
            ->whereBetween('meal_date', $dateRange)
            ->get();

        $totalMeals = $meals->sum(function ($meal) {
            return $meal->breakfast + $meal->lunch + $meal->dinner;
        });

        return [
            'total' => $totalMeals,
            'cost' => $totalMeals * 50, // Default rate, should get from mess
        ];
    }

    private function getUserMonthlyExpenses($userId, $messId, $dateRange)
    {
        $expenses = Expense::where('user_id', $userId)
            ->where('mess_id', $messId)
            ->whereBetween('expense_date', $dateRange)
            ->get();

        return [
            'total' => $expenses->sum('amount'),
            'count' => $expenses->count(),
        ];
    }

    private function getUserMonthlyPayments($userId, $messId, $dateRange)
    {
        $payments = Payment::where('user_id', $userId)
            ->where('mess_id', $messId)
            ->whereBetween('payment_date', $dateRange)
            ->where('status', 'completed')
            ->get();

        return [
            'total' => $payments->sum('amount'),
            'count' => $payments->count(),
        ];
    }

    private function getUpcomingBazar($userId, $messId)
    {
        $bazar = Bazar::where('mess_id', $messId)
            ->where('bazar_date', '>=', now())
            ->where('user_id', $userId)
            ->orderBy('bazar_date', 'asc')
            ->first();

        return $bazar ? [
            'date' => $bazar->bazar_date->format('Y-m-d'),
            'items' => $bazar->item_list,
        ] : ['date' => null, 'items' => []];
    }

    private function calculateRemainingDue($userId, $messId)
    {
        $monthlyExpenses = Expense::where('user_id', $userId)
            ->where('mess_id', $messId)
            ->whereMonth('expense_date', now()->month)
            ->whereYear('expense_date', now()->year)
            ->sum('amount');

        $monthlyPayments = Payment::where('user_id', $userId)
            ->where('mess_id', $messId)
            ->whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year)
            ->where('status', 'completed')
            ->sum('amount');

        return max(0, $monthlyExpenses - $monthlyPayments);
    }

    private function getUserRecentActivities($userId, $messId)
    {
        // Implementation would fetch recent activities for user
        return [];
    }

    private function getMessMemberStats($messId)
    {
        $members = MessMember::where('mess_id', $messId)
            ->where('status', 'approved')
            ->get();

        return [
            'total_members' => $members->count(),
            'active_members' => $members->count(),
            'new_members_this_month' => $members->where('created_at', '>=', now()->startOfMonth())->count(),
        ];
    }

    private function getMessMealStats($messId, $month, $year)
    {
        $meals = Meal::where('mess_id', $messId)
            ->whereMonth('meal_date', $month)
            ->whereYear('meal_date', $year)
            ->get();

        return [
            'total_meals' => $meals->count(),
            'total_breakfast' => $meals->sum('breakfast'),
            'total_lunch' => $meals->sum('lunch'),
            'total_dinner' => $meals->sum('dinner'),
        ];
    }

    private function getMessExpenseStats($messId, $month, $year)
    {
        $expenses = Expense::where('mess_id', $messId)
            ->whereMonth('expense_date', $month)
            ->whereYear('expense_date', $year)
            ->get();

        return [
            'total_expenses' => $expenses->count(),
            'total_amount' => $expenses->sum('amount'),
            'approved_amount' => $expenses->where('status', 'approved')->sum('amount'),
        ];
    }

    private function getMessPaymentStats($messId, $month, $year)
    {
        $payments = Payment::where('mess_id', $messId)
            ->whereMonth('payment_date', $month)
            ->whereYear('payment_date', $year)
            ->get();

        return [
            'total_payments' => $payments->count(),
            'total_amount' => $payments->sum('amount'),
            'completed_amount' => $payments->where('status', 'completed')->sum('amount'),
        ];
    }

    private function getMessMonthlyTrend($messId, $months)
    {
        $trend = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthExpenses = Expense::where('mess_id', $messId)
                ->whereMonth('expense_date', $date->month)
                ->whereYear('expense_date', $date->year)
                ->sum('amount');

            $monthPayments = Payment::where('mess_id', $messId)
                ->whereMonth('payment_date', $date->month)
                ->whereYear('payment_date', $date->year)
                ->where('status', 'completed')
                ->sum('amount');

            $trend[] = [
                'month' => $date->format('Y-m'),
                'month_name' => $date->format('F Y'),
                'expenses' => $monthExpenses,
                'payments' => $monthPayments,
            ];
        }

        return $trend;
    }

    private function getMessRecentActivities($messId, $user)
    {
        // Implementation would fetch recent activities for mess
        return [];
    }

    private function getMonthlyFinancialBreakdown($messId, $year)
    {
        $breakdown = [];
        for ($month = 1; $month <= 12; $month++) {
            $expenses = Expense::where('mess_id', $messId)
                ->whereMonth('expense_date', $month)
                ->whereYear('expense_date', $year)
                ->sum('amount');

            $payments = Payment::where('mess_id', $messId)
                ->whereMonth('payment_date', $month)
                ->whereYear('payment_date', $year)
                ->where('status', 'completed')
                ->sum('amount');

            $breakdown[] = [
                'month' => $month,
                'month_name' => Carbon::create($year, $month, 1)->format('F'),
                'expenses' => $expenses,
                'payments' => $payments,
                'balance' => $payments - $expenses,
            ];
        }

        return $breakdown;
    }

    private function getMemberMealBreakdown($messId, $dateRange)
    {
        $meals = Meal::where('mess_id', $messId)
            ->whereBetween('meal_date', $dateRange)
            ->with('user')
            ->get()
            ->groupBy('user_id');

        return $meals->map(function ($userMeals) {
            $user = $userMeals->first()->user;
            $totalMeals = $userMeals->sum(function ($meal) {
                return $meal->breakfast + $meal->lunch + $meal->dinner;
            });

            return [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'total_meals' => $totalMeals,
                'breakfast_count' => $userMeals->sum('breakfast'),
                'lunch_count' => $userMeals->sum('lunch'),
                'dinner_count' => $userMeals->sum('dinner'),
            ];
        })->values();
    }

    private function getSystemMonthlyStats()
    {
        // Implementation would return system-wide monthly statistics
        return [];
    }

    private function getTopMesses()
    {
        // Implementation would return top performing messes
        return [];
    }

    private function getRecentRegistrations()
    {
        // Implementation would return recent user registrations
        return [];
    }

    private function getSuperAdminQuickStats($month, $year)
    {
        return [
            'total_messes' => Mess::count(),
            'total_users' => User::count(),
            'total_members' => MessMember::where('status', 'approved')->count(),
            'monthly_expenses' => Expense::whereMonth('expense_date', $month)
                ->whereYear('expense_date', $year)
                ->sum('amount'),
        ];
    }

    private function getManagerQuickStats($mess, $month, $year)
    {
        return [
            'total_members' => $mess->members()->where('status', 'approved')->count(),
            'monthly_expenses' => Expense::where('mess_id', $mess->id)
                ->whereMonth('expense_date', $month)
                ->whereYear('expense_date', $year)
                ->sum('amount'),
            'monthly_payments' => Payment::where('mess_id', $mess->id)
                ->whereMonth('payment_date', $month)
                ->whereYear('payment_date', $year)
                ->where('status', 'completed')
                ->sum('amount'),
        ];
    }

    private function getMemberQuickStats($user, $month, $year)
    {
        return [
            'monthly_meals' => Meal::where('user_id', $user->id)
                ->whereMonth('meal_date', $month)
                ->whereYear('meal_date', $year)
                ->count(),
            'monthly_expenses' => Expense::where('user_id', $user->id)
                ->whereMonth('expense_date', $month)
                ->whereYear('expense_date', $year)
                ->sum('amount'),
            'monthly_payments' => Payment::where('user_id', $user->id)
                ->whereMonth('payment_date', $month)
                ->whereYear('payment_date', $year)
                ->where('status', 'completed')
                ->sum('amount'),
        ];
    }

    private function getPeriodData($mess, $metric, $period)
    {
        // Implementation would return current period data for metric
        return ['total' => 0, 'count' => 0];
    }

    private function getComparisonData($mess, $metric, $period, $compareWith)
    {
        // Implementation would return comparison period data
        return ['total' => 0, 'count' => 0];
    }

    private function calculateChangePercentage($current, $previous)
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return (($current['total'] - $previous['total']) / $previous['total']) * 100;
    }

    private function determineTrend($current, $previous)
    {
        $change = $current['total'] - $previous['total'];

        if ($change > 0) {
            return 'up';
        }
        if ($change < 0) {
            return 'down';
        }
        return 'stable';
    }

    private function getChartData($mess, $metric, $period)
    {
        // Implementation would return chart-ready data
        return [];
    }
}

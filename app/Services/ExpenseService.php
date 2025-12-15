<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Mess;
use App\Models\MessMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class ExpenseService
{
    /**
     * Create expense with validation and receipt handling.
     */
    public function createExpense(array $data, $createdBy)
    {
        try {
            DB::beginTransaction();

            // Handle receipt upload
            $receiptPath = null;
            if (isset($data['receipt_image']) && $data['receipt_image'] instanceof \Illuminate\Http\UploadedFile) {
                $receiptPath = $data['receipt_image']->store('expense_receipts', 'public');
            }

            $expense = Expense::create([
                'mess_id' => $data['mess_id'],
                'category_id' => $data['category_id'],
                'user_id' => $data['user_id'],
                'expense_date' => $data['expense_date'],
                'description' => $data['description'],
                'amount' => $data['amount'],
                'receipt_image' => $receiptPath,
                'notes' => $data['notes'] ?? null,
                'created_by' => $createdBy
            ]);

            DB::commit();

            return $expense;
        } catch (\Exception $e) {
            DB::rollBack();

            // Remove uploaded receipt if transaction failed
            if (isset($receiptPath)) {
                Storage::disk('public')->delete($receiptPath);
            }

            throw new \Exception('Failed to create expense: ' . $e->getMessage());
        }
    }

    /**
     * Update expense with validation.
     */
    public function updateExpense(Expense $expense, array $data, $updatedBy)
    {
        try {
            DB::beginTransaction();

            // Don't allow updating approved expenses (only managers can)
            if ($expense->isApproved() && !auth()->user()->hasRole(['super_admin', 'admin'])) {
                throw new \Exception('Cannot update approved expense');
            }

            $expense->update($data);

            DB::commit();

            return $expense->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('Failed to update expense: ' . $e->getMessage());
        }
    }

    /**
     * Delete expense with cleanup.
     */
    public function deleteExpense(Expense $expense, $deletedBy)
    {
        try {
            DB::beginTransaction();

            // Don't allow deleting approved expenses (only managers can)
            if ($expense->isApproved() && !auth()->user()->hasRole(['super_admin', 'admin'])) {
                throw new \Exception('Cannot delete approved expense');
            }

            // Delete receipt image if exists
            if ($expense->receipt_image) {
                Storage::disk('public')->delete($expense->receipt_image);
            }

            $expense->delete();

            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('Failed to delete expense: ' . $e->getMessage());
        }
    }

    /**
     * Approve expense.
     */
    public function approveExpense(Expense $expense, $approvedBy)
    {
        try {
            DB::beginTransaction();

            if ($expense->isApproved()) {
                throw new \Exception('Expense is already approved');
            }

            $expense->approve($approvedBy);

            DB::commit();

            return $expense->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('Failed to approve expense: ' . $e->getMessage());
        }
    }

    /**
     * Upload receipt for expense.
     */
    public function uploadReceipt(Expense $expense, $receiptImage)
    {
        try {
            DB::beginTransaction();

            // Delete old receipt if exists
            if ($expense->receipt_image) {
                Storage::disk('public')->delete($expense->receipt_image);
            }

            // Upload new receipt
            $receiptPath = $receiptImage->store('expense_receipts', 'public');

            $expense->update(['receipt_image' => $receiptPath]);

            DB::commit();

            return [
                'receipt_url' => $expense->receipt_url
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('Failed to upload receipt: ' . $e->getMessage());
        }
    }

    /**
     * Generate monthly expense report.
     */
    public function generateMonthlyReport($messId, $year, $month, $groupBy = 'category')
    {
        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $expenses = Expense::where('mess_id', $messId)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->with(['category', 'user', 'approvedBy'])
            ->orderBy('expense_date')
            ->get();

        $mess = Mess::findOrFail($messId);

        $data = [];

        if ($groupBy === 'category') {
            $data = $expenses->groupBy('category_id')->map(function ($categoryExpenses, $categoryId) {
                $category = $categoryExpenses->first()->category;
                return [
                    'category' => $category ? [
                        'id' => $category->id,
                        'name' => $category->name,
                        'description' => $category->description,
                        'color' => $category->color,
                        'icon' => $category->icon,
                    ] : null,
                    'total_amount' => $categoryExpenses->sum('amount'),
                    'count' => $categoryExpenses->count(),
                    'expenses' => $categoryExpenses
                ];
            })->values();
        } elseif ($groupBy === 'user') {
            $data = $expenses->groupBy('user_id')->map(function ($userExpenses, $userId) {
                $user = $userExpenses->first()->user;
                return [
                    'user' => [
                        'id' => $userId,
                        'name' => $user->name,
                        'email' => $user->email
                    ],
                    'total_amount' => $userExpenses->sum('amount'),
                    'count' => $userExpenses->count(),
                    'expenses' => $userExpenses
                ];
            })->values();
        } elseif ($groupBy === 'date') {
            $data = $expenses->groupBy(function ($expense) {
                return $expense->expense_date->format('Y-m-d');
            })->map(function ($dateExpenses, $date) {
                return [
                    'date' => $date,
                    'formatted_date' => Carbon::parse($date)->format('M d, Y'),
                    'total_amount' => $dateExpenses->sum('amount'),
                    'count' => $dateExpenses->count(),
                    'expenses' => $dateExpenses
                ];
            })->values();
        } else {
            $data = [
                'total_amount' => $expenses->sum('amount'),
                'count' => $expenses->count(),
                'approved_expenses' => $expenses->whereNotNull('approved_at')->count(),
                'pending_expenses' => $expenses->whereNull('approved_at')->count(),
                'expenses' => $expenses
            ];
        }

        return [
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'month_name' => $startDate->format('F Y'),
                'group_by' => $groupBy
            ],
            'mess' => [
                'id' => $mess->id,
                'name' => $mess->name,
            ],
            'summary' => [
                'total_expenses' => $expenses->count(),
                'total_amount' => $expenses->sum('amount'),
                'average_amount' => $expenses->count() > 0 ? $expenses->sum('amount') / $expenses->count() : 0,
                'approved_expenses' => $expenses->whereNotNull('approved_at')->count(),
                'pending_expenses' => $expenses->whereNull('approved_at')->count(),
            ],
            'data' => $data
        ];
    }

    /**
     * Get expense statistics for user in a month.
     */
    public function getUserExpenseStatistics($messId, $userId, $year, $month)
    {
        return Expense::getUserExpenseStatistics($messId, $userId, $year, $month);
    }

    /**
     * Get expense statistics for mess in a month.
     */
    public function getMessExpenseStatistics($messId, $year, $month)
    {
        return Expense::getMessExpenseStatistics($messId, $year, $month);
    }

    /**
     * Get expense trend for last 6 months.
     */
    public function getExpenseTrend($messId)
    {
        return Expense::getExpenseTrend($messId);
    }

    /**
     * Calculate expense comparison between users.
     */
    public function calculateExpenseComparison($messId, $year, $month)
    {
        $expenses = Expense::where('mess_id', $messId)
            ->forMonth($year, $month)
            ->with(['category', 'user'])
            ->get();

        return $expenses->groupBy('user_id')->map(function ($userExpenses, $userId) {
            $user = $userExpenses->first()->user;
            return [
                'user' => [
                    'id' => $userId,
                    'name' => $user->name,
                    'email' => $user->email
                ],
                'total_amount' => $userExpenses->sum('amount'),
                'count' => $userExpenses->count(),
                'average_amount' => $userExpenses->sum('amount') / $userExpenses->count(),
                'highest_expense' => $userExpenses->max('amount'),
                'lowest_expense' => $userExpenses->min('amount'),
            ];
        })->sortBy('total_amount')->values();
    }

    /**
     * Get expense performance metrics.
     */
    public function getExpensePerformanceMetrics($messId, $year, $month)
    {
        $expenses = Expense::where('mess_id', $messId)
            ->forMonth($year, $month)
            ->get();

        $totalExpenses = $expenses->count();
        $approvedExpenses = $expenses->whereNotNull('approved_at')->count();
        $pendingExpenses = $expenses->whereNull('approved_at')->count();
        $totalAmount = $expenses->sum('amount');
        $averageAmount = $totalExpenses > 0 ? $totalAmount / $totalExpenses : 0;

        return [
            'total_expenses' => $totalExpenses,
            'approval_rate' => $totalExpenses > 0 ? ($approvedExpenses / $totalExpenses) * 100 : 0,
            'pending_rate' => $totalExpenses > 0 ? ($pendingExpenses / $totalExpenses) * 100 : 0,
            'total_amount' => $totalAmount,
            'average_amount_per_expense' => $averageAmount,
            'highest_expense' => $expenses->max('amount'),
            'lowest_expense' => $expenses->min('amount'),
            'cost_variance' => $totalExpenses > 1 ? $expenses->stdDev('amount') : 0,
        ];
    }

    /**
     * Calculate monthly bill including expenses.
     */
    public function calculateMonthlyBill($messId, $userId, $year, $month)
    {
        // Get meal costs for the user
        $mealCosts = \App\Models\Meal::getUserMealStatistics($messId, $userId, $year, $month);
        $totalMealCost = $mealCosts['total_meal_cost'] ?? 0;

        // Get expenses for the user
        $expenseAmount = \App\Models\Expense::where('mess_id', $messId)
            ->where('user_id', $userId)
            ->whereMonth('expense_date', $year, $month)
            ->whereNotNull('approved_at')
            ->sum('amount');

        $totalBill = $totalMealCost + $expenseAmount;

        return [
            'period' => [
                'year' => $year,
                'month' => $month,
                'month_name' => Carbon::create($year, $month, 1)->format('F Y')
            ],
            'meal_costs' => $mealCosts,
            'expense_amount' => $expenseAmount,
            'total_bill' => $totalBill,
            'breakdown' => [
                'meals' => $totalMealCost,
                'expenses' => $expenseAmount,
            ]
        ];
    }

    /**
     * Get expense categories for a mess.
     */
    public function getExpenseCategories($messId)
    {
        return \App\Models\ExpenseCategory::where('mess_id', $messId)
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * Create default expense categories for a mess.
     */
    public function createDefaultExpenseCategories($messId)
    {
        $defaultCategories = [
            ['name' => 'Groceries', 'description' => 'Daily food items and groceries', 'color' => '#10B981', 'icon' => 'fas fa-shopping-cart'],
            ['name' => 'Utilities', 'description' => 'Electricity, water, gas, internet bills', 'color' => '#F59E0B', 'icon' => 'fas fa-bolt'],
            ['name' => 'Rent', 'description' => 'Mess rent and maintenance', 'color' => '#EF4444', 'icon' => 'fas fa-home'],
            ['name' => 'Transportation', 'description' => 'Travel and commuting expenses', 'color' => '#3B82F6', 'icon' => 'fas fa-car'],
            ['name' => 'Medical', 'description' => 'Healthcare and medical expenses', 'color' => '#DC3545', 'icon' => 'fas fa-heartbeat'],
            ['name' => 'Entertainment', 'description' => 'Recreation and entertainment', 'color' => '#8E5BE', 'icon' => 'fas fa-film'],
            ['name' => 'Other', 'description' => 'Miscellaneous expenses', 'color' => '#6C757D', 'icon' => 'fas fa-ellipsis-h'],
        ];

        foreach ($defaultCategories as $category) {
            \App\Models\ExpenseCategory::create([
                'mess_id' => $messId,
                'name' => $category['name'],
                'description' => $category['description'],
                'color' => $category['color'],
                'icon' => $category['icon'],
                'is_default' => true,
                'is_active' => true
            ]);
        }

        return count($defaultCategories);
    }
}

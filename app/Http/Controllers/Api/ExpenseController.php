<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Mess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    /**
     * Display a listing of expenses.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'category_id' => 'nullable|exists:expense_categories,id',
            'user_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:approved,pending,all',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            !$mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = $mess->expenses()->with(['category', 'user', 'approvedBy']);

        // Filter by date range
        if (isset($validated['date_from'])) {
            $query->whereDate('expense_date', '>=', $validated['date_from']);
        }
        if (isset($validated['date_to'])) {
            $query->whereDate('expense_date', '<=', $validated['date_to']);
        }

        // Filter by category
        if (isset($validated['category_id'])) {
            $query->where('category_id', $validated['category_id']);
        }

        // Filter by user
        if (isset($validated['user_id'])) {
            // Only allow filtering by user if authorized
            if (
                !$user->hasRole('super_admin') &&
                $mess->manager_id !== $user->id &&
                $validated['user_id'] !== $user->id
            ) {
                return response()->json(['message' => 'Unauthorized to filter by user'], 403);
            }
            $query->where('user_id', $validated['user_id']);
        }

        // Filter by status
        if (isset($validated['status'])) {
            if ($validated['status'] === 'approved') {
                $query->approved();
            } elseif ($validated['status'] === 'pending') {
                $query->pending();
            }
        }

        $expenses = $query->orderBy('expense_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => $expenses
        ]);
    }

    /**
     * Store a newly created expense.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'category_id' => 'required|exists:expense_categories,id',
            'user_id' => 'required|exists:users,id',
            'expense_date' => 'required|date|before_or_equal:today',
            'description' => 'required|string|max:1000',
            'amount' => 'required|numeric|min:0.01',
            'receipt_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'notes' => 'nullable|string|max:1000'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            $validated['user_id'] !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check if user is a member of the mess
        if (!$mess->members()->where('user_id', $validated['user_id'])->where('status', 'approved')->exists()) {
            return response()->json(['message' => 'User is not an active member of this mess'], 400);
        }

        try {
            DB::beginTransaction();

            // Handle receipt upload
            $receiptPath = null;
            if ($request->hasFile('receipt_image')) {
                $receiptPath = $request->file('receipt_image')->store('expense_receipts', 'public');
            }

            $expense = Expense::create([
                'mess_id' => $validated['mess_id'],
                'category_id' => $validated['category_id'],
                'user_id' => $validated['user_id'],
                'expense_date' => $validated['expense_date'],
                'description' => $validated['description'],
                'amount' => $validated['amount'],
                'receipt_image' => $receiptPath,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Expense created successfully',
                'data' => $expense->load(['category', 'user', 'createdBy'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            // Remove uploaded receipt if transaction failed
            if ($receiptPath) {
                Storage::disk('public')->delete($receiptPath);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to create expense: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified expense.
     */
    public function show($id)
    {
        $user = Auth::user();
        $expense = Expense::with(['mess', 'category', 'user', 'approvedBy'])->findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $expense->mess->manager_id !== $user->id &&
            $expense->user_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $expense
        ]);
    }

    /**
     * Update the specified expense.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'category_id' => 'sometimes|required|exists:expense_categories,id',
            'expense_date' => 'sometimes|required|date|before_or_equal:today',
            'description' => 'sometimes|required|string|max:1000',
            'amount' => 'sometimes|required|numeric|min:0.01',
            'notes' => 'nullable|string|max:1000'
        ]);

        $user = Auth::user();
        $expense = Expense::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $expense->mess->manager_id !== $user->id &&
            $expense->user_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Don't allow updating approved expenses (only managers can)
        if ($expense->isApproved() && !$user->hasRole('super_admin') && $expense->mess->manager_id !== $user->id) {
            return response()->json(['message' => 'Cannot update approved expense'], 400);
        }

        try {
            $expense->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Expense updated successfully',
                'data' => $expense->fresh()->load(['category', 'user', 'approvedBy'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update expense: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified expense.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $expense = Expense::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $expense->mess->manager_id !== $user->id &&
            $expense->user_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Don't allow deleting approved expenses (only managers can)
        if ($expense->isApproved() && !$user->hasRole('super_admin') && $expense->mess->manager_id !== $user->id) {
            return response()->json(['message' => 'Cannot delete approved expense'], 400);
        }

        try {
            // Delete receipt image if exists
            if ($expense->receipt_image) {
                Storage::disk('public')->delete($expense->receipt_image);
            }

            $expense->delete();

            return response()->json([
                'success' => true,
                'message' => 'Expense deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete expense: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve the specified expense.
     */
    public function approve($id)
    {
        $user = Auth::user();
        $expense = Expense::findOrFail($id);

        // Check authorization (only managers can approve)
        if (!$user->hasRole('super_admin') && $expense->mess->manager_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($expense->isApproved()) {
            return response()->json([
                'success' => false,
                'message' => 'Expense is already approved'
            ], 400);
        }

        try {
            $expense->approve($user->id);

            return response()->json([
                'success' => true,
                'message' => 'Expense approved successfully',
                'data' => $expense->fresh()->load(['category', 'user', 'approvedBy'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve expense: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload receipt image for expense.
     */
    public function uploadReceipt(Request $request, $id)
    {
        $validated = $request->validate([
            'receipt_image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        $user = Auth::user();
        $expense = Expense::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $expense->mess->manager_id !== $user->id &&
            $expense->user_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            // Delete old receipt if exists
            if ($expense->receipt_image) {
                Storage::disk('public')->delete($expense->receipt_image);
            }

            // Upload new receipt
            $receiptPath = $request->file('receipt_image')->store('expense_receipts', 'public');

            $expense->update(['receipt_image' => $receiptPath]);

            return response()->json([
                'success' => true,
                'message' => 'Receipt uploaded successfully',
                'data' => [
                    'receipt_url' => $expense->receipt_url
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload receipt: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get expense report.
     */
    public function report(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'year' => 'required|integer|min:2020|max:' . date('Y'),
            'month' => 'required|integer|min:1|max:12',
            'group_by' => 'nullable|in:category,user,date,none'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            !$mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $expenses = $mess->expenses()
            ->forMonth($validated['year'], $validated['month'])
            ->with(['category', 'user', 'approvedBy'])
            ->orderBy('expense_date')
            ->get();

        $data = [];

        if ($validated['group_by'] === 'category') {
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
        } elseif ($validated['group_by'] === 'user') {
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
        } elseif ($validated['group_by'] === 'date') {
            $data = $expenses->groupBy(function ($expense) {
                return $expense->expense_date->format('Y-m-d');
            })->map(function ($dateExpenses, $date) {
                return [
                    'date' => $date,
                    'formatted_date' => \Carbon\Carbon::parse($date)->format('M d, Y'),
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

        return response()->json([
            'success' => true,
            'data' => $data,
            'period' => [
                'year' => $validated['year'],
                'month' => $validated['month'],
                'month_name' => \Carbon\Carbon::create($validated['year'], $validated['month'], 1)->format('F Y'),
                'group_by' => $validated['group_by'] ?? 'none'
            ]
        ]);
    }

    /**
     * Get expense statistics.
     */
    public function statistics(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'year' => 'required|integer|min:2020|max:' . date('Y'),
            'month' => 'required|integer|min:1|max:12',
            'user_id' => 'nullable|exists:users,id'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            !$mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $statistics = Expense::getMessExpenseStatistics($validated['mess_id'], $validated['year'], $validated['month']);

        if (isset($validated['user_id'])) {
            // Only allow user-specific statistics if authorized
            if (
                !$user->hasRole('super_admin') &&
                $mess->manager_id !== $user->id &&
                $validated['user_id'] !== $user->id
            ) {
                return response()->json(['message' => 'Unauthorized to view user-specific statistics'], 403);
            }

            $userStatistics = Expense::getUserExpenseStatistics($validated['mess_id'], $validated['user_id'], $validated['year'], $validated['month']);
            $statistics['user_statistics'] = $userStatistics;
        }

        return response()->json([
            'success' => true,
            'data' => $statistics
        ]);
    }

    /**
     * Get expense categories.
     */
    public function categories(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            !$mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $categories = \App\Models\ExpenseCategory::active()->get();

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }
}

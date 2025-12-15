<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ExpensePolicy
{
    /**
     * Determine whether user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // All authenticated users can view expenses they have access to
    }

    /**
     * Determine whether user can view model.
     */
    public function view(User $user, Expense $expense): bool
    {
        // Super admin can view all expenses
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can view expenses in their mess
        if ($expense->mess->manager_id === $user->id) {
            return true;
        }

        // Users can view their own expenses
        return $expense->user_id === $user->id;
    }

    /**
     * Determine whether user can create models.
     */
    public function create(User $user): bool
    {
        return true; // All authenticated users can create expenses (subject to mess membership)
    }

    /**
     * Determine whether user can update model.
     */
    public function update(User $user, Expense $expense): bool
    {
        // Super admin can update all expenses
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can update expenses in their mess
        if ($expense->mess->manager_id === $user->id) {
            return true;
        }

        // Users can update their own expenses (if not approved)
        return $expense->user_id === $user->id && !$expense->isApproved();
    }

    /**
     * Determine whether user can delete model.
     */
    public function delete(User $user, Expense $expense): bool
    {
        // Super admin can delete all expenses
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can delete expenses in their mess
        if ($expense->mess->manager_id === $user->id) {
            return true;
        }

        // Users can delete their own expenses (if not approved)
        return $expense->user_id === $user->id && !$expense->isApproved();
    }

    /**
     * Determine whether user can restore model.
     */
    public function restore(User $user, Expense $expense): bool
    {
        // Only super admin can restore expenses
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether user can permanently delete model.
     */
    public function forceDelete(User $user, Expense $expense): bool
    {
        // Only super admin can permanently delete expenses
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether user can approve expenses.
     */
    public function approve(User $user, Expense $expense): bool
    {
        // Super admin can approve all expenses
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Only mess manager can approve expenses in their mess
        return $expense->mess->manager_id === $user->id;
    }

    /**
     * Determine whether user can upload receipts.
     */
    public function uploadReceipt(User $user, Expense $expense): bool
    {
        // Super admin can upload receipts for all expenses
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can upload receipts for expenses in their mess
        if ($expense->mess->manager_id === $user->id) {
            return true;
        }

        // Users can upload receipts for their own expenses
        return $expense->user_id === $user->id;
    }

    /**
     * Determine whether user can view expense statistics.
     */
    public function viewStatistics(User $user, Expense $expense): bool
    {
        // Super admin can view all expense statistics
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can view statistics for expenses in their mess
        if ($expense->mess->manager_id === $user->id) {
            return true;
        }

        // Users can view their own expense statistics
        return $expense->user_id === $user->id;
    }

    /**
     * Determine whether user can view expense reports.
     */
    public function viewReports(User $user): bool
    {
        // Super admin can view all expense reports
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // All authenticated users can view expense reports for messes they have access to
        return true;
    }

    /**
     * Determine whether user can manage expense categories.
     */
    public function manageCategories(User $user): bool
    {
        // Super admin can manage all expense categories
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can manage expense categories in their mess
        return $user->hasRole('admin');
    }

    /**
     * Determine whether user can export expense data.
     */
    public function export(User $user): bool
    {
        // Super admin can export all expense data
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can export expense data for their mess
        return $user->hasRole('admin');
    }

    /**
     * Determine whether user can bulk manage expenses.
     */
    public function bulkManage(User $user): bool
    {
        // Super admin can bulk manage all expenses
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Only mess managers can bulk manage expenses in their mess
        return $user->hasRole('admin');
    }

    /**
     * Determine whether user can modify expense amounts.
     */
    public function modifyAmount(User $user, Expense $expense): bool
    {
        // Super admin can modify amounts in all expenses
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can modify amounts in expenses in their mess
        if ($expense->mess->manager_id === $user->id) {
            return true;
        }

        // Users can modify amounts in their own expenses (if not approved)
        return $expense->user_id === $user->id && !$expense->isApproved();
    }

    /**
     * Determine whether user can add notes to expenses.
     */
    public function addNotes(User $user, Expense $expense): bool
    {
        // Super admin can add notes to all expenses
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can add notes to expenses in their mess
        if ($expense->mess->manager_id === $user->id) {
            return true;
        }

        // Users can add notes to their own expenses
        return $expense->user_id === $user->id;
    }

    /**
     * Determine whether user can change expense categories.
     */
    public function changeCategory(User $user, Expense $expense): bool
    {
        // Super admin can change categories for all expenses
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can change categories for expenses in their mess
        if ($expense->mess->manager_id === $user->id) {
            return true;
        }

        // Users can change categories for their own expenses (if not approved)
        return $expense->user_id === $user->id && !$expense->isApproved();
    }

    /**
     * Determine whether user can view expense categories.
     */
    public function viewCategories(User $user): bool
    {
        // Super admin can view all expense categories
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // All authenticated users can view expense categories
        return true;
    }

    /**
     * Determine whether user can create expense categories.
     */
    public function createCategories(User $user): bool
    {
        // Super admin can create expense categories
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can create expense categories in their mess
        return $user->hasRole('admin');
    }

    /**
     * Determine whether user can update expense categories.
     */
    public function updateCategories(User $user): bool
    {
        // Super admin can update all expense categories
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can update expense categories in their mess
        return $user->hasRole('admin');
    }

    /**
     * Determine whether user can delete expense categories.
     */
    public function deleteCategories(User $user): bool
    {
        // Super admin can delete all expense categories
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can delete expense categories in their mess
        return $user->hasRole('admin');
    }
}

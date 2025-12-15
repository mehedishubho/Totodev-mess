<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PaymentPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any payments.
     */
    public function viewAny(User $user): bool
    {
        // All authenticated users can view payments for their messes
        return true;
    }

    /**
     * Determine whether the user can view the payment.
     */
    public function view(User $user, Payment $payment): bool
    {
        // Super admin can view all payments
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can view all payments for their mess
        if ($payment->mess->manager_id === $user->id) {
            return true;
        }

        // Users can view their own payments
        if ($payment->user_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create payments.
     */
    public function create(User $user): bool
    {
        // All authenticated users can create payments for themselves
        return true;
    }

    /**
     * Determine whether the user can update the payment.
     */
    public function update(User $user, Payment $payment): bool
    {
        // Super admin can update all payments
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can update all payments for their mess
        if ($payment->mess->manager_id === $user->id) {
            return true;
        }

        // Users can update their own payments, but only if not approved
        if ($payment->user_id === $user->id && !$payment->isApproved()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the payment.
     */
    public function delete(User $user, Payment $payment): bool
    {
        // Super admin can delete all payments
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can delete all payments for their mess
        if ($payment->mess->manager_id === $user->id) {
            return true;
        }

        // Users can delete their own payments, but only if not approved
        if ($payment->user_id === $user->id && !$payment->isApproved()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can approve the payment.
     */
    public function approve(User $user, Payment $payment): bool
    {
        // Only super admin and mess managers can approve payments
        if ($user->hasRole('super_admin')) {
            return true;
        }

        if ($payment->mess->manager_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can upload receipt for the payment.
     */
    public function uploadReceipt(User $user, Payment $payment): bool
    {
        // Super admin can upload receipts for all payments
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can upload receipts for all payments in their mess
        if ($payment->mess->manager_id === $user->id) {
            return true;
        }

        // Users can upload receipts for their own payments
        if ($payment->user_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can view payment history for other users.
     */
    public function viewUserHistory(User $user, User $targetUser): bool
    {
        // Super admin can view anyone's history
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Users can only view their own history
        if ($user->id === $targetUser->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can view payment statistics for other users.
     */
    public function viewUserStatistics(User $user, User $targetUser): bool
    {
        // Super admin can view anyone's statistics
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Users can only view their own statistics
        if ($user->id === $targetUser->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can view payment collection reports.
     */
    public function viewCollectionReport(User $user): bool
    {
        // Super admin can view all reports
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // All authenticated users can view reports for their messes
        return true;
    }

    /**
     * Determine whether the user can view payment methods summary.
     */
    public function viewPaymentMethodsSummary(User $user): bool
    {
        // Super admin can view all summaries
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // All authenticated users can view summaries for their messes
        return true;
    }

    /**
     * Determine whether the user can create payments for other users.
     */
    public function createForUser(User $user, User $targetUser): bool
    {
        // Super admin can create payments for anyone
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Users can create payments for themselves
        if ($user->id === $targetUser->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can bulk approve payments.
     */
    public function bulkApprove(User $user): bool
    {
        // Only super admin and mess managers can bulk approve
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Check if user is a mess manager of any mess
        return $user->managedMesses()->exists();
    }

    /**
     * Determine whether the user can export payment data.
     */
    public function export(User $user): bool
    {
        // Super admin can export all payment data
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess managers can export payment data for their messes
        return $user->managedMesses()->exists();
    }

    /**
     * Determine whether the user can access payment analytics.
     */
    public function accessAnalytics(User $user): bool
    {
        // Super admin can access all analytics
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess managers can access analytics for their messes
        return $user->managedMesses()->exists();
    }

    /**
     * Determine whether the user can view payment trends.
     */
    public function viewTrends(User $user): bool
    {
        // Super admin can view all trends
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // All authenticated users can view trends for their messes
        return true;
    }

    /**
     * Determine whether the user can manage payment settings.
     */
    public function manageSettings(User $user): bool
    {
        // Only super admin can manage global payment settings
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can view payment audit logs.
     */
    public function viewAuditLogs(User $user): bool
    {
        // Only super admin can view audit logs
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can restore soft deleted payments.
     */
    public function restore(User $user, Payment $payment): bool
    {
        // Only super admin can restore deleted payments
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can force delete payments.
     */
    public function forceDelete(User $user, Payment $payment): bool
    {
        // Only super admin can force delete payments
        return $user->hasRole('super_admin');
    }
}

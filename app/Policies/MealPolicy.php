<?php

namespace App\Policies;

use App\Models\Meal;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MealPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // All authenticated users can view meals they have access to
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Meal $meal): bool
    {
        // Super admin can view all meals
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can view meals in their mess
        if ($meal->mess->manager_id === $user->id) {
            return true;
        }

        // Users can view their own meals
        return $meal->user_id === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true; // All authenticated users can create meals (subject to mess membership)
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Meal $meal): bool
    {
        // Super admin can update all meals
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can update meals in their mess
        if ($meal->mess->manager_id === $user->id) {
            return true;
        }

        // Users can update their own meals (if not locked)
        return $meal->user_id === $user->id && !$meal->isLocked();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Meal $meal): bool
    {
        // Super admin can delete all meals
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can delete meals in their mess
        if ($meal->mess->manager_id === $user->id) {
            return true;
        }

        // Users can delete their own meals (if not locked)
        return $meal->user_id === $user->id && !$meal->isLocked();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Meal $meal): bool
    {
        // Only super admin can restore meals
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Meal $meal): bool
    {
        // Only super admin can permanently delete meals
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can lock meals.
     */
    public function lock(User $user, Meal $meal): bool
    {
        // Super admin can lock all meals
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Only mess manager can lock meals in their mess
        return $meal->mess->manager_id === $user->id;
    }

    /**
     * Determine whether the user can unlock meals.
     */
    public function unlock(User $user, Meal $meal): bool
    {
        // Super admin can unlock all meals
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Only mess manager can unlock meals in their mess
        return $meal->mess->manager_id === $user->id;
    }

    /**
     * Determine whether the user can enter meals for others.
     */
    public function enterForOthers(User $user, Meal $meal): bool
    {
        // Super admin can enter meals for anyone
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can enter meals for members in their mess
        if ($meal->mess->manager_id === $user->id) {
            return true;
        }

        // Staff can enter meals for members in their mess
        $member = $meal->mess->members()
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('role', 'staff')
            ->first();

        return $member !== null;
    }

    /**
     * Determine whether the user can view meal statistics.
     */
    public function viewStatistics(User $user, Meal $meal): bool
    {
        // Super admin can view all meal statistics
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can view statistics for meals in their mess
        if ($meal->mess->manager_id === $user->id) {
            return true;
        }

        // Users can view their own meal statistics
        return $meal->user_id === $user->id;
    }

    /**
     * Determine whether the user can view meal summary.
     */
    public function viewSummary(User $user): bool
    {
        // Super admin can view all meal summaries
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // All authenticated users can view meal summaries for messes they have access to
        return true;
    }

    /**
     * Determine whether the user can bulk lock meals.
     */
    public function bulkLock(User $user): bool
    {
        // Super admin can bulk lock all meals
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Only mess managers can bulk lock meals in their mess
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can modify extra items.
     */
    public function modifyExtraItems(User $user, Meal $meal): bool
    {
        // Super admin can modify extra items in all meals
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can modify extra items in meals in their mess
        if ($meal->mess->manager_id === $user->id) {
            return true;
        }

        // Users can modify extra items in their own meals (if not locked)
        return $meal->user_id === $user->id && !$meal->isLocked();
    }

    /**
     * Determine whether the user can add notes to meals.
     */
    public function addNotes(User $user, Meal $meal): bool
    {
        // Super admin can add notes to all meals
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can add notes to meals in their mess
        if ($meal->mess->manager_id === $user->id) {
            return true;
        }

        // Users can add notes to their own meals (if not locked)
        return $meal->user_id === $user->id && !$meal->isLocked();
    }

    /**
     * Determine whether the user can override meal cutoff time.
     */
    public function overrideCutoff(User $user): bool
    {
        // Only super admin and mess managers can override cutoff time
        return $user->hasRole(['super_admin', 'admin']);
    }

    /**
     * Determine whether the user can view meal history.
     */
    public function viewHistory(User $user): bool
    {
        // Super admin can view all meal history
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // All authenticated users can view meal history for messes they have access to
        return true;
    }

    /**
     * Determine whether the user can export meal data.
     */
    public function export(User $user): bool
    {
        // Super admin can export all meal data
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can export meal data for their mess
        return $user->hasRole('admin');
    }
}

<?php

namespace App\Policies;

use App\Models\Mess;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MessPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // All authenticated users can view messes they have access to
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Mess $mess): bool
    {
        // Super admin can view all messes
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can view their own mess
        if ($mess->manager_id === $user->id) {
            return true;
        }

        // Members can view their own mess
        return $mess->members()->where('user_id', $user->id)->where('status', 'approved')->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Only super admin and admin can create messes
        return $user->hasRole(['super_admin', 'admin']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Mess $mess): bool
    {
        // Super admin can update all messes
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Only mess manager can update their own mess
        return $mess->manager_id === $user->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Mess $mess): bool
    {
        // Only super admin can delete messes
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Mess $mess): bool
    {
        // Only super admin can restore messes
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Mess $mess): bool
    {
        // Only super admin can permanently delete messes
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can view mess statistics.
     */
    public function viewStatistics(User $user, Mess $mess): bool
    {
        // Super admin can view all mess statistics
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can view their own mess statistics
        if ($mess->manager_id === $user->id) {
            return true;
        }

        // Members can view their own mess statistics
        return $mess->members()->where('user_id', $user->id)->where('status', 'approved')->exists();
    }

    /**
     * Determine whether the user can add members to the mess.
     */
    public function addMember(User $user, Mess $mess): bool
    {
        // Super admin can add members to any mess
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Only mess manager can add members to their own mess
        return $mess->manager_id === $user->id;
    }

    /**
     * Determine whether the user can remove members from the mess.
     */
    public function removeMember(User $user, Mess $mess): bool
    {
        // Super admin can remove members from any mess
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Only mess manager can remove members from their own mess
        return $mess->manager_id === $user->id;
    }

    /**
     * Determine whether the user can manage mess settings.
     */
    public function manageSettings(User $user, Mess $mess): bool
    {
        // Super admin can manage all mess settings
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Only mess manager can manage their own mess settings
        return $mess->manager_id === $user->id;
    }

    /**
     * Determine whether the user can view mess members.
     */
    public function viewMembers(User $user, Mess $mess): bool
    {
        // Super admin can view all mess members
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can view their own mess members
        if ($mess->manager_id === $user->id) {
            return true;
        }

        // Members can view other members of their own mess
        return $mess->members()->where('user_id', $user->id)->where('status', 'approved')->exists();
    }

    /**
     * Determine whether the user can manage mess meals.
     */
    public function manageMeals(User $user, Mess $mess): bool
    {
        // Super admin can manage all mess meals
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can manage their own mess meals
        if ($mess->manager_id === $user->id) {
            return true;
        }

        // Staff can manage meals in their mess
        $member = $mess->members()->where('user_id', $user->id)->where('status', 'approved')->first();
        return $member && $member->role === 'staff';
    }

    /**
     * Determine whether the user can manage mess bazars.
     */
    public function manageBazars(User $user, Mess $mess): bool
    {
        // Super admin can manage all mess bazars
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can manage their own mess bazars
        if ($mess->manager_id === $user->id) {
            return true;
        }

        // Staff can manage bazars in their mess
        $member = $mess->members()->where('user_id', $user->id)->where('status', 'approved')->first();
        return $member && $member->role === 'staff';
    }

    /**
     * Determine whether the user can manage mess expenses.
     */
    public function manageExpenses(User $user, Mess $mess): bool
    {
        // Super admin can manage all mess expenses
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Only mess manager can manage expenses in their own mess
        return $mess->manager_id === $user->id;
    }

    /**
     * Determine whether the user can manage mess payments.
     */
    public function managePayments(User $user, Mess $mess): bool
    {
        // Super admin can manage all mess payments
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Only mess manager can manage payments in their own mess
        return $mess->manager_id === $user->id;
    }
}

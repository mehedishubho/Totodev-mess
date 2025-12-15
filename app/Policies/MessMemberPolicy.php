<?php

namespace App\Policies;

use App\Models\MessMember;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MessMemberPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // All authenticated users can view members they have access to
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, MessMember $messMember): bool
    {
        // Super admin can view all members
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can view members of their own mess
        if ($messMember->mess->manager_id === $user->id) {
            return true;
        }

        // Users can view their own member profile
        return $messMember->user_id === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Only super admin and admin can create members
        return $user->hasRole(['super_admin', 'admin']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, MessMember $messMember): bool
    {
        // Super admin can update all members
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can update members of their own mess
        return $messMember->mess->manager_id === $user->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, MessMember $messMember): bool
    {
        // Super admin can delete all members
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can delete members of their own mess (except themselves)
        if ($messMember->mess->manager_id === $user->id) {
            return $messMember->user_id !== $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, MessMember $messMember): bool
    {
        // Only super admin can restore members
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, MessMember $messMember): bool
    {
        // Only super admin can permanently delete members
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can approve members.
     */
    public function approve(User $user, MessMember $messMember): bool
    {
        // Super admin can approve all members
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can approve members of their own mess
        return $messMember->mess->manager_id === $user->id;
    }

    /**
     * Determine whether the user can reject members.
     */
    public function reject(User $user, MessMember $messMember): bool
    {
        // Super admin can reject all members
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can reject members of their own mess
        return $messMember->mess->manager_id === $user->id;
    }

    /**
     * Determine whether the user can view member statistics.
     */
    public function viewStatistics(User $user, MessMember $messMember): bool
    {
        // Super admin can view all member statistics
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can view statistics of members in their own mess
        if ($messMember->mess->manager_id === $user->id) {
            return true;
        }

        // Users can view their own statistics
        return $messMember->user_id === $user->id;
    }

    /**
     * Determine whether the user can manage member settings.
     */
    public function manageSettings(User $user, MessMember $messMember): bool
    {
        // Super admin can manage all member settings
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can manage settings of members in their own mess
        return $messMember->mess->manager_id === $user->id;
    }

    /**
     * Determine whether the user can update member role.
     */
    public function updateRole(User $user, MessMember $messMember): bool
    {
        // Super admin can update all member roles
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can update roles of members in their own mess (except themselves)
        if ($messMember->mess->manager_id === $user->id) {
            return $messMember->user_id !== $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can manage member payments.
     */
    public function managePayments(User $user, MessMember $messMember): bool
    {
        // Super admin can manage all member payments
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can manage payments of members in their own mess
        return $messMember->mess->manager_id === $user->id;
    }

    /**
     * Determine whether the user can view member bills.
     */
    public function viewBills(User $user, MessMember $messMember): bool
    {
        // Super admin can view all member bills
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can view bills of members in their own mess
        if ($messMember->mess->manager_id === $user->id) {
            return true;
        }

        // Users can view their own bills
        return $messMember->user_id === $user->id;
    }

    /**
     * Determine whether the user can manage member meals.
     */
    public function manageMeals(User $user, MessMember $messMember): bool
    {
        // Super admin can manage all member meals
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can manage meals of members in their own mess
        if ($messMember->mess->manager_id === $user->id) {
            return true;
        }

        // Staff can manage meals of members in their mess
        $staffMember = $messMember->mess->members()
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('role', 'staff')
            ->first();

        return $staffMember !== null;
    }

    /**
     * Determine whether the user can assign bazar to member.
     */
    public function assignBazar(User $user, MessMember $messMember): bool
    {
        // Super admin can assign bazar to all members
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can assign bazar to members in their own mess
        return $messMember->mess->manager_id === $user->id;
    }

    /**
     * Determine whether the user can perform bulk actions on members.
     */
    public function bulkAction(User $user): bool
    {
        // Only super admin and admin can perform bulk actions
        return $user->hasRole(['super_admin', 'admin']);
    }
}

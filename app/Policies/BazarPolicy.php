<?php

namespace App\Policies;

use App\Models\Bazar;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BazarPolicy
{
    /**
     * Determine whether user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // All authenticated users can view bazars they have access to
    }

    /**
     * Determine whether user can view the model.
     */
    public function view(User $user, Bazar $bazar): bool
    {
        // Super admin can view all bazars
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can view bazars in their mess
        if ($bazar->mess->manager_id === $user->id) {
            return true;
        }

        // Users can view their own bazars
        return $bazar->bazar_person_id === $user->id;
    }

    /**
     * Determine whether user can create models.
     */
    public function create(User $user): bool
    {
        return true; // All authenticated users can create bazars (subject to mess membership)
    }

    /**
     * Determine whether user can update the model.
     */
    public function update(User $user, Bazar $bazar): bool
    {
        // Super admin can update all bazars
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can update bazars in their mess
        if ($bazar->mess->manager_id === $user->id) {
            return true;
        }

        // Users can update their own bazars (if not approved)
        return $bazar->bazar_person_id === $user->id && !$bazar->isApproved();
    }

    /**
     * Determine whether user can delete the model.
     */
    public function delete(User $user, Bazar $bazar): bool
    {
        // Super admin can delete all bazars
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can delete bazars in their mess
        if ($bazar->mess->manager_id === $user->id) {
            return true;
        }

        // Users can delete their own bazars (if not approved)
        return $bazar->bazar_person_id === $user->id && !$bazar->isApproved();
    }

    /**
     * Determine whether user can restore the model.
     */
    public function restore(User $user, Bazar $bazar): bool
    {
        // Only super admin can restore bazars
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether user can permanently delete the model.
     */
    public function forceDelete(User $user, Bazar $bazar): bool
    {
        // Only super admin can permanently delete bazars
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether user can approve bazars.
     */
    public function approve(User $user, Bazar $bazar): bool
    {
        // Super admin can approve all bazars
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Only mess manager can approve bazars in their mess
        return $bazar->mess->manager_id === $user->id;
    }

    /**
     * Determine whether user can upload receipts.
     */
    public function uploadReceipt(User $user, Bazar $bazar): bool
    {
        // Super admin can upload receipts for all bazars
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can upload receipts for bazars in their mess
        if ($bazar->mess->manager_id === $user->id) {
            return true;
        }

        // Users can upload receipts for their own bazars
        return $bazar->bazar_person_id === $user->id;
    }

    /**
     * Determine whether user can assign bazar person.
     */
    public function assignPerson(User $user, Bazar $bazar): bool
    {
        // Super admin can assign bazar person for all bazars
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Only mess manager can assign bazar person in their mess
        return $bazar->mess->manager_id === $user->id;
    }

    /**
     * Determine whether user can view bazar statistics.
     */
    public function viewStatistics(User $user, Bazar $bazar): bool
    {
        // Super admin can view all bazar statistics
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can view statistics for bazars in their mess
        if ($bazar->mess->manager_id === $user->id) {
            return true;
        }

        // Users can view their own bazar statistics
        return $bazar->bazar_person_id === $user->id;
    }

    /**
     * Determine whether user can view bazar reports.
     */
    public function viewReports(User $user): bool
    {
        // Super admin can view all bazar reports
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // All authenticated users can view bazar reports for messes they have access to
        return true;
    }

    /**
     * Determine whether user can manage bazar items.
     */
    public function manageItems(User $user, Bazar $bazar): bool
    {
        // Super admin can manage items in all bazars
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can manage items in bazars in their mess
        if ($bazar->mess->manager_id === $user->id) {
            return true;
        }

        // Users can manage items in their own bazars (if not approved)
        return $bazar->bazar_person_id === $user->id && !$bazar->isApproved();
    }

    /**
     * Determine whether user can add notes to bazars.
     */
    public function addNotes(User $user, Bazar $bazar): bool
    {
        // Super admin can add notes to all bazars
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can add notes to bazars in their mess
        if ($bazar->mess->manager_id === $user->id) {
            return true;
        }

        // Users can add notes to their own bazars
        return $bazar->bazar_person_id === $user->id;
    }

    /**
     * Determine whether user can modify bazar cost.
     */
    public function modifyCost(User $user, Bazar $bazar): bool
    {
        // Super admin can modify cost in all bazars
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can modify cost in bazars in their mess
        if ($bazar->mess->manager_id === $user->id) {
            return true;
        }

        // Users can modify cost in their own bazars (if not approved)
        return $bazar->bazar_person_id === $user->id && !$bazar->isApproved();
    }

    /**
     * Determine whether user can view upcoming bazars.
     */
    public function viewUpcoming(User $user): bool
    {
        // Super admin can view all upcoming bazars
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // All authenticated users can view upcoming bazars for messes they have access to
        return true;
    }

    /**
     * Determine whether user can view recent bazars.
     */
    public function viewRecent(User $user): bool
    {
        // Super admin can view all recent bazars
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // All authenticated users can view recent bazars for messes they have access to
        return true;
    }

    /**
     * Determine whether user can export bazar data.
     */
    public function export(User $user): bool
    {
        // Super admin can export all bazar data
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can export bazar data for their mess
        return $user->hasRole('admin');
    }

    /**
     * Determine whether user can bulk manage bazars.
     */
    public function bulkManage(User $user): bool
    {
        // Super admin can bulk manage all bazars
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Only mess managers can bulk manage bazars in their mess
        return $user->hasRole('admin');
    }
}

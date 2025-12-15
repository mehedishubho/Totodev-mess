<?php

namespace App\Services;

use App\Models\Mess;
use App\Models\MessMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MessService
{
    /**
     * Create a new mess with manager as member.
     */
    public function createMess(array $data, $managerId)
    {
        try {
            DB::beginTransaction();

            // Handle logo upload
            if (isset($data['logo']) && $data['logo'] instanceof \Illuminate\Http\UploadedFile) {
                $logoPath = $data['logo']->store('mess_logos', 'public');
                $data['logo'] = $logoPath;
            }

            // Set default values
            $data['manager_id'] = $managerId;
            $data['status'] = $data['status'] ?? true;
            $data['auto_bazar_rotation'] = $data['auto_bazar_rotation'] ?? true;

            $mess = Mess::create($data);

            // Add manager as a member automatically
            MessMember::create([
                'mess_id' => $mess->id,
                'user_id' => $managerId,
                'role' => 'admin',
                'status' => 'approved',
                'joined_at' => now(),
                'approved_by' => $managerId,
                'approved_at' => now()
            ]);

            DB::commit();

            return $mess;
        } catch (\Exception $e) {
            DB::rollBack();

            // Remove uploaded logo if transaction failed
            if (isset($logoPath)) {
                Storage::disk('public')->delete($logoPath);
            }

            throw new \Exception('Failed to create mess: ' . $e->getMessage());
        }
    }

    /**
     * Update mess with logo handling.
     */
    public function updateMess(Mess $mess, array $data)
    {
        try {
            DB::beginTransaction();

            // Handle logo upload
            if (isset($data['logo']) && $data['logo'] instanceof \Illuminate\Http\UploadedFile) {
                // Delete old logo
                if ($mess->logo) {
                    Storage::disk('public')->delete($mess->logo);
                }

                $logoPath = $data['logo']->store('mess_logos', 'public');
                $data['logo'] = $logoPath;
            }

            $mess->update($data);

            DB::commit();

            return $mess->fresh();
        } catch (\Exception $e) {
            DB::rollBack();

            // Remove uploaded logo if transaction failed
            if (isset($logoPath)) {
                Storage::disk('public')->delete($logoPath);
            }

            throw new \Exception('Failed to update mess: ' . $e->getMessage());
        }
    }

    /**
     * Delete mess with cleanup.
     */
    public function deleteMess(Mess $mess)
    {
        try {
            DB::beginTransaction();

            // Check if mess has active members
            if ($mess->members()->where('status', 'approved')->count() > 0) {
                throw new \Exception('Cannot delete mess with active members');
            }

            // Delete logo if exists
            if ($mess->logo) {
                Storage::disk('public')->delete($mess->logo);
            }

            $mess->delete();

            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('Failed to delete mess: ' . $e->getMessage());
        }
    }

    /**
     * Add member to mess with validation.
     */
    public function addMember(Mess $mess, array $data, $approvedBy)
    {
        try {
            DB::beginTransaction();

            // Create user if not exists
            if (!isset($data['user_id'])) {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'password' => bcrypt('password123'), // Default password
                    'email_verified_at' => now()
                ]);

                // Assign member role
                $user->assignRole('member');
                $data['user_id'] = $user->id;
            }

            // Check if user is already a member
            if ($mess->members()->where('user_id', $data['user_id'])->exists()) {
                throw new \Exception('User is already a member of this mess');
            }

            // Check max members limit
            if ($mess->hasReachedMaxMembers()) {
                throw new \Exception('Mess has reached maximum member limit');
            }

            $member = MessMember::create([
                'mess_id' => $mess->id,
                'user_id' => $data['user_id'],
                'role' => $data['role'],
                'room_number' => $data['room_number'] ?? null,
                'status' => $data['status'] ?? 'pending',
                'monthly_fixed_cost' => $data['monthly_fixed_cost'] ?? null,
                'deposit_amount' => $data['deposit_amount'] ?? null,
                'notes' => $data['notes'] ?? null,
                'joined_at' => now(),
                'approved_by' => $data['status'] === 'approved' ? $approvedBy : null,
                'approved_at' => $data['status'] === 'approved' ? now() : null
            ]);

            DB::commit();

            return $member;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('Failed to add member: ' . $e->getMessage());
        }
    }

    /**
     * Remove member from mess with validation.
     */
    public function removeMember(Mess $mess, MessMember $member)
    {
        try {
            // Don't allow removing the manager
            if ($member->user_id === $mess->manager_id) {
                throw new \Exception('Cannot remove mess manager');
            }

            $member->delete();

            return true;
        } catch (\Exception $e) {
            throw new \Exception('Failed to remove member: ' . $e->getMessage());
        }
    }

    /**
     * Get next bazar person based on rotation.
     */
    public function getNextBazarPerson(Mess $mess)
    {
        if (!$mess->auto_bazar_rotation) {
            return null;
        }

        $activeMembers = $mess->activeMembers()->get();

        if ($activeMembers->isEmpty()) {
            return null;
        }

        $lastBazar = $mess->bazars()
            ->orderBy('bazar_date', 'desc')
            ->first();

        if (!$lastBazar) {
            return $activeMembers->first()->user;
        }

        $currentIndex = $activeMembers->search(function ($member) use ($lastBazar) {
            return $member->user_id === $lastBazar->bazar_person_id;
        });

        $nextIndex = ($currentIndex + 1) % $activeMembers->count();
        $nextMember = $activeMembers->get($nextIndex);

        return $nextMember ? $nextMember->user : null;
    }

    /**
     * Generate monthly report for mess.
     */
    public function generateMonthlyReport(Mess $mess, $year, $month)
    {
        $startDate = \Carbon\Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $totalMeals = $mess->getTotalMealsForMonth($year, $month);
        $totalBazarCost = $mess->getTotalBazarCostForMonth($year, $month);
        $totalExpenseCost = $mess->getTotalExpenseCostForMonth($year, $month);
        $totalPayments = $mess->getTotalPaymentsForMonth($year, $month);

        $mealSummary = $mess->getMealSummary($startDate, $endDate);
        $members = $mess->activeMembers()->with('user')->get();

        return [
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'month_name' => $startDate->format('F Y'),
            ],
            'summary' => [
                'total_members' => $members->count(),
                'total_meals' => $totalMeals,
                'total_bazar_cost' => $totalBazarCost,
                'total_expense_cost' => $totalExpenseCost,
                'total_payments' => $totalPayments,
                'net_balance' => $totalPayments - ($totalBazarCost + $totalExpenseCost),
            ],
            'meal_rates' => [
                'breakfast' => $mess->breakfast_rate,
                'lunch' => $mess->lunch_rate,
                'dinner' => $mess->dinner_rate,
                'total_daily' => $mess->getTotalDailyMealRate(),
            ],
            'meal_summary' => $mealSummary,
            'members' => $members->map(function ($member) use ($year, $month) {
                return [
                    'id' => $member->id,
                    'name' => $member->user->name,
                    'email' => $member->user->email,
                    'room_number' => $member->room_number,
                    'total_meals' => $member->getTotalMealsForMonth($year, $month),
                    'meal_cost' => $member->getTotalMealCostForMonth($year, $month),
                    'bazar_cost' => $member->getTotalBazarCostForMonth($year, $month),
                    'total_paid' => $member->getTotalPaymentsForMonth($year, $month),
                    'balance' => $member->getCurrentBalance(),
                ];
            }),
        ];
    }

    /**
     * Calculate mess statistics.
     */
    public function calculateStatistics(Mess $mess)
    {
        $currentMonth = now();

        return [
            'total_members' => $mess->activeMembers()->count(),
            'pending_members' => $mess->pendingMembers()->count(),
            'active_members' => $mess->activeMembers()->count(),
            'total_meals_today' => $mess->getTotalMealsForDate(now()),
            'total_bazar_this_month' => $mess->getTotalBazarCostForMonth($currentMonth->year, $currentMonth->month),
            'monthly_meal_rate' => [
                'breakfast' => $mess->breakfast_rate,
                'lunch' => $mess->lunch_rate,
                'dinner' => $mess->dinner_rate,
                'total_daily' => $mess->getTotalDailyMealRate()
            ],
            'next_bazar_person' => $this->getNextBazarPerson($mess),
            'monthly_expense_trend' => $this->getMonthlyExpenseTrend($mess)
        ];
    }

    /**
     * Get monthly expense trend.
     */
    private function getMonthlyExpenseTrend(Mess $mess)
    {
        $months = collect();

        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $totalExpense = $mess->bazars()
                ->whereMonth('bazar_date', $month->month)
                ->whereYear('bazar_date', $month->year)
                ->sum('total_cost');

            $months->push([
                'month' => $month->format('M Y'),
                'expense' => $totalExpense
            ]);
        }

        return $months;
    }
}

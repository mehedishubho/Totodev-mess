<?php

namespace App\Services;

use App\Models\Meal;
use App\Models\Mess;
use App\Models\MessMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MealService
{
    /**
     * Enter meal for user with validation.
     */
    public function enterMeal(array $data, $userId, $enteredBy)
    {
        try {
            DB::beginTransaction();

            // Check if user is a member of the mess
            $member = MessMember::where('mess_id', $data['mess_id'])
                ->where('user_id', $userId)
                ->where('status', 'approved')
                ->first();

            if (!$member) {
                throw new \Exception('User is not an active member of this mess');
            }

            // Check if meal entry is allowed
            if (!Meal::isMealEntryAllowed($data['mess_id'], $data['meal_date'])) {
                throw new \Exception('Meal entry not allowed for this date. Past cutoff time or date is in past.');
            }

            // Check if meal already exists
            $existingMeal = Meal::where('mess_id', $data['mess_id'])
                ->where('user_id', $userId)
                ->whereDate('meal_date', $data['meal_date'])
                ->first();

            if ($existingMeal) {
                throw new \Exception('Meal already exists for this user and date');
            }

            // Check if meal is locked
            if ($existingMeal && $existingMeal->isLocked()) {
                throw new \Exception('Meal is locked and cannot be modified');
            }

            $meal = Meal::create([
                'mess_id' => $data['mess_id'],
                'user_id' => $userId,
                'meal_date' => $data['meal_date'],
                'breakfast' => $data['breakfast'],
                'lunch' => $data['lunch'],
                'dinner' => $data['dinner'],
                'extra_items' => $data['extra_items'] ?? null,
                'notes' => $data['notes'] ?? null,
                'entered_by' => $enteredBy
            ]);

            DB::commit();

            return $meal;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('Failed to enter meal: ' . $e->getMessage());
        }
    }

    /**
     * Update meal with validation.
     */
    public function updateMeal(Meal $meal, array $data, $updatedBy)
    {
        try {
            DB::beginTransaction();

            // Check if meal is locked
            if ($meal->isLocked()) {
                throw new \Exception('Meal is locked and cannot be modified');
            }

            // Check if meal entry is still allowed
            if (!Meal::isMealEntryAllowed($meal->mess_id, $meal->meal_date)) {
                throw new \Exception('Meal modification not allowed. Past cutoff time or date is in past.');
            }

            $meal->update($data);

            DB::commit();

            return $meal->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('Failed to update meal: ' . $e->getMessage());
        }
    }

    /**
     * Delete meal with validation.
     */
    public function deleteMeal(Meal $meal, $deletedBy)
    {
        try {
            DB::beginTransaction();

            // Check if meal is locked
            if ($meal->isLocked()) {
                throw new \Exception('Meal is locked and cannot be deleted');
            }

            // Check if meal entry is still allowed
            if (!Meal::isMealEntryAllowed($meal->mess_id, $meal->meal_date)) {
                throw new \Exception('Meal deletion not allowed. Past cutoff time or date is in past.');
            }

            $meal->delete();

            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('Failed to delete meal: ' . $e->getMessage());
        }
    }

    /**
     * Lock meals for a specific date.
     */
    public function lockMeals($messId, $date, $lockedBy, $force = false)
    {
        try {
            DB::beginTransaction();

            $mess = Mess::findOrFail($messId);
            $mealDate = is_string($date) ? Carbon::parse($date) : $date;

            // Check if cutoff time has passed (unless force is true)
            if (!$force && $mealDate->isToday() && !now()->gt($mess->meal_cutoff_time)) {
                throw new \Exception('Cannot lock meals before cutoff time. Use force=true to override.');
            }

            $meals = Meal::where('mess_id', $messId)
                ->whereDate('meal_date', $mealDate)
                ->whereNull('locked_at')
                ->get();

            foreach ($meals as $meal) {
                $meal->lock($lockedBy);
            }

            DB::commit();

            return [
                'locked_count' => $meals->count(),
                'date' => $mealDate->format('Y-m-d')
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('Failed to lock meals: ' . $e->getMessage());
        }
    }

    /**
     * Generate monthly meal report.
     */
    public function generateMonthlyReport($messId, $year, $month)
    {
        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $meals = Meal::where('mess_id', $messId)
            ->whereBetween('meal_date', [$startDate, $endDate])
            ->with(['user', 'enteredBy', 'lockedBy'])
            ->orderBy('meal_date')
            ->orderBy('user_id')
            ->get();

        $mess = Mess::findOrFail($messId);
        $members = $mess->activeMembers()->with('user')->get();

        // Group meals by date
        $mealsByDate = $meals->groupBy('meal_date');

        // Group meals by user
        $mealsByUser = $meals->groupBy('user_id');

        // Calculate statistics
        $statistics = [
            'total_breakfast' => $meals->sum('breakfast'),
            'total_lunch' => $meals->sum('lunch'),
            'total_dinner' => $meals->sum('dinner'),
            'total_meals' => $meals->sum(function ($meal) {
                return $meal->breakfast + $meal->lunch + $meal->dinner;
            }),
            'unique_members' => $meals->pluck('user_id')->unique()->count(),
            'days_with_meals' => $meals->pluck('meal_date')->unique()->count(),
            'total_meal_cost' => $meals->sum('total_cost'),
            'total_extra_items_cost' => $meals->sum('extra_items_total_cost'),
        ];

        // Member-wise summary
        $memberSummary = $members->map(function ($member) use ($mealsByUser, $mess) {
            $userMeals = $mealsByUser->get($member->user_id, collect());

            return [
                'user' => [
                    'id' => $member->user->id,
                    'name' => $member->user->name,
                    'email' => $member->user->email,
                    'room_number' => $member->room_number,
                ],
                'statistics' => [
                    'total_breakfast' => $userMeals->sum('breakfast'),
                    'total_lunch' => $userMeals->sum('lunch'),
                    'total_dinner' => $userMeals->sum('dinner'),
                    'total_meals' => $userMeals->sum(function ($meal) {
                        return $meal->breakfast + $meal->lunch + $meal->dinner;
                    }),
                    'days_with_meals' => $userMeals->count(),
                    'total_cost' => $userMeals->sum('total_cost'),
                    'extra_items_cost' => $userMeals->sum('extra_items_total_cost'),
                ],
                'meals' => $userMeals
            ];
        });

        // Daily summary
        $dailySummary = $mealsByDate->map(function ($dateMeals, $date) use ($mess) {
            return [
                'date' => $date,
                'formatted_date' => Carbon::parse($date)->format('M d, Y'),
                'statistics' => [
                    'total_breakfast' => $dateMeals->sum('breakfast'),
                    'total_lunch' => $dateMeals->sum('lunch'),
                    'total_dinner' => $dateMeals->sum('dinner'),
                    'total_meals' => $dateMeals->sum(function ($meal) {
                        return $meal->breakfast + $meal->lunch + $meal->dinner;
                    }),
                    'unique_members' => $dateMeals->pluck('user_id')->unique()->count(),
                    'total_cost' => $dateMeals->sum('total_cost'),
                ],
                'meals' => $dateMeals
            ];
        });

        return [
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'month_name' => $startDate->format('F Y'),
            ],
            'mess' => [
                'id' => $mess->id,
                'name' => $mess->name,
                'meal_rates' => [
                    'breakfast' => $mess->breakfast_rate,
                    'lunch' => $mess->lunch_rate,
                    'dinner' => $mess->dinner_rate,
                    'total_daily' => $mess->getTotalDailyMealRate(),
                ],
            ],
            'statistics' => $statistics,
            'member_summary' => $memberSummary,
            'daily_summary' => $dailySummary,
        ];
    }

    /**
     * Get meal summary with grouping.
     */
    public function getMealSummary($messId, $startDate, $endDate, $groupBy = 'date')
    {
        $meals = Meal::where('mess_id', $messId)
            ->whereBetween('meal_date', [$startDate, $endDate])
            ->with(['user'])
            ->get();

        $data = [];

        if ($groupBy === 'date') {
            $data = $meals->groupBy('meal_date')->map(function ($dateMeals, $date) {
                return [
                    'date' => $date,
                    'formatted_date' => Carbon::parse($date)->format('M d, Y'),
                    'total_breakfast' => $dateMeals->sum('breakfast'),
                    'total_lunch' => $dateMeals->sum('lunch'),
                    'total_dinner' => $dateMeals->sum('dinner'),
                    'total_meals' => $dateMeals->sum(function ($meal) {
                        return $meal->breakfast + $meal->lunch + $meal->dinner;
                    }),
                    'unique_members' => $dateMeals->pluck('user_id')->unique()->count(),
                    'total_cost' => $dateMeals->sum('total_cost'),
                    'meals' => $dateMeals
                ];
            })->values();
        } elseif ($groupBy === 'user') {
            $data = $meals->groupBy('user_id')->map(function ($userMeals, $userId) {
                $user = $userMeals->first()->user;
                return [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email
                    ],
                    'total_breakfast' => $userMeals->sum('breakfast'),
                    'total_lunch' => $userMeals->sum('lunch'),
                    'total_dinner' => $userMeals->sum('dinner'),
                    'total_meals' => $userMeals->sum(function ($meal) {
                        return $meal->breakfast + $meal->lunch + $meal->dinner;
                    }),
                    'total_cost' => $userMeals->sum('total_cost'),
                    'days_with_meals' => $userMeals->count(),
                    'meals' => $userMeals
                ];
            })->values();
        } else {
            $data = [
                'total_breakfast' => $meals->sum('breakfast'),
                'total_lunch' => $meals->sum('lunch'),
                'total_dinner' => $meals->sum('dinner'),
                'total_meals' => $meals->sum(function ($meal) {
                    return $meal->breakfast + $meal->lunch + $meal->dinner;
                }),
                'unique_members' => $meals->pluck('user_id')->unique()->count(),
                'total_cost' => $meals->sum('total_cost'),
                'meals' => $meals
            ];
        }

        return [
            'data' => $data,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'group_by' => $groupBy
            ]
        ];
    }

    /**
     * Get today's meal summary for a mess.
     */
    public function getTodayMealSummary($messId)
    {
        $today = today();
        $meals = Meal::where('mess_id', $messId)
            ->whereDate('meal_date', $today)
            ->with(['user', 'enteredBy'])
            ->orderBy('created_at', 'desc')
            ->get();

        $mess = Mess::findOrFail($messId);

        return [
            'date' => $today->format('Y-m-d'),
            'formatted_date' => $today->format('M d, Y'),
            'is_cutoff_passed' => now()->gt($mess->meal_cutoff_time),
            'cutoff_time' => $mess->meal_cutoff_time->format('h:i A'),
            'statistics' => [
                'total_breakfast' => $meals->sum('breakfast'),
                'total_lunch' => $meals->sum('lunch'),
                'total_dinner' => $meals->sum('dinner'),
                'total_meals' => $meals->sum(function ($meal) {
                    return $meal->breakfast + $meal->lunch + $meal->dinner;
                }),
                'unique_members' => $meals->pluck('user_id')->unique()->count(),
                'total_cost' => $meals->sum('total_cost'),
            ],
            'meals' => $meals
        ];
    }

    /**
     * Calculate meal statistics for user in a month.
     */
    public function getUserMealStatistics($messId, $userId, $year, $month)
    {
        return Meal::getUserMealStatistics($messId, $userId, $year, $month);
    }

    /**
     * Calculate meal statistics for mess in a month.
     */
    public function getMessMealStatistics($messId, $year, $month)
    {
        return Meal::getMessMealStatistics($messId, $year, $month);
    }
}

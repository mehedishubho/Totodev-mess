<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meal;
use App\Models\Mess;
use App\Models\MessMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MealController extends Controller
{
    /**
     * Display a listing of meals.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'user_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:locked,unlocked,all',
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

        $query = $mess->meals()->with(['user', 'enteredBy', 'lockedBy']);

        // Filter by date range
        if (isset($validated['date_from'])) {
            $query->whereDate('meal_date', '>=', $validated['date_from']);
        }
        if (isset($validated['date_to'])) {
            $query->whereDate('meal_date', '<=', $validated['date_to']);
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
            if ($validated['status'] === 'locked') {
                $query->locked();
            } elseif ($validated['status'] === 'unlocked') {
                $query->unlocked();
            }
        }

        $meals = $query->orderBy('meal_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => $meals
        ]);
    }

    /**
     * Store a newly created meal.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'user_id' => 'nullable|exists:users,id',
            'meal_date' => 'required|date',
            'breakfast' => 'required|integer|min:0|max:10',
            'lunch' => 'required|integer|min:0|max:10',
            'dinner' => 'required|integer|min:0|max:10',
            'extra_items' => 'nullable|array',
            'extra_items.*.name' => 'required|string|max:255',
            'extra_items.*.quantity' => 'required|integer|min:1',
            'extra_items.*.price' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Set user_id to current user if not provided (for self-entry)
        if (!isset($validated['user_id'])) {
            $validated['user_id'] = $user->id;
        }

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            $validated['user_id'] !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check if meal entry is allowed
        if (!Meal::isMealEntryAllowed($validated['mess_id'], $validated['meal_date'])) {
            return response()->json([
                'message' => 'Meal entry not allowed for this date. Past cutoff time or date is in the past.'
            ], 400);
        }

        // Check if meal already exists for this user and date
        $existingMeal = Meal::where('mess_id', $validated['mess_id'])
            ->where('user_id', $validated['user_id'])
            ->whereDate('meal_date', $validated['meal_date'])
            ->first();

        if ($existingMeal) {
            return response()->json([
                'message' => 'Meal already exists for this user and date',
                'existing_meal' => $existingMeal
            ], 400);
        }

        // Check if meal is locked
        if ($existingMeal && $existingMeal->isLocked()) {
            return response()->json([
                'message' => 'Meal is locked and cannot be modified'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $meal = Meal::create([
                'mess_id' => $validated['mess_id'],
                'user_id' => $validated['user_id'],
                'meal_date' => $validated['meal_date'],
                'breakfast' => $validated['breakfast'],
                'lunch' => $validated['lunch'],
                'dinner' => $validated['dinner'],
                'extra_items' => $validated['extra_items'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'entered_by' => $user->id
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Meal entered successfully',
                'data' => $meal->load(['user', 'enteredBy'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to enter meal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified meal.
     */
    public function show($id)
    {
        $user = Auth::user();
        $meal = Meal::with(['mess', 'user', 'enteredBy', 'lockedBy'])->findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $meal->mess->manager_id !== $user->id &&
            $meal->user_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $meal
        ]);
    }

    /**
     * Update the specified meal.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'breakfast' => 'sometimes|required|integer|min:0|max:10',
            'lunch' => 'sometimes|required|integer|min:0|max:10',
            'dinner' => 'sometimes|required|integer|min:0|max:10',
            'extra_items' => 'nullable|array',
            'extra_items.*.name' => 'required|string|max:255',
            'extra_items.*.quantity' => 'required|integer|min:1',
            'extra_items.*.price' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000'
        ]);

        $user = Auth::user();
        $meal = Meal::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $meal->mess->manager_id !== $user->id &&
            $meal->user_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check if meal is locked
        if ($meal->isLocked()) {
            return response()->json([
                'message' => 'Meal is locked and cannot be modified'
            ], 400);
        }

        // Check if meal entry is still allowed
        if (!Meal::isMealEntryAllowed($meal->mess_id, $meal->meal_date)) {
            return response()->json([
                'message' => 'Meal modification not allowed. Past cutoff time or date is in the past.'
            ], 400);
        }

        try {
            $meal->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Meal updated successfully',
                'data' => $meal->fresh()->load(['user', 'enteredBy'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update meal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified meal.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $meal = Meal::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $meal->mess->manager_id !== $user->id &&
            $meal->user_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check if meal is locked
        if ($meal->isLocked()) {
            return response()->json([
                'message' => 'Meal is locked and cannot be deleted'
            ], 400);
        }

        // Check if meal entry is still allowed
        if (!Meal::isMealEntryAllowed($meal->mess_id, $meal->meal_date)) {
            return response()->json([
                'message' => 'Meal deletion not allowed. Past cutoff time or date is in the past.'
            ], 400);
        }

        try {
            $meal->delete();

            return response()->json([
                'success' => true,
                'message' => 'Meal deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete meal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get today's meals for a mess.
     */
    public function today(Request $request)
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

        $todayMeals = $mess->meals()
            ->today()
            ->with(['user', 'enteredBy', 'lockedBy'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $todayMeals,
            'date' => today()->format('Y-m-d'),
            'is_cutoff_passed' => now()->gt($mess->meal_cutoff_time)
        ]);
    }

    /**
     * Enter today's meal for current user.
     */
    public function enterToday(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'breakfast' => 'required|integer|min:0|max:10',
            'lunch' => 'required|integer|min:0|max:10',
            'dinner' => 'required|integer|min:0|max:10',
            'extra_items' => 'nullable|array',
            'extra_items.*.name' => 'required|string|max:255',
            'extra_items.*.quantity' => 'required|integer|min:1',
            'extra_items.*.price' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check if user is a member of this mess
        if (!$mess->members()->where('user_id', $user->id)->where('status', 'approved')->exists()) {
            return response()->json(['message' => 'You are not an active member of this mess'], 403);
        }

        // Check if meal entry is allowed for today
        if (!Meal::isMealEntryAllowed($validated['mess_id'], today())) {
            return response()->json([
                'message' => 'Meal entry not allowed. Past cutoff time.'
            ], 400);
        }

        // Check if meal already exists for today
        $existingMeal = Meal::where('mess_id', $validated['mess_id'])
            ->where('user_id', $user->id)
            ->whereDate('meal_date', today())
            ->first();

        if ($existingMeal) {
            return response()->json([
                'message' => 'Meal already entered for today',
                'existing_meal' => $existingMeal
            ], 400);
        }

        try {
            DB::beginTransaction();

            $meal = Meal::create([
                'mess_id' => $validated['mess_id'],
                'user_id' => $user->id,
                'meal_date' => today(),
                'breakfast' => $validated['breakfast'],
                'lunch' => $validated['lunch'],
                'dinner' => $validated['dinner'],
                'extra_items' => $validated['extra_items'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'entered_by' => $user->id
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Today\'s meal entered successfully',
                'data' => $meal->load(['user', 'enteredBy'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to enter today\'s meal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lock meals for a specific date.
     */
    public function lock(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'meal_date' => 'required|date',
            'force' => 'boolean' // Allow force locking even after cutoff
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization (only managers can lock meals)
        if (!$user->hasRole('super_admin') && $mess->manager_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $mealDate = is_string($validated['meal_date']) ?
            \Carbon\Carbon::parse($validated['meal_date']) : $validated['meal_date'];

        // Check if cutoff time has passed (unless force is true)
        if (!$validated['force'] && $mealDate->isToday() && !now()->gt($mess->meal_cutoff_time)) {
            return response()->json([
                'message' => 'Cannot lock meals before cutoff time. Use force=true to override.'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $meals = $mess->meals()
                ->forDate($mealDate)
                ->unlocked()
                ->get();

            foreach ($meals as $meal) {
                $meal->lock($user->id);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Successfully locked {$meals->count()} meals for {$mealDate->format('Y-m-d')}",
                'locked_count' => $meals->count()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to lock meals: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get meal summary for a date range.
     */
    public function summary(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'group_by' => 'nullable|in:date,user,none'
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

        $groupBy = $validated['group_by'] ?? 'date';
        $summary = $mess->meals()
            ->whereBetween('meal_date', [$validated['date_from'], $validated['date_to']])
            ->with(['user'])
            ->get();

        $data = [];

        if ($groupBy === 'date') {
            $data = $summary->groupBy('meal_date')->map(function ($meals, $date) {
                return [
                    'date' => $date,
                    'total_breakfast' => $meals->sum('breakfast'),
                    'total_lunch' => $meals->sum('lunch'),
                    'total_dinner' => $meals->sum('dinner'),
                    'total_meals' => $meals->sum(function ($meal) {
                        return $meal->breakfast + $meal->lunch + $meal->dinner;
                    }),
                    'unique_members' => $meals->pluck('user_id')->unique()->count(),
                    'meals' => $meals
                ];
            })->values();
        } elseif ($groupBy === 'user') {
            $data = $summary->groupBy('user_id')->map(function ($meals, $userId) {
                $user = $meals->first()->user;
                return [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email
                    ],
                    'total_breakfast' => $meals->sum('breakfast'),
                    'total_lunch' => $meals->sum('lunch'),
                    'total_dinner' => $meals->sum('dinner'),
                    'total_meals' => $meals->sum(function ($meal) {
                        return $meal->breakfast + $meal->lunch + $meal->dinner;
                    }),
                    'total_cost' => $meals->sum('total_cost'),
                    'days_with_meals' => $meals->count(),
                    'meals' => $meals
                ];
            })->values();
        } else {
            $data = [
                'total_breakfast' => $summary->sum('breakfast'),
                'total_lunch' => $summary->sum('lunch'),
                'total_dinner' => $summary->sum('dinner'),
                'total_meals' => $summary->sum(function ($meal) {
                    return $meal->breakfast + $meal->lunch + $meal->dinner;
                }),
                'unique_members' => $summary->pluck('user_id')->unique()->count(),
                'total_cost' => $summary->sum('total_cost'),
                'meals' => $summary
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'period' => [
                'date_from' => $validated['date_from'],
                'date_to' => $validated['date_to'],
                'group_by' => $groupBy
            ]
        ]);
    }
}

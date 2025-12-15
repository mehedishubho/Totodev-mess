<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mess;
use App\Models\MessMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class MessController extends Controller
{
    /**
     * Display a listing of messes.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        if ($user->hasRole('super_admin')) {
            $messes = Mess::with(['manager', 'membersCount'])->paginate($request->per_page ?? 15);
        } elseif ($user->hasRole('admin')) {
            $messes = Mess::where('manager_id', $user->id)
                ->with(['manager', 'membersCount'])
                ->paginate($request->per_page ?? 15);
        } else {
            // Members can only see their own mess
            $member = MessMember::where('user_id', $user->id)->first();
            if (!$member) {
                return response()->json(['message' => 'You are not a member of any mess'], 403);
            }

            $messes = Mess::where('id', $member->mess_id)
                ->with(['manager', 'membersCount'])
                ->paginate($request->per_page ?? 15);
        }

        return response()->json([
            'success' => true,
            'data' => $messes
        ]);
    }

    /**
     * Store a newly created mess.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Mess::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'breakfast_rate' => 'required|numeric|min:0',
            'lunch_rate' => 'required|numeric|min:0',
            'dinner_rate' => 'required|numeric|min:0',
            'payment_cycle' => 'required|in:weekly,monthly',
            'meal_cutoff_time' => 'required|date_format:H:i',
            'max_members' => 'nullable|integer|min:1',
            'auto_bazar_rotation' => 'boolean',
            'bazar_rotation_days' => 'nullable|array',
            'bazar_rotation_days.*' => 'integer|min:1|max:7',
            'settings' => 'nullable|array',
            'status' => 'boolean'
        ]);

        try {
            DB::beginTransaction();

            // Handle logo upload
            if ($request->hasFile('logo')) {
                $logoPath = $request->file('logo')->store('mess_logos', 'public');
                $validated['logo'] = $logoPath;
            }

            // Set default values
            $validated['manager_id'] = Auth::id();
            $validated['status'] = $validated['status'] ?? true;
            $validated['auto_bazar_rotation'] = $validated['auto_bazar_rotation'] ?? true;

            $mess = Mess::create($validated);

            // Add manager as a member automatically
            MessMember::create([
                'mess_id' => $mess->id,
                'user_id' => Auth::id(),
                'role' => 'admin',
                'status' => 'approved',
                'joined_at' => now(),
                'approved_by' => Auth::id(),
                'approved_at' => now()
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Mess created successfully',
                'data' => $mess->load(['manager', 'members'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            // Remove uploaded logo if transaction failed
            if (isset($logoPath)) {
                Storage::disk('public')->delete($logoPath);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to create mess: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified mess.
     */
    public function show($id)
    {
        $user = Auth::user();

        $mess = Mess::with(['manager', 'members.user', 'members.approvedBy'])
            ->findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            !$user->hasRole('admin') &&
            !$mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $mess
        ]);
    }

    /**
     * Update the specified mess.
     */
    public function update(Request $request, $id)
    {
        $mess = Mess::findOrFail($id);
        $this->authorize('update', $mess);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'address' => 'sometimes|required|string',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'breakfast_rate' => 'sometimes|required|numeric|min:0',
            'lunch_rate' => 'sometimes|required|numeric|min:0',
            'dinner_rate' => 'sometimes|required|numeric|min:0',
            'payment_cycle' => 'sometimes|required|in:weekly,monthly',
            'meal_cutoff_time' => 'sometimes|required|date_format:H:i',
            'max_members' => 'nullable|integer|min:1',
            'auto_bazar_rotation' => 'boolean',
            'bazar_rotation_days' => 'nullable|array',
            'bazar_rotation_days.*' => 'integer|min:1|max:7',
            'settings' => 'nullable|array',
            'status' => 'boolean'
        ]);

        try {
            DB::beginTransaction();

            // Handle logo upload
            if ($request->hasFile('logo')) {
                // Delete old logo
                if ($mess->logo) {
                    Storage::disk('public')->delete($mess->logo);
                }

                $logoPath = $request->file('logo')->store('mess_logos', 'public');
                $validated['logo'] = $logoPath;
            }

            $mess->update($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Mess updated successfully',
                'data' => $mess->fresh()->load(['manager', 'members'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            // Remove uploaded logo if transaction failed
            if (isset($logoPath)) {
                Storage::disk('public')->delete($logoPath);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to update mess: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified mess.
     */
    public function destroy($id)
    {
        $mess = Mess::findOrFail($id);
        $this->authorize('delete', $mess);

        try {
            DB::beginTransaction();

            // Check if mess has active members
            if ($mess->members()->where('status', 'approved')->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete mess with active members'
                ], 400);
            }

            // Delete logo if exists
            if ($mess->logo) {
                Storage::disk('public')->delete($mess->logo);
            }

            $mess->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Mess deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete mess: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get mess statistics.
     */
    public function statistics($id)
    {
        $user = Auth::user();
        $mess = Mess::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            !$mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $stats = [
            'total_members' => $mess->members()->where('status', 'approved')->count(),
            'pending_members' => $mess->members()->where('status', 'pending')->count(),
            'active_members' => $mess->members()->where('status', 'approved')->count(),
            'total_meals_today' => $mess->meals()->whereDate('meal_date', today())->count(),
            'total_bazar_this_month' => $mess->bazars()->whereMonth('bazar_date', now()->month)->sum('total_cost'),
            'monthly_meal_rate' => [
                'breakfast' => $mess->breakfast_rate,
                'lunch' => $mess->lunch_rate,
                'dinner' => $mess->dinner_rate,
                'total_daily' => $mess->breakfast_rate + $mess->lunch_rate + $mess->dinner_rate
            ],
            'next_bazar_person' => $this->getNextBazarPerson($mess),
            'monthly_expense_trend' => $this->getMonthlyExpenseTrend($mess)
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Add member to mess.
     */
    public function addMember(Request $request, $messId)
    {
        $mess = Mess::findOrFail($messId);
        $this->authorize('update', $mess);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'required|in:member,staff',
            'room_number' => 'nullable|string|max:50',
            'status' => 'sometimes|in:pending,approved'
        ]);

        // Check if user is already a member
        if ($mess->members()->where('user_id', $validated['user_id'])->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'User is already a member of this mess'
            ], 400);
        }

        // Check max members limit
        if ($mess->max_members && $mess->members()->where('status', 'approved')->count() >= $mess->max_members) {
            return response()->json([
                'success' => false,
                'message' => 'Mess has reached maximum member limit'
            ], 400);
        }

        try {
            $member = MessMember::create([
                'mess_id' => $mess->id,
                'user_id' => $validated['user_id'],
                'role' => $validated['role'],
                'room_number' => $validated['room_number'] ?? null,
                'status' => $validated['status'] ?? 'pending',
                'joined_at' => now(),
                'approved_by' => Auth::id(),
                'approved_at' => $validated['status'] === 'approved' ? now() : null
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Member added successfully',
                'data' => $member->load('user')
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add member: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove member from mess.
     */
    public function removeMember($messId, $memberId)
    {
        $mess = Mess::findOrFail($messId);
        $this->authorize('update', $mess);

        $member = $mess->members()->findOrFail($memberId);

        // Don't allow removing the manager
        if ($member->user_id === $mess->manager_id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot remove mess manager'
            ], 400);
        }

        try {
            $member->delete();

            return response()->json([
                'success' => true,
                'message' => 'Member removed successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove member: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get next bazar person.
     */
    private function getNextBazarPerson($mess)
    {
        if (!$mess->auto_bazar_rotation) {
            return null;
        }

        $lastBazar = $mess->bazars()->orderBy('bazar_date', 'desc')->first();

        if (!$lastBazar) {
            // Get first member if no bazar history
            $firstMember = $mess->members()->where('status', 'approved')->first();
            return $firstMember ? $firstMember->user : null;
        }

        // Get next member in rotation
        $members = $mess->members()->where('status', 'approved')->get();
        $currentIndex = $members->search(function ($member) use ($lastBazar) {
            return $member->user_id === $lastBazar->bazar_person_id;
        });

        $nextIndex = ($currentIndex + 1) % $members->count();
        $nextMember = $members->get($nextIndex);

        return $nextMember ? $nextMember->user : null;
    }

    /**
     * Get monthly expense trend.
     */
    private function getMonthlyExpenseTrend($mess)
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

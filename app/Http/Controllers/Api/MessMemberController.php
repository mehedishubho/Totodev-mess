<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mess;
use App\Models\MessMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class MessMemberController extends Controller
{
    /**
     * Display a listing of mess members.
     */
    public function index(Request $request, $messId)
    {
        $user = Auth::user();
        $mess = Mess::findOrFail($messId);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            !$mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = $mess->members()->with(['user', 'approvedBy']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by role
        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        $members = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $members
        ]);
    }

    /**
     * Store a newly created mess member.
     */
    public function store(Request $request, $messId)
    {
        $mess = Mess::findOrFail($messId);
        $this->authorize('update', $mess);

        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'name' => 'required_without:user_id|string|max:255',
            'email' => 'required_without:user_id|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'role' => 'required|in:member,staff',
            'room_number' => 'nullable|string|max:50',
            'status' => 'sometimes|in:pending,approved',
            'monthly_fixed_cost' => 'nullable|numeric|min:0',
            'deposit_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000'
        ]);

        try {
            DB::beginTransaction();

            // Create user if not exists
            if (!isset($validated['user_id'])) {
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'] ?? null,
                    'password' => Hash::make('password123'), // Default password
                    'email_verified_at' => now()
                ]);

                // Assign member role
                $user->assignRole('member');
                $validated['user_id'] = $user->id;
            }

            // Check if user is already a member
            if ($mess->members()->where('user_id', $validated['user_id'])->exists()) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'User is already a member of this mess'
                ], 400);
            }

            // Check max members limit
            if ($mess->hasReachedMaxMembers()) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Mess has reached maximum member limit'
                ], 400);
            }

            $member = MessMember::create([
                'mess_id' => $mess->id,
                'user_id' => $validated['user_id'],
                'role' => $validated['role'],
                'room_number' => $validated['room_number'] ?? null,
                'status' => $validated['status'] ?? 'pending',
                'monthly_fixed_cost' => $validated['monthly_fixed_cost'] ?? null,
                'deposit_amount' => $validated['deposit_amount'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'joined_at' => now(),
                'approved_by' => $validated['status'] === 'approved' ? Auth::id() : null,
                'approved_at' => $validated['status'] === 'approved' ? now() : null
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Member added successfully',
                'data' => $member->load(['user', 'approvedBy'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to add member: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified mess member.
     */
    public function show($messId, $memberId)
    {
        $user = Auth::user();
        $mess = Mess::findOrFail($messId);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            $user->id !== $memberId
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $member = $mess->members()
            ->with(['user', 'approvedBy', 'meals', 'bazars', 'payments'])
            ->findOrFail($memberId);

        // Add member statistics
        $member->statistics = $member->getStatistics();

        return response()->json([
            'success' => true,
            'data' => $member
        ]);
    }

    /**
     * Update the specified mess member.
     */
    public function update(Request $request, $messId, $memberId)
    {
        $mess = Mess::findOrFail($messId);
        $this->authorize('update', $mess);

        $member = $mess->members()->findOrFail($memberId);

        $validated = $request->validate([
            'role' => 'sometimes|required|in:member,staff',
            'room_number' => 'nullable|string|max:50',
            'status' => 'sometimes|required|in:pending,approved,rejected,left',
            'monthly_fixed_cost' => 'nullable|numeric|min:0',
            'deposit_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'settings' => 'nullable|array'
        ]);

        try {
            DB::beginTransaction();

            // Handle status changes
            if (isset($validated['status'])) {
                switch ($validated['status']) {
                    case 'approved':
                        $member->approve(Auth::id());
                        break;
                    case 'rejected':
                        $member->reject();
                        break;
                    case 'left':
                        $member->leave();
                        break;
                }
                unset($validated['status']); // Remove from update data
            }

            $member->update($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Member updated successfully',
                'data' => $member->fresh()->load(['user', 'approvedBy'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update member: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified mess member.
     */
    public function destroy($messId, $memberId)
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
     * Approve a pending member.
     */
    public function approve($messId, $memberId)
    {
        $mess = Mess::findOrFail($messId);
        $this->authorize('update', $mess);

        $member = $mess->members()->findOrFail($memberId);

        if (!$member->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Member is not pending approval'
            ], 400);
        }

        try {
            $member->approve(Auth::id());

            return response()->json([
                'success' => true,
                'message' => 'Member approved successfully',
                'data' => $member->fresh()->load(['user', 'approvedBy'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve member: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject a pending member.
     */
    public function reject($messId, $memberId)
    {
        $mess = Mess::findOrFail($messId);
        $this->authorize('update', $mess);

        $member = $mess->members()->findOrFail($memberId);

        if (!$member->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Member is not pending approval'
            ], 400);
        }

        try {
            $member->reject();

            return response()->json([
                'success' => true,
                'message' => 'Member rejected successfully',
                'data' => $member->fresh()->load(['user'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject member: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get member statistics.
     */
    public function statistics($messId, $memberId)
    {
        $user = Auth::user();
        $mess = Mess::findOrFail($messId);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            $user->id !== $memberId
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $member = $mess->members()->findOrFail($memberId);

        $statistics = $member->getStatistics();

        // Add additional statistics
        $statistics['member_since'] = $member->joined_at ? $member->joined_at->format('M d, Y') : null;
        $statistics['approval_status'] = $member->status;
        $statistics['room_number'] = $member->room_number;
        $statistics['monthly_fixed_cost'] = $member->monthly_fixed_cost;
        $statistics['deposit_amount'] = $member->deposit_amount;

        return response()->json([
            'success' => true,
            'data' => $statistics
        ]);
    }

    /**
     * Bulk operations on members.
     */
    public function bulkAction(Request $request, $messId)
    {
        $mess = Mess::findOrFail($messId);
        $this->authorize('update', $mess);

        $validated = $request->validate([
            'action' => 'required|in:approve,reject,delete',
            'member_ids' => 'required|array',
            'member_ids.*' => 'exists:mess_members,id'
        ]);

        try {
            DB::beginTransaction();

            $members = $mess->members()->whereIn('id', $validated['member_ids'])->get();
            $processedCount = 0;

            foreach ($members as $member) {
                // Don't allow bulk operations on manager
                if ($member->user_id === $mess->manager_id) {
                    continue;
                }

                switch ($validated['action']) {
                    case 'approve':
                        if ($member->isPending()) {
                            $member->approve(Auth::id());
                            $processedCount++;
                        }
                        break;
                    case 'reject':
                        if ($member->isPending()) {
                            $member->reject();
                            $processedCount++;
                        }
                        break;
                    case 'delete':
                        $member->delete();
                        $processedCount++;
                        break;
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Successfully processed {$processedCount} members",
                'processed_count' => $processedCount
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to process bulk action: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Search members.
     */
    public function search(Request $request, $messId)
    {
        $user = Auth::user();
        $mess = Mess::findOrFail($messId);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            !$mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'query' => 'required|string|min:2',
            'status' => 'nullable|in:pending,approved,rejected,left',
            'role' => 'nullable|in:member,staff'
        ]);

        $query = $validated['query'];

        $members = $mess->members()
            ->with(['user'])
            ->whereHas('user', function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%")
                    ->orWhere('phone', 'like', "%{$query}%");
            })
            ->when(isset($validated['status']), function ($q) use ($validated) {
                $q->where('status', $validated['status']);
            })
            ->when(isset($validated['role']), function ($q) use ($validated) {
                $q->where('role', $validated['role']);
            })
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $members
        ]);
    }
}

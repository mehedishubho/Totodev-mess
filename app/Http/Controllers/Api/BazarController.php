<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bazar;
use App\Models\Mess;
use App\Models\MessMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BazarController extends Controller
{
    /**
     * Display a listing of bazars.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'bazar_person_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:approved,pending,all',
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

        $query = $mess->bazars()->with(['bazarPerson', 'createdBy', 'approvedBy']);

        // Filter by date range
        if (isset($validated['date_from'])) {
            $query->whereDate('bazar_date', '>=', $validated['date_from']);
        }
        if (isset($validated['date_to'])) {
            $query->whereDate('bazar_date', '<=', $validated['date_to']);
        }

        // Filter by bazar person
        if (isset($validated['bazar_person_id'])) {
            // Only allow filtering by bazar person if authorized
            if (
                !$user->hasRole('super_admin') &&
                $mess->manager_id !== $user->id &&
                $validated['bazar_person_id'] !== $user->id
            ) {
                return response()->json(['message' => 'Unauthorized to filter by bazar person'], 403);
            }
            $query->where('bazar_person_id', $validated['bazar_person_id']);
        }

        // Filter by status
        if (isset($validated['status'])) {
            if ($validated['status'] === 'approved') {
                $query->approved();
            } elseif ($validated['status'] === 'pending') {
                $query->pending();
            }
        }

        $bazars = $query->orderBy('bazar_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => $bazars
        ]);
    }

    /**
     * Store a newly created bazar.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'bazar_person_id' => 'required|exists:users,id',
            'bazar_date' => 'required|date|after_or_equal:today',
            'item_list' => 'required|array|min:1',
            'item_list.*.name' => 'required|string|max:255',
            'item_list.*.quantity' => 'required|numeric|min:0.01',
            'item_list.*.unit' => 'nullable|string|max:50',
            'item_list.*.price' => 'required|numeric|min:0',
            'total_cost' => 'required|numeric|min:0',
            'receipt_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'notes' => 'nullable|string|max:1000'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            $validated['bazar_person_id'] !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check if bazar person is a member of the mess
        if (!$mess->members()->where('user_id', $validated['bazar_person_id'])->where('status', 'approved')->exists()) {
            return response()->json(['message' => 'Bazar person is not an active member of this mess'], 400);
        }

        // Validate total cost matches calculated cost
        $calculatedCost = collect($validated['item_list'])->sum(function ($item) {
            return ($item['quantity'] ?? 0) * ($item['price'] ?? 0);
        });

        if (abs($calculatedCost - $validated['total_cost']) > 0.01) {
            return response()->json([
                'message' => 'Total cost does not match calculated cost from items',
                'calculated_cost' => $calculatedCost,
                'provided_cost' => $validated['total_cost']
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Handle receipt upload
            $receiptPath = null;
            if ($request->hasFile('receipt_image')) {
                $receiptPath = $request->file('receipt_image')->store('bazar_receipts', 'public');
            }

            $bazar = Bazar::create([
                'mess_id' => $validated['mess_id'],
                'bazar_person_id' => $validated['bazar_person_id'],
                'bazar_date' => $validated['bazar_date'],
                'item_list' => $validated['item_list'],
                'total_cost' => $validated['total_cost'],
                'receipt_image' => $receiptPath,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bazar created successfully',
                'data' => $bazar->load(['bazarPerson', 'createdBy'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            // Remove uploaded receipt if transaction failed
            if ($receiptPath) {
                Storage::disk('public')->delete($receiptPath);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to create bazar: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified bazar.
     */
    public function show($id)
    {
        $user = Auth::user();
        $bazar = Bazar::with(['mess', 'bazarPerson', 'createdBy', 'approvedBy'])->findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $bazar->mess->manager_id !== $user->id &&
            $bazar->bazar_person_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $bazar
        ]);
    }

    /**
     * Update the specified bazar.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'bazar_person_id' => 'sometimes|required|exists:users,id',
            'bazar_date' => 'sometimes|required|date',
            'item_list' => 'sometimes|required|array|min:1',
            'item_list.*.name' => 'required|string|max:255',
            'item_list.*.quantity' => 'required|numeric|min:0.01',
            'item_list.*.unit' => 'nullable|string|max:50',
            'item_list.*.price' => 'required|numeric|min:0',
            'total_cost' => 'sometimes|required|numeric|min:0',
            'notes' => 'nullable|string|max:1000'
        ]);

        $user = Auth::user();
        $bazar = Bazar::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $bazar->mess->manager_id !== $user->id &&
            $bazar->bazar_person_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Don't allow updating approved bazars (only managers can)
        if ($bazar->isApproved() && !$user->hasRole('super_admin') && $bazar->mess->manager_id !== $user->id) {
            return response()->json(['message' => 'Cannot update approved bazar'], 400);
        }

        // Validate total cost if item list is provided
        if (isset($validated['item_list']) && isset($validated['total_cost'])) {
            $calculatedCost = collect($validated['item_list'])->sum(function ($item) {
                return ($item['quantity'] ?? 0) * ($item['price'] ?? 0);
            });

            if (abs($calculatedCost - $validated['total_cost']) > 0.01) {
                return response()->json([
                    'message' => 'Total cost does not match calculated cost from items',
                    'calculated_cost' => $calculatedCost,
                    'provided_cost' => $validated['total_cost']
                ], 400);
            }
        }

        try {
            $bazar->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Bazar updated successfully',
                'data' => $bazar->fresh()->load(['bazarPerson', 'createdBy'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update bazar: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified bazar.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $bazar = Bazar::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $bazar->mess->manager_id !== $user->id &&
            $bazar->bazar_person_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Don't allow deleting approved bazars (only managers can)
        if ($bazar->isApproved() && !$user->hasRole('super_admin') && $bazar->mess->manager_id !== $user->id) {
            return response()->json(['message' => 'Cannot delete approved bazar'], 400);
        }

        try {
            // Delete receipt image if exists
            if ($bazar->receipt_image) {
                Storage::disk('public')->delete($bazar->receipt_image);
            }

            $bazar->delete();

            return response()->json([
                'success' => true,
                'message' => 'Bazar deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete bazar: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload receipt image for bazar.
     */
    public function uploadReceipt(Request $request, $id)
    {
        $validated = $request->validate([
            'receipt_image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        $user = Auth::user();
        $bazar = Bazar::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $bazar->mess->manager_id !== $user->id &&
            $bazar->bazar_person_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            // Delete old receipt if exists
            if ($bazar->receipt_image) {
                Storage::disk('public')->delete($bazar->receipt_image);
            }

            // Upload new receipt
            $receiptPath = $request->file('receipt_image')->store('bazar_receipts', 'public');

            $bazar->update(['receipt_image' => $receiptPath]);

            return response()->json([
                'success' => true,
                'message' => 'Receipt uploaded successfully',
                'data' => [
                    'receipt_url' => $bazar->receipt_url
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload receipt: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve bazar.
     */
    public function approve($id)
    {
        $user = Auth::user();
        $bazar = Bazar::findOrFail($id);

        // Check authorization (only managers can approve)
        if (!$user->hasRole('super_admin') && $bazar->mess->manager_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($bazar->isApproved()) {
            return response()->json([
                'success' => false,
                'message' => 'Bazar is already approved'
            ], 400);
        }

        try {
            $bazar->approve($user->id);

            return response()->json([
                'success' => true,
                'message' => 'Bazar approved successfully',
                'data' => $bazar->fresh()->load(['bazarPerson', 'approvedBy'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve bazar: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get bazar report.
     */
    public function report(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'year' => 'required|integer|min:2020|max:' . date('Y'),
            'month' => 'required|integer|min:1|max:12',
            'group_by' => 'nullable|in:date,person,none'
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
        $bazars = $mess->bazars()
            ->forMonth($validated['year'], $validated['month'])
            ->with(['bazarPerson', 'createdBy', 'approvedBy'])
            ->orderBy('bazar_date')
            ->get();

        $data = [];

        if ($groupBy === 'date') {
            $data = $bazars->groupBy('bazar_date')->map(function ($dateBazars, $date) {
                return [
                    'date' => $date,
                    'formatted_date' => \Carbon\Carbon::parse($date)->format('M d, Y'),
                    'total_cost' => $dateBazars->sum('total_cost'),
                    'bazars_count' => $dateBazars->count(),
                    'bazars' => $dateBazars
                ];
            })->values();
        } elseif ($groupBy === 'person') {
            $data = $bazars->groupBy('bazar_person_id')->map(function ($personBazars, $personId) {
                $person = $personBazars->first()->bazarPerson;
                return [
                    'person' => [
                        'id' => $person->id,
                        'name' => $person->name,
                        'email' => $person->email
                    ],
                    'total_cost' => $personBazars->sum('total_cost'),
                    'bazars_count' => $personBazars->count(),
                    'average_cost' => $personBazars->sum('total_cost') / $personBazars->count(),
                    'bazars' => $personBazars
                ];
            })->values();
        } else {
            $data = [
                'total_cost' => $bazars->sum('total_cost'),
                'bazars_count' => $bazars->count(),
                'average_cost' => $bazars->count() > 0 ? $bazars->sum('total_cost') / $bazars->count() : 0,
                'pending_bazars' => $bazars->whereNull('approved_at')->count(),
                'approved_bazars' => $bazars->whereNotNull('approved_at')->count(),
                'bazars' => $bazars
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'period' => [
                'year' => $validated['year'],
                'month' => $validated['month'],
                'month_name' => \Carbon\Carbon::create($validated['year'], $validated['month'], 1)->format('F Y'),
                'group_by' => $groupBy
            ]
        ]);
    }

    /**
     * Get upcoming bazars.
     */
    public function upcoming(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'limit' => 'nullable|integer|min:1|max:20'
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

        $upcomingBazars = $mess->bazars()
            ->upcoming()
            ->with(['bazarPerson'])
            ->orderBy('bazar_date')
            ->limit($validated['limit'] ?? 5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $upcomingBazars
        ]);
    }

    /**
     * Get recent bazars.
     */
    public function recent(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'limit' => 'nullable|integer|min:1|max:50'
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

        $recentBazars = $mess->bazars()
            ->past()
            ->with(['bazarPerson', 'approvedBy'])
            ->orderBy('bazar_date', 'desc')
            ->limit($validated['limit'] ?? 10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $recentBazars
        ]);
    }
}

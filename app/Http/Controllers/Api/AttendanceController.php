<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\QRCode;
use App\Models\Mess;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    /**
     * Display a listing of attendances.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'status' => 'nullable|in:pending,approved,rejected,all',
            'meal_type' => 'nullable|in:breakfast,lunch,dinner',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'user_id' => 'nullable|exists:users,id',
            'is_manual_entry' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100'
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

        $query = Attendance::where('mess_id', $validated['mess_id'])
            ->with(['user', 'approvedBy', 'scannedBy']);

        // Apply filters
        if (isset($validated['status']) && $validated['status'] !== 'all') {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['meal_type'])) {
            $query->where('meal_type', $validated['meal_type']);
        }

        if (isset($validated['date_from'])) {
            $query->whereDate('meal_date', '>=', $validated['date_from']);
        }

        if (isset($validated['date_to'])) {
            $query->whereDate('meal_date', '<=', $validated['date_to']);
        }

        if (isset($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        if (isset($validated['is_manual_entry'])) {
            $query->where('is_manual_entry', $validated['is_manual_entry']);
        }

        // Members can only see their own attendance
        if (!$user->hasRole('super_admin') && $mess->manager_id !== $user->id) {
            $query->where('user_id', $user->id);
        }

        $attendances = $query->orderBy('meal_date', 'desc')
            ->orderBy('scan_time', 'desc')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => $attendances
        ]);
    }

    /**
     * Store a newly created attendance (manual entry).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'user_id' => 'required|exists:users,id',
            'meal_type' => 'required|in:breakfast,lunch,dinner',
            'meal_date' => 'required|date',
            'notes' => 'nullable|string',
            'device_info' => 'nullable|array',
            'location' => 'nullable|string'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization - only managers and super admin can create manual entries
        if (!$user->hasRole('super_admin') && $mess->manager_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized to create manual attendance'], 403);
        }

        // Check for duplicate attendance
        if (Attendance::isDuplicate(
            $validated['user_id'],
            $validated['meal_date'],
            $validated['meal_type'],
            $validated['mess_id']
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Attendance already exists for this meal'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $attendance = Attendance::create([
                'mess_id' => $validated['mess_id'],
                'user_id' => $validated['user_id'],
                'meal_type' => $validated['meal_type'],
                'meal_date' => $validated['meal_date'],
                'scan_time' => now(),
                'status' => 'approved', // Manual entries are auto-approved
                'approved_by' => $user->id,
                'approved_at' => now(),
                'notes' => $validated['notes'],
                'device_info' => $validated['device_info'],
                'location' => $validated['location'],
                'is_manual_entry' => true,
                'scanned_by' => $user->id
            ]);

            // Update corresponding meal entry
            $attendance->updateMealEntry();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Manual attendance created successfully',
                'data' => $attendance->load(['user', 'approvedBy'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create attendance: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified attendance.
     */
    public function show($id)
    {
        $user = Auth::user();
        $attendance = Attendance::with(['user', 'mess', 'approvedBy', 'scannedBy', 'meal'])
            ->findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $attendance->mess->manager_id !== $user->id &&
            $attendance->user_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $attendance
        ]);
    }

    /**
     * Update the specified attendance.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'notes' => 'sometimes|nullable|string',
            'device_info' => 'sometimes|nullable|array',
            'location' => 'sometimes|nullable|string'
        ]);

        $user = Auth::user();
        $attendance = Attendance::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $attendance->mess->manager_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized to update attendance'], 403);
        }

        // Only allow updating pending attendances
        if ($attendance->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update approved or rejected attendance'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $attendance->update($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Attendance updated successfully',
                'data' => $attendance->fresh()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update attendance: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified attendance.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $attendance = Attendance::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $attendance->mess->manager_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized to delete attendance'], 403);
        }

        try {
            DB::beginTransaction();

            // Soft delete the attendance
            $attendance->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Attendance deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete attendance: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Scan QR code for attendance.
     */
    public function scanQR(Request $request)
    {
        $validated = $request->validate([
            'qr_code' => 'required|string',
            'device_info' => 'nullable|array',
            'location' => 'nullable|string'
        ]);

        $user = Auth::user();

        // Validate QR code
        $qrData = QRCode::validateQRData($validated['qr_code']);

        if (!$qrData['valid']) {
            return response()->json([
                'success' => false,
                'message' => $qrData['message']
            ], 400);
        }

        $data = $qrData['data'];

        // Check if user is authorized to scan in this mess
        if (
            !$user->hasRole('super_admin') &&
            $data['mess_id'] !== $user->mess_id &&
            $user->mess_id !== null
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized mess access'
            ], 403);
        }

        // Check for duplicate attendance
        if (Attendance::isDuplicate(
            $data['user_id'],
            $data['meal_date'],
            $data['meal_type'],
            $data['mess_id']
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Attendance already recorded for this meal'
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Get user and mess details
            $attendanceUser = User::findOrFail($data['user_id']);
            $mess = Mess::findOrFail($data['mess_id']);

            // Create attendance record
            $attendance = Attendance::create([
                'mess_id' => $data['mess_id'],
                'user_id' => $data['user_id'],
                'meal_type' => $data['meal_type'],
                'meal_date' => $data['meal_date'],
                'scan_time' => now(),
                'qr_code' => $validated['qr_code'],
                'status' => 'pending', // QR scans require approval
                'device_info' => $validated['device_info'],
                'location' => $validated['location'],
                'is_manual_entry' => false,
                'scanned_by' => $user->id
            ]);

            // Use the QR code if it exists in database
            if (isset($data['qr_token'])) {
                $qrCode = QRCode::where('qr_token', $data['qr_token'])->first();
                if ($qrCode) {
                    $qrCode->useQR();
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'QR code scanned successfully',
                'data' => [
                    'attendance' => $attendance->load(['user']),
                    'user' => $attendanceUser,
                    'mess' => $mess,
                    'requires_approval' => true
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to scan QR code: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve attendance.
     */
    public function approve($id)
    {
        $validated = request()->validate([
            'notes' => 'nullable|string'
        ]);

        $user = Auth::user();
        $attendance = Attendance::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $attendance->mess->manager_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized to approve attendance'], 403);
        }

        // Check if attendance can be approved
        if (!$attendance->can_be_approved) {
            return response()->json([
                'success' => false,
                'message' => 'Attendance cannot be approved'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $attendance->approve($user->id, $validated['notes'] ?? null);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Attendance approved successfully',
                'data' => $attendance->fresh()->load(['user', 'approvedBy'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve attendance: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject attendance.
     */
    public function reject($id)
    {
        $validated = request()->validate([
            'reason' => 'required|string'
        ]);

        $user = Auth::user();
        $attendance = Attendance::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $attendance->mess->manager_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized to reject attendance'], 403);
        }

        // Check if attendance can be rejected
        if (!$attendance->can_be_approved) {
            return response()->json([
                'success' => false,
                'message' => 'Attendance cannot be rejected'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $attendance->reject($user->id, $validated['reason']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Attendance rejected successfully',
                'data' => $attendance->fresh()->load(['user', 'approvedBy'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject attendance: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get pending attendances for approval.
     */
    public function pending(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization
        if (!$user->hasRole('super_admin') && $mess->manager_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $pendingAttendances = Attendance::getPendingAttendances($validated['mess_id']);

        return response()->json([
            'success' => true,
            'data' => $pendingAttendances
        ]);
    }

    /**
     * Get today's attendances.
     */
    public function today(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'status' => 'nullable|in:pending,approved,rejected',
            'meal_type' => 'nullable|in:breakfast,lunch,dinner'
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

        $todayAttendances = Attendance::getTodayAttendances(
            $validated['mess_id'],
            $validated['status'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => $todayAttendances
        ]);
    }

    /**
     * Get attendance statistics.
     */
    public function statistics(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'user_id' => 'nullable|exists:users,id'
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

        $startDate = $validated['date_from'] ?? now()->subDays(30);
        $endDate = $validated['date_to'] ?? now();

        // If user_id is provided and user is not admin/manager, only show own stats
        if (
            isset($validated['user_id']) &&
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id
        ) {
            if ($validated['user_id'] !== $user->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            $statistics = Attendance::getUserSummary($validated['user_id'], $startDate, $endDate);
        } else {
            $statistics = Attendance::getStatistics($validated['mess_id'], $startDate, $endDate);
        }

        return response()->json([
            'success' => true,
            'data' => $statistics
        ]);
    }

    /**
     * Generate QR code for user.
     */
    public function generateQR(Request $request)
    {
        $validated = $request->validate([
            'meal_date' => 'required|date|today_or_future',
            'meal_type' => 'required|in:breakfast,lunch,dinner',
            'purpose' => 'nullable|in:meal_attendance,mess_access'
        ]);

        $user = Auth::user();
        $purpose = $validated['purpose'] ?? 'meal_attendance';

        if ($purpose === 'meal_attendance') {
            // Check if QR already exists for today's meal
            $existingQR = QRCode::getTodayMealQR($user->id, $validated['meal_type']);
            if ($existingQR) {
                return response()->json([
                    'success' => true,
                    'message' => 'QR code already exists',
                    'data' => $existingQR
                ]);
            }

            $qrCode = QRCode::generateMealQR(
                $user,
                $validated['meal_date'],
                $validated['meal_type']
            );
        } else {
            $qrCode = QRCode::generateAccessQR($user);
        }

        return response()->json([
            'success' => true,
            'message' => 'QR code generated successfully',
            'data' => $qrCode
        ]);
    }

    /**
     * Get user's QR codes.
     */
    public function myQRCodes(Request $request)
    {
        $validated = $request->validate([
            'purpose' => 'nullable|in:meal_attendance,mess_access,guest_access'
        ]);

        $user = Auth::user();
        $qrCodes = QRCode::getActiveForUser($user->id, $validated['purpose'] ?? null);

        return response()->json([
            'success' => true,
            'data' => $qrCodes
        ]);
    }

    /**
     * Deactivate QR code.
     */
    public function deactivateQR($id)
    {
        $user = Auth::user();
        $qrCode = QRCode::findOrFail($id);

        // Check authorization
        if ($qrCode->user_id !== $user->id && !$user->hasRole('super_admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            $qrCode->deactivate();

            return response()->json([
                'success' => true,
                'message' => 'QR code deactivated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate QR code: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get attendance trends.
     */
    public function trends(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'days' => 'nullable|integer|min:1|max:365'
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

        $trends = Attendance::getAttendanceTrends(
            $validated['mess_id'],
            $validated['days'] ?? 30
        );

        return response()->json([
            'success' => true,
            'data' => $trends
        ]);
    }

    /**
     * Get peak meal times.
     */
    public function peakTimes(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'days' => 'nullable|integer|min:1|max:365'
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

        $peakTimes = Attendance::getPeakMealTimes(
            $validated['mess_id'],
            $validated['days'] ?? 30
        );

        return response()->json([
            'success' => true,
            'data' => $peakTimes
        ]);
    }
}

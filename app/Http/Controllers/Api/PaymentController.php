<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Models\Payment;
use App\Models\Mess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PaymentController extends Controller
{
    /**
     * Display a listing of payments.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'user_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:pending,completed,all',
            'payment_method' => 'nullable|in:cash,bkash,nagad,card,bank_transfer',
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

        $query = $mess->payments()->with(['user', 'approvedBy']);

        // Filter by date range
        if (isset($validated['date_from'])) {
            $query->whereDate('payment_date', '>=', $validated['date_from']);
        }
        if (isset($validated['date_to'])) {
            $query->whereDate('payment_date', '<=', $validated['date_to']);
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
            if ($validated['status'] === 'completed') {
                $query->completed();
            } elseif ($validated['status'] === 'pending') {
                $query->pending();
            }
        }

        // Filter by payment method
        if (isset($validated['payment_method'])) {
            $query->where('payment_method', $validated['payment_method']);
        }

        $payments = $query->orderBy('payment_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => $payments
        ]);
    }

    /**
     * Store a newly created payment.
     */
    public function store(StorePaymentRequest $request)
    {
        $validated = $request->validated();
        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check if user is a member of the mess
        if (!$mess->members()->where('user_id', $validated['user_id'])->where('status', 'approved')->exists()) {
            return response()->json(['message' => 'User is not an active member of this mess'], 400);
        }

        try {
            DB::beginTransaction();

            // Handle receipt upload
            $receiptPath = null;
            if ($request->hasFile('receipt_image')) {
                $receiptPath = $request->file('receipt_image')->store('payment_receipts', 'public');
            }

            $payment = Payment::create([
                'mess_id' => $validated['mess_id'],
                'user_id' => $validated['user_id'],
                'amount' => $validated['amount'],
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'transaction_id' => $validated['transaction_id'] ?? null,
                'receipt_image' => $receiptPath,
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending',
                'created_by' => $user->id
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment recorded successfully',
                'data' => $payment->load(['user', 'approvedBy'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            // Remove uploaded receipt if transaction failed
            if ($receiptPath) {
                Storage::disk('public')->delete($receiptPath);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to record payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified payment.
     */
    public function show($id)
    {
        $user = Auth::user();
        $payment = Payment::with(['mess', 'user', 'approvedBy'])->findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $payment->mess->manager_id !== $user->id &&
            $payment->user_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $payment
        ]);
    }

    /**
     * Update the specified payment.
     */
    public function update(UpdatePaymentRequest $request, $id)
    {
        $validated = $request->validated();
        $user = Auth::user();
        $payment = Payment::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $payment->mess->manager_id !== $user->id &&
            $payment->user_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Don't allow updating approved payments (only managers can)
        if ($payment->isApproved() && !$user->hasRole('super_admin') && $payment->mess->manager_id !== $user->id) {
            return response()->json(['message' => 'Cannot update approved payment'], 400);
        }

        try {
            $payment->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Payment updated successfully',
                'data' => $payment->fresh()->load(['user', 'approvedBy'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified payment.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $payment = Payment::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $payment->mess->manager_id !== $user->id &&
            $payment->user_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Don't allow deleting approved payments (only managers can)
        if ($payment->isApproved() && !$user->hasRole('super_admin') && $payment->mess->manager_id !== $user->id) {
            return response()->json(['message' => 'Cannot delete approved payment'], 400);
        }

        try {
            // Delete receipt image if exists
            if ($payment->receipt_image) {
                Storage::disk('public')->delete($payment->receipt_image);
            }

            $payment->delete();

            return response()->json([
                'success' => true,
                'message' => 'Payment deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve the specified payment.
     */
    public function approve($id)
    {
        $user = Auth::user();
        $payment = Payment::findOrFail($id);

        // Check authorization (only managers can approve)
        if (!$user->hasRole('super_admin') && $payment->mess->manager_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($payment->isApproved()) {
            return response()->json([
                'success' => false,
                'message' => 'Payment is already approved'
            ], 400);
        }

        try {
            $payment->approve($user->id);

            return response()->json([
                'success' => true,
                'message' => 'Payment approved successfully',
                'data' => $payment->fresh()->load(['user', 'approvedBy'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload receipt image for payment.
     */
    public function uploadReceipt(Request $request, $id)
    {
        $validated = $request->validate([
            'receipt_image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        $user = Auth::user();
        $payment = Payment::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $payment->mess->manager_id !== $user->id &&
            $payment->user_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            // Delete old receipt if exists
            if ($payment->receipt_image) {
                Storage::disk('public')->delete($payment->receipt_image);
            }

            // Upload new receipt
            $receiptPath = $request->file('receipt_image')->store('payment_receipts', 'public');

            $payment->update(['receipt_image' => $receiptPath]);

            return response()->json([
                'success' => true,
                'message' => 'Receipt uploaded successfully',
                'data' => [
                    'receipt_url' => $payment->receipt_url
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
     * Get payment history for user.
     */
    public function history(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'user_id' => 'nullable|exists:users,id',
            'limit' => 'nullable|integer|min:1|max:50'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            $validated['user_id'] &&
            $validated['user_id'] !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized to view user-specific payment history'], 403);
        }

        $history = Payment::getUserPaymentHistory($validated['mess_id'], $validated['user_id'], $validated['limit'] ?? 20);

        return response()->json([
            'success' => true,
            'data' => $history
        ]);
    }

    /**
     * Get payment statistics.
     */
    public function statistics(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'year' => 'required|integer|min:2020|max:' . date('Y'),
            'month' => 'required|integer|min:1|max:12',
            'user_id' => 'nullable|exists:users,id'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            $validated['user_id'] &&
            $validated['user_id'] !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized to view user-specific payment statistics'], 403);
        }

        $statistics = Payment::getUserPaymentStatistics($validated['mess_id'], $validated['user_id'], $validated['year'], $validated['month']);

        return response()->json([
            'success' => true,
            'data' => $statistics
        ]);
    }

    /**
     * Get payment methods summary.
     */
    public function paymentMethodsSummary(Request $request)
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

        $summary = Payment::getPaymentMethodsSummary($validated['mess_id']);

        return response()->json([
            'success' => true,
            'data' => $summary
        ]);
    }

    /**
     * Get payment collection report.
     */
    public function collectionReport(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'year' => 'required|integer|min:2020|max:' . date('Y'),
            'month' => 'required|integer|min:1|max:12',
            'group_by' => 'nullable|in:user,payment_method,none'
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

        $report = Payment::getPaymentCollectionReport($validated['mess_id'], $validated['year'], $validated['month'], $validated['group_by'] ?? 'none');

        return response()->json([
            'success' => true,
            'data' => $report
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mess;
use App\Models\User;
use App\Models\Meal;
use App\Models\Bazar;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\MessMember;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    protected $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    /**
     * Get admin dashboard data
     */
    public function admin(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'nullable|exists:messes,id',
            'period' => 'nullable|in:today,week,month,year',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from'
        ]);

        $user = Auth::user();

        // Check authorization
        if (!$user->hasRole('super_admin') && !$user->managedMesses()->exists()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $messId = $validated['mess_id'] ?? null;
        $period = $validated['period'] ?? 'month';

        $data = $this->dashboardService->getAdminDashboardData($user, $messId, $period, $validated);

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Get member dashboard data
     */
    public function member(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'period' => 'nullable|in:today,week,month,year'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check if user is a member of the mess
        if (!$mess->members()->where('user_id', $user->id)->where('status', 'approved')->exists()) {
            return response()->json(['message' => 'You are not an active member of this mess'], 403);
        }

        $period = $validated['period'] ?? 'month';
        $data = $this->dashboardService->getMemberDashboardData($user, $mess, $period);

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Get mess overview statistics
     */
    public function messOverview(Request $request)
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

        $data = $this->dashboardService->getMessOverview($mess, $user);

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Get financial summary
     */
    public function financialSummary(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'year' => 'required|integer|min:2020|max:' . date('Y'),
            'month' => 'nullable|integer|min:1|max:12'
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

        $data = $this->dashboardService->getFinancialSummary(
            $mess,
            $validated['year'],
            $validated['month'] ?? null,
            $user
        );

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Get meal statistics
     */
    public function mealStatistics(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'period' => 'nullable|in:today,week,month,year',
            'year' => 'nullable|integer|min:2020|max:' . date('Y'),
            'month' => 'nullable|integer|min:1|max:12'
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

        $data = $this->dashboardService->getMealStatistics(
            $mess,
            $validated['period'] ?? 'month',
            $validated['year'] ?? null,
            $validated['month'] ?? null,
            $user
        );

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Get expense analytics
     */
    public function expenseAnalytics(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'period' => 'nullable|in:today,week,month,year',
            'year' => 'nullable|integer|min:2020|max:' . date('Y'),
            'month' => 'nullable|integer|min:1|max:12'
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

        $data = $this->dashboardService->getExpenseAnalytics(
            $mess,
            $validated['period'] ?? 'month',
            $validated['year'] ?? null,
            $validated['month'] ?? null,
            $user
        );

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Get payment analytics
     */
    public function paymentAnalytics(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'period' => 'nullable|in:today,week,month,year',
            'year' => 'nullable|integer|min:2020|max:' . date('Y'),
            'month' => 'nullable|integer|min:1|max:12'
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

        $data = $this->dashboardService->getPaymentAnalytics(
            $mess,
            $validated['period'] ?? 'month',
            $validated['year'] ?? null,
            $validated['month'] ?? null,
            $user
        );

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Get member activity summary
     */
    public function memberActivity(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'user_id' => 'nullable|exists:users,id',
            'period' => 'nullable|in:today,week,month,year'
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
            return response()->json(['message' => 'Unauthorized to view other user activity'], 403);
        }

        $targetUserId = $validated['user_id'] ?? $user->id;
        $data = $this->dashboardService->getMemberActivity(
            $mess,
            $targetUserId,
            $validated['period'] ?? 'month',
            $user
        );

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Get system-wide statistics (Super Admin only)
     */
    public function systemStatistics(Request $request)
    {
        $user = Auth::user();

        if (!$user->hasRole('super_admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $this->dashboardService->getSystemStatistics();

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Get quick stats for dashboard widgets
     */
    public function quickStats(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'nullable|exists:messes,id'
        ]);

        $user = Auth::user();
        $messId = $validated['mess_id'] ?? null;

        $data = $this->dashboardService->getQuickStats($user, $messId);

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Get trends and comparisons
     */
    public function trends(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'metric' => 'required|in:meals,expenses,payments,members',
            'period' => 'nullable|in:week,month,quarter,year',
            'compare_with' => 'nullable|in:previous_period,same_last_year'
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

        $data = $this->dashboardService->getTrends(
            $mess,
            $validated['metric'],
            $validated['period'] ?? 'month',
            $validated['compare_with'] ?? 'previous_period',
            $user
        );

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }
}

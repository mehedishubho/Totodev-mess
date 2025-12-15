<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Mess;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AnnouncementController extends Controller
{
    /**
     * Display a listing of announcements.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'status' => 'nullable|in:active,inactive,all',
            'priority' => 'nullable|in:high,medium,low,all',
            'category' => 'nullable|in:general,urgent,maintenance,reminder',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
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

        $query = $mess->announcements()->with(['createdBy']);

        // Apply filters
        if (isset($validated['status']) && $validated['status'] !== 'all') {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['priority']) && $validated['priority'] !== 'all') {
            $query->where('priority', $validated['priority']);
        }

        if (isset($validated['category']) && $validated['category'] !== 'all') {
            $query->where('category', $validated['category']);
        }

        if (isset($validated['date_from'])) {
            $query->whereDate('announcements.start_date', '>=', $validated['date_from']);
        }

        if (isset($validated['date_to'])) {
            $query->whereDate('announcements.start_date', '<=', $validated['date_to']);
        }

        $announcements = $query->orderBy('priority', 'desc')
            ->orderBy('start_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => $announcements
        ]);
    }

    /**
     * Store a newly created announcement.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'category' => 'required|in:general,urgent,maintenance,reminder',
            'priority' => 'required|in:high,medium,low',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'target_audience' => 'required|in:all,members,managers,specific',
            'target_users' => 'nullable|array',
            'target_roles' => 'nullable|array',
            'is_pinned' => 'boolean',
            'allow_comments' => 'boolean',
            'send_notification' => 'boolean',
            'send_email' => 'boolean',
            'send_sms' => 'boolean',
            'send_push' => 'boolean',
            'scheduled_at' => 'nullable|date|after_or_equal:start_date'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization - only managers and super admin can create announcements
        if (!$user->hasRole('super_admin') && $mess->manager_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized to create announcements'], 403);
        }

        try {
            DB::beginTransaction();

            $announcement = Announcement::create([
                'mess_id' => $validated['mess_id'],
                'title' => $validated['title'],
                'message' => $validated['message'],
                'category' => $validated['category'],
                'priority' => $validated['priority'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'target_audience' => $validated['target_audience'],
                'is_pinned' => $validated['is_pinned'] ?? false,
                'allow_comments' => $validated['allow_comments'] ?? true,
                'status' => 'active',
                'created_by' => $user->id,
                'scheduled_at' => $validated['scheduled_at'],
            ]);

            // Handle target users and roles
            if ($validated['target_audience'] === 'specific' && !empty($validated['target_users'])) {
                $announcement->target_users()->sync($validated['target_users']);
            }

            if ($validated['target_audience'] === 'specific' && !empty($validated['target_roles'])) {
                $announcement->target_roles()->sync($validated['target_roles']);
            }

            // Send notifications if requested
            if ($validated['send_notification'] || $validated['send_email'] || $validated['send_sms'] || $validated['send_push']) {
                $this->sendAnnouncementNotifications($announcement, $validated);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Announcement created successfully',
                'data' => $announcement->load(['createdBy', 'targetUsers', 'targetRoles'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create announcement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified announcement.
     */
    public function show($id)
    {
        $user = Auth::user();
        $announcement = Announcement::with(['mess', 'createdBy', 'targetUsers', 'targetRoles', 'comments'])->findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $announcement->mess->manager_id !== $user->id &&
            !$announcement->mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $announcement
        ]);
    }

    /**
     * Update the specified announcement.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'message' => 'sometimes|string',
            'category' => 'sometimes|in:general,urgent,maintenance,reminder',
            'priority' => 'sometimes|in:high,medium,low',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'is_pinned' => 'sometimes|boolean',
            'allow_comments' => 'sometimes|boolean',
            'status' => 'sometimes|in:active,inactive'
        ]);

        $user = Auth::user();
        $announcement = Announcement::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $announcement->mess->manager_id !== $user->id &&
            !$announcement->mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized to update announcement'], 403);
        }

        // Don't allow updating certain fields if announcement is already sent
        if ($announcement->status === 'sent') {
            $restrictedFields = ['title', 'message', 'start_date', 'target_audience'];
            foreach ($restrictedFields as $field) {
                if (isset($validated[$field])) {
                    unset($validated[$field]);
                }
            }
        }

        try {
            DB::beginTransaction();

            $announcement->update($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Announcement updated successfully',
                'data' => $announcement->fresh()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update announcement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified announcement.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $announcement = Announcement::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $announcement->mess->manager_id !== $user->id &&
            !$announcement->mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized to delete announcement'], 403);
        }

        try {
            DB::beginTransaction();

            // Soft delete the announcement
            $announcement->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Announcement deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete announcement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark announcement as read for user.
     */
    public function markAsRead($id)
    {
        $user = Auth::user();
        $announcement = Announcement::findOrFail($id);

        // Check if user can read this announcement
        if (!$this->canUserReadAnnouncement($user, $announcement)) {
            return response()->json(['message' => 'Unauthorized to read announcement'], 403);
        }

        try {
            DB::beginTransaction();

            // Mark as read for this user
            $announcement->readBy()->syncWithoutDetaching($user->id);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Announcement marked as read'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark announcement as read: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get unread announcements count for user.
     */
    public function unreadCount(Request $request)
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

        $unreadCount = $mess->announcements()
            ->where('status', 'active')
            ->whereDoesntHave('readBy', $user->id)
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => $unreadCount
            ]
        ]);
    }

    /**
     * Get announcement statistics.
     */
    public function statistics(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'category' => 'nullable|in:general,urgent,maintenance,reminder'
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

        $query = $mess->announcements();

        // Apply filters
        if (isset($validated['date_from'])) {
            $query->whereDate('start_date', '>=', $validated['date_from']);
        }
        if (isset($validated['date_to'])) {
            $query->whereDate('start_date', '<=', $validated['date_to']);
        }
        if (isset($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        $announcements = $query->get();

        $totalAnnouncements = $announcements->count();

        $statistics = [
            'total_announcements' => $totalAnnouncements,
            'by_priority' => $announcements->groupBy('priority')->map(function ($group) use ($totalAnnouncements) {
                return [
                    'priority' => $group->first()->priority,
                    'count' => $group->count(),
                    'percentage' => $totalAnnouncements > 0 ? ($group->count() / $totalAnnouncements) * 100 : 0
                ];
            })->values(),
            'by_category' => $announcements->groupBy('category')->map(function ($group) use ($totalAnnouncements) {
                return [
                    'category' => $group->first()->category,
                    'count' => $group->count(),
                    'percentage' => $totalAnnouncements > 0 ? ($group->count() / $totalAnnouncements) * 100 : 0
                ];
            })->values(),
            'by_status' => [
                'active' => $announcements->where('status', 'active')->count(),
                'inactive' => $announcements->where('status', 'inactive')->count(),
            ],
            'read_rate' => $totalAnnouncements > 0 ?
                ($announcements->whereHas('readBy')->count() / $totalAnnouncements) * 100 : 0,
        ];

        return response()->json([
            'success' => true,
            'data' => $statistics
        ]);
    }

    /**
     * Check if user can read announcement
     */
    private function canUserReadAnnouncement(User $user, Announcement $announcement): bool
    {
        // Super admin can read all announcements
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess managers can read announcements in their mess
        if ($announcement->mess->manager_id === $user->id) {
            return true;
        }

        // Check if user is target audience
        if ($announcement->target_audience === 'all') {
            return $announcement->mess->members()->where('user_id', $user->id)->exists();
        }

        if ($announcement->target_audience === 'members') {
            return $announcement->mess->members()->where('user_id', $user->id)->exists();
        }

        if ($announcement->target_audience === 'managers') {
            return $announcement->mess->manager_id === $user->id;
        }

        // Check specific user targeting
        if ($announcement->target_audience === 'specific') {
            return $announcement->target_users()->where('user_id', $user->id)->exists();
        }

        // Check role-based targeting
        if ($announcement->target_audience === 'specific' && !empty($announcement->target_roles)) {
            $userRoles = $user->roles->pluck('name')->toArray();
            $targetRoles = $announcement->target_roles->pluck('name')->toArray();
            return !empty(array_intersect($userRoles, $targetRoles));
        }

        return false;
    }

    /**
     * Send announcement notifications
     */
    private function sendAnnouncementNotifications(Announcement $announcement, array $data): void
    {
        // This would integrate with notification services
        // For now, we'll just log the notification sending
        \Log::info('Announcement notifications sent', [
            'announcement_id' => $announcement->id,
            'title' => $announcement->title,
            'send_email' => $data['send_email'] ?? false,
            'send_sms' => $data['send_sms'] ?? false,
            'send_push' => $data['send_push'] ?? false,
            'target_count' => $this->getNotificationTargetCount($announcement)
        ]);
    }

    /**
     * Get notification target count
     */
    private function getNotificationTargetCount(Announcement $announcement): int
    {
        switch ($announcement->target_audience) {
            case 'all':
                return $announcement->mess->members()->where('status', 'approved')->count();
            case 'members':
                return $announcement->mess->members()->where('status', 'approved')->count();
            case 'managers':
                return 1; // Only the mess manager
            case 'specific':
                $userCount = $announcement->target_users()->count();
                $roleCount = $announcement->target_roles()->count();
                return max($userCount, $roleCount);
            default:
                return 0;
        }
    }
}

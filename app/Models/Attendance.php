<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attendance extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'mess_id',
        'user_id',
        'meal_type',
        'meal_date',
        'scan_time',
        'qr_code',
        'status',
        'approved_by',
        'approved_at',
        'notes',
        'device_info',
        'location',
        'is_manual_entry',
        'scanned_by'
    ];

    protected $casts = [
        'meal_date' => 'date',
        'scan_time' => 'datetime',
        'approved_at' => 'datetime',
        'is_manual_entry' => 'boolean'
    ];

    protected $appends = [
        'meal_type_label',
        'status_label',
        'is_approved',
        'can_be_approved',
        'formatted_scan_time'
    ];

    /**
     * Get the mess that owns the attendance.
     */
    public function mess()
    {
        return $this->belongsTo(Mess::class);
    }

    /**
     * Get the user who owns the attendance.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who approved the attendance.
     */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who scanned the attendance.
     */
    public function scannedBy()
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }

    /**
     * Get the associated meal.
     */
    public function meal()
    {
        return $this->belongsTo(Meal::class, ['user_id', 'meal_date', 'meal_type'], ['user_id', 'meal_date', 'meal_type']);
    }

    /**
     * Scope a query to only include pending attendances.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include approved attendances.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope a query to only include rejected attendances.
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    /**
     * Scope a query to only include attendances for a specific date.
     */
    public function scopeForDate($query, $date)
    {
        return $query->whereDate('meal_date', $date);
    }

    /**
     * Scope a query to only include attendances for a specific meal type.
     */
    public function scopeForMealType($query, $mealType)
    {
        return $query->where('meal_type', $mealType);
    }

    /**
     * Scope a query to only include attendances for a date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('meal_date', [$startDate, $endDate]);
    }

    /**
     * Scope a query to only include QR scanned attendances.
     */
    public function scopeQrScanned($query)
    {
        return $query->where('is_manual_entry', false);
    }

    /**
     * Scope a query to only include manual entries.
     */
    public function scopeManualEntry($query)
    {
        return $query->where('is_manual_entry', true);
    }

    /**
     * Get meal type label.
     */
    public function getMealTypeLabelAttribute()
    {
        return [
            'breakfast' => 'Breakfast',
            'lunch' => 'Lunch',
            'dinner' => 'Dinner'
        ][$this->meal_type] ?? $this->meal_type;
    }

    /**
     * Get status label.
     */
    public function getStatusLabelAttribute()
    {
        return [
            'pending' => 'Pending',
            'approved' => 'Approved',
            'rejected' => 'Rejected'
        ][$this->status] ?? $this->status;
    }

    /**
     * Check if attendance is approved.
     */
    public function getIsApprovedAttribute()
    {
        return $this->status === 'approved';
    }

    /**
     * Check if attendance can be approved.
     */
    public function getCanBeApprovedAttribute()
    {
        return $this->status === 'pending';
    }

    /**
     * Get formatted scan time.
     */
    public function getFormattedScanTimeAttribute()
    {
        return $this->scan_time ? $this->scan_time->format('h:i A') : null;
    }

    /**
     * Approve the attendance.
     */
    public function approve($approvedBy, $notes = null)
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $approvedBy,
            'approved_at' => now(),
            'notes' => $notes ?? $this->notes
        ]);

        // Update corresponding meal entry
        $this->updateMealEntry();
    }

    /**
     * Reject the attendance.
     */
    public function reject($rejectedBy, $reason = null)
    {
        $this->update([
            'status' => 'rejected',
            'approved_by' => $rejectedBy,
            'approved_at' => now(),
            'notes' => $reason ?? $this->notes
        ]);
    }

    /**
     * Update corresponding meal entry.
     */
    private function updateMealEntry()
    {
        $meal = Meal::where('user_id', $this->user_id)
            ->where('meal_date', $this->meal_date)
            ->where('meal_type', $this->meal_type)
            ->first();

        if ($meal) {
            // Update meal count to reflect attendance
            $meal->update([
                'count' => 1, // Assuming 1 meal per attendance
                'updated_by' => $this->approved_by
            ]);
        } else {
            // Create meal entry if it doesn't exist
            Meal::create([
                'user_id' => $this->user_id,
                'mess_id' => $this->mess_id,
                'meal_date' => $this->meal_date,
                'meal_type' => $this->meal_type,
                'count' => 1,
                'created_by' => $this->approved_by
            ]);
        }
    }

    /**
     * Generate QR code for user.
     */
    public static function generateQRCode($user, $mealDate, $mealType)
    {
        $qrData = [
            'user_id' => $user->id,
            'meal_date' => $mealDate,
            'meal_type' => $mealType,
            'mess_id' => $user->mess_id,
            'timestamp' => now()->timestamp,
            'hash' => md5($user->id . $mealDate . $mealType . config('app.key'))
        ];

        return base64_encode(json_encode($qrData));
    }

    /**
     * Validate QR code.
     */
    public static function validateQRCode($qrCode)
    {
        try {
            $decoded = json_decode(base64_decode($qrCode), true);

            if (!$decoded || !isset($decoded['user_id'], $decoded['meal_date'], $decoded['meal_type'])) {
                return null;
            }

            // Verify hash
            $expectedHash = md5(
                $decoded['user_id'] .
                    $decoded['meal_date'] .
                    $decoded['meal_type'] .
                    config('app.key')
            );

            if (!isset($decoded['hash']) || $decoded['hash'] !== $expectedHash) {
                return null;
            }

            // Check if QR code is expired (24 hours)
            if (
                isset($decoded['timestamp']) &&
                (now()->timestamp - $decoded['timestamp']) > 86400
            ) {
                return null;
            }

            return $decoded;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check for duplicate attendance.
     */
    public static function isDuplicate($userId, $mealDate, $mealType, $messId)
    {
        return self::where('user_id', $userId)
            ->where('meal_date', $mealDate)
            ->where('meal_type', $mealType)
            ->where('mess_id', $messId)
            ->where('status', '!=', 'rejected')
            ->exists();
    }

    /**
     * Get attendance statistics for mess.
     */
    public static function getStatistics($messId, $startDate, $endDate)
    {
        $attendances = self::where('mess_id', $messId)
            ->dateRange($startDate, $endDate)
            ->get();

        return [
            'total_attendances' => $attendances->count(),
            'approved_attendances' => $attendances->where('status', 'approved')->count(),
            'pending_attendances' => $attendances->where('status', 'pending')->count(),
            'rejected_attendances' => $attendances->where('status', 'rejected')->count(),
            'qr_scanned' => $attendances->where('is_manual_entry', false)->count(),
            'manual_entries' => $attendances->where('is_manual_entry', true)->count(),
            'by_meal_type' => $attendances->groupBy('meal_type')->map(function ($group) {
                return [
                    'meal_type' => $group->first()->meal_type,
                    'count' => $group->count(),
                    'approved' => $group->where('status', 'approved')->count()
                ];
            })->values(),
            'daily_breakdown' => $attendances->groupBy(function ($item) {
                return $item->meal_date->format('Y-m-d');
            })->map(function ($group) {
                return [
                    'date' => $group->first()->meal_date->format('Y-m-d'),
                    'total' => $group->count(),
                    'approved' => $group->where('status', 'approved')->count(),
                    'pending' => $group->where('status', 'pending')->count()
                ];
            })->values()
        ];
    }

    /**
     * Get attendance summary for user.
     */
    public static function getUserSummary($userId, $startDate, $endDate)
    {
        $attendances = self::where('user_id', $userId)
            ->dateRange($startDate, $endDate)
            ->get();

        return [
            'total_meals' => $attendances->where('status', 'approved')->count(),
            'by_meal_type' => $attendances->where('status', 'approved')
                ->groupBy('meal_type')
                ->map(function ($group) {
                    return [
                        'meal_type' => $group->first()->meal_type,
                        'count' => $group->count()
                    ];
                })->values(),
            'attendance_rate' => $attendances->count() > 0 ?
                ($attendances->where('status', 'approved')->count() / $attendances->count()) * 100 : 0,
            'qr_usage' => $attendances->where('is_manual_entry', false)->count(),
            'manual_usage' => $attendances->where('is_manual_entry', true)->count()
        ];
    }

    /**
     * Get pending attendances for mess.
     */
    public static function getPendingAttendances($messId)
    {
        return self::where('mess_id', $messId)
            ->pending()
            ->with(['user', 'meal'])
            ->orderBy('scan_time', 'desc')
            ->get();
    }

    /**
     * Get today's attendances for mess.
     */
    public static function getTodayAttendances($messId, $status = null)
    {
        $query = self::where('mess_id', $messId)
            ->forDate(today());

        if ($status) {
            $query->where('status', $status);
        }

        return $query->with(['user', 'meal'])
            ->orderBy('scan_time', 'desc')
            ->get();
    }

    /**
     * Search attendances.
     */
    public static function search($messId, $query, $filters = [])
    {
        $attendances = self::where('mess_id', $messId)
            ->with(['user', 'approvedBy', 'scannedBy']);

        // Search query
        if ($query) {
            $attendances->where(function ($q) use ($query) {
                $q->where('qr_code', 'LIKE', "%{$query}%")
                    ->orWhere('notes', 'LIKE', "%{$query}%")
                    ->orWhereHas('user', function ($subQuery) use ($query) {
                        $subQuery->where('name', 'LIKE', "%{$query}%")
                            ->orWhere('email', 'LIKE', "%{$query}%");
                    });
            });
        }

        // Apply filters
        if (isset($filters['status'])) {
            $attendances->where('status', $filters['status']);
        }

        if (isset($filters['meal_type'])) {
            $attendances->where('meal_type', $filters['meal_type']);
        }

        if (isset($filters['date_from'])) {
            $attendances->whereDate('meal_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $attendances->whereDate('meal_date', '<=', $filters['date_to']);
        }

        if (isset($filters['is_manual_entry'])) {
            $attendances->where('is_manual_entry', $filters['is_manual_entry']);
        }

        return $attendances;
    }

    /**
     * Get attendance trends.
     */
    public static function getAttendanceTrends($messId, $days = 30)
    {
        $startDate = now()->subDays($days)->startOfDay();

        return self::where('mess_id', $messId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('
                       DATE(meal_date) as date,
                       meal_type,
                       COUNT(*) as total_attendances,
                       SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) as approved_attendances,
                       SUM(CASE WHEN is_manual_entry = 0 THEN 1 ELSE 0 END) as qr_scanned,
                       SUM(CASE WHEN is_manual_entry = 1 THEN 1 ELSE 0 END) as manual_entries
                   ')
            ->groupBy('date', 'meal_type')
            ->orderBy('date')
            ->get()
            ->groupBy('date');
    }

    /**
     * Get peak meal times.
     */
    public static function getPeakMealTimes($messId, $days = 30)
    {
        $startDate = now()->subDays($days)->startOfDay();

        return self::where('mess_id', $messId)
            ->where('scan_time', '>=', $startDate)
            ->where('status', 'approved')
            ->selectRaw('
                       meal_type,
                       HOUR(scan_time) as hour,
                       COUNT(*) as attendance_count
                   ')
            ->groupBy('meal_type', 'hour')
            ->orderBy('attendance_count', 'desc')
            ->get()
            ->groupBy('meal_type');
    }

    /**
     * Get user attendance rate.
     */
    public static function getUserAttendanceRate($userId, $messId, $days = 30)
    {
        $startDate = now()->subDays($days)->startOfDay();

        $totalPossibleMeals = $days * 3; // 3 meals per day
        $attendedMeals = self::where('user_id', $userId)
            ->where('mess_id', $messId)
            ->where('meal_date', '>=', $startDate)
            ->where('status', 'approved')
            ->count();

        return $totalPossibleMeals > 0 ? ($attendedMeals / $totalPossibleMeals) * 100 : 0;
    }
}

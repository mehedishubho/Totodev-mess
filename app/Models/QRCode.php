<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class QRCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'mess_id',
        'qr_data',
        'qr_token',
        'expires_at',
        'is_active',
        'usage_count',
        'max_usage',
        'purpose',
        'metadata'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'usage_count' => 'integer',
        'max_usage' => 'integer',
        'metadata' => 'array'
    ];

    protected $appends = [
        'is_expired',
        'usage_remaining',
        'qr_image_url'
    ];

    /**
     * Get the user that owns the QR code.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the mess that owns the QR code.
     */
    public function mess()
    {
        return $this->belongsTo(Mess::class);
    }

    /**
     * Check if QR code is expired.
     */
    public function getIsExpiredAttribute()
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Get remaining usage count.
     */
    public function getUsageRemainingAttribute()
    {
        return max(0, $this->max_usage - $this->usage_count);
    }

    /**
     * Get QR image URL.
     */
    public function getQrImageUrlAttribute()
    {
        return route('qr.code.image', $this->qr_token);
    }

    /**
     * Scope a query to only include active QR codes.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include non-expired QR codes.
     */
    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Scope a query to only include QR codes with remaining usage.
     */
    public function scopeWithUsage($query)
    {
        return $query->whereRaw('usage_count < max_usage');
    }

    /**
     * Scope a query to only include QR codes for a specific purpose.
     */
    public function scopeForPurpose($query, $purpose)
    {
        return $query->where('purpose', $purpose);
    }

    /**
     * Generate QR code for meal attendance.
     */
    public static function generateMealQR($user, $mealDate, $mealType, $expiresInHours = 24)
    {
        $qrData = [
            'type' => 'meal_attendance',
            'user_id' => $user->id,
            'mess_id' => $user->mess_id,
            'meal_date' => $mealDate,
            'meal_type' => $mealType,
            'generated_at' => now()->toISOString(),
            'signature' => self::generateSignature($user->id, $mealDate, $mealType)
        ];

        $qrToken = Str::random(32);

        return self::create([
            'user_id' => $user->id,
            'mess_id' => $user->mess_id,
            'qr_data' => json_encode($qrData),
            'qr_token' => $qrToken,
            'expires_at' => now()->addHours($expiresInHours),
            'is_active' => true,
            'usage_count' => 0,
            'max_usage' => 1, // Single use for meal QR
            'purpose' => 'meal_attendance',
            'metadata' => [
                'meal_date' => $mealDate,
                'meal_type' => $mealType,
                'user_name' => $user->name
            ]
        ]);
    }

    /**
     * Generate QR code for mess access.
     */
    public static function generateAccessQR($user, $expiresInHours = 168) // 1 week default
    {
        $qrData = [
            'type' => 'mess_access',
            'user_id' => $user->id,
            'mess_id' => $user->mess_id,
            'generated_at' => now()->toISOString(),
            'signature' => self::generateSignature($user->id, 'access', now()->timestamp)
        ];

        $qrToken = Str::random(32);

        return self::create([
            'user_id' => $user->id,
            'mess_id' => $user->mess_id,
            'qr_data' => json_encode($qrData),
            'qr_token' => $qrToken,
            'expires_at' => now()->addHours($expiresInHours),
            'is_active' => true,
            'usage_count' => 0,
            'max_usage' => 100, // Multiple uses for access QR
            'purpose' => 'mess_access',
            'metadata' => [
                'user_name' => $user->name,
                'user_role' => $user->roles->first()->name ?? 'member'
            ]
        ]);
    }

    /**
     * Generate temporary QR code for guest access.
     */
    public static function generateGuestQR($messId, $guestName, $expiresInHours = 4)
    {
        $qrData = [
            'type' => 'guest_access',
            'mess_id' => $messId,
            'guest_name' => $guestName,
            'generated_at' => now()->toISOString(),
            'signature' => self::generateSignature('guest', $guestName, now()->timestamp)
        ];

        $qrToken = Str::random(32);

        return self::create([
            'user_id' => null, // Guest QR has no user
            'mess_id' => $messId,
            'qr_data' => json_encode($qrData),
            'qr_token' => $qrToken,
            'expires_at' => now()->addHours($expiresInHours),
            'is_active' => true,
            'usage_count' => 0,
            'max_usage' => 1, // Single use for guest
            'purpose' => 'guest_access',
            'metadata' => [
                'guest_name' => $guestName
            ]
        ]);
    }

    /**
     * Validate QR code data.
     */
    public static function validateQRData($qrData)
    {
        try {
            $data = is_string($qrData) ? json_decode($qrData, true) : $qrData;

            if (!$data || !isset($data['type'], $data['signature'])) {
                return ['valid' => false, 'message' => 'Invalid QR code format'];
            }

            // Verify signature
            $expectedSignature = self::generateSignature(
                $data['user_id'] ?? 'guest',
                $data['meal_date'] ?? $data['guest_name'] ?? 'access',
                $data['generated_at'] ?? now()->timestamp
            );

            if ($data['signature'] !== $expectedSignature) {
                return ['valid' => false, 'message' => 'Invalid QR code signature'];
            }

            // Check expiration
            if (isset($data['generated_at'])) {
                $generatedAt = \Carbon\Carbon::parse($data['generated_at']);
                if ($generatedAt->diffInHours(now()) > 24) {
                    return ['valid' => false, 'message' => 'QR code expired'];
                }
            }

            return ['valid' => true, 'data' => $data];
        } catch (\Exception $e) {
            return ['valid' => false, 'message' => 'QR code validation failed'];
        }
    }

    /**
     * Generate signature for QR data.
     */
    private static function generateSignature($userId, $context, $timestamp)
    {
        return hash_hmac(
            'sha256',
            $userId . $context . $timestamp . config('app.key'),
            config('app.key')
        );
    }

    /**
     * Use QR code (increment usage).
     */
    public function useQR()
    {
        if ($this->isExpired() || $this->usage_count >= $this->max_usage) {
            return false;
        }

        $this->increment('usage_count');

        // Deactivate if max usage reached
        if ($this->usage_count >= $this->max_usage) {
            $this->update(['is_active' => false]);
        }

        return true;
    }

    /**
     * Deactivate QR code.
     */
    public function deactivate()
    {
        return $this->update(['is_active' => false]);
    }

    /**
     * Extend QR code expiration.
     */
    public function extendExpiration($hours)
    {
        $newExpiresAt = $this->expires_at ?
            $this->expires_at->addHours($hours) :
            now()->addHours($hours);

        return $this->update(['expires_at' => $newExpiresAt]);
    }

    /**
     * Get active QR codes for user.
     */
    public static function getActiveForUser($userId, $purpose = null)
    {
        $query = self::where('user_id', $userId)
            ->active()
            ->notExpired()
            ->withUsage();

        if ($purpose) {
            $query->forPurpose($purpose);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Get active QR codes for mess.
     */
    public static function getActiveForMess($messId, $purpose = null)
    {
        $query = self::where('mess_id', $messId)
            ->active()
            ->notExpired()
            ->withUsage();

        if ($purpose) {
            $query->forPurpose($purpose);
        }

        return $query->with(['user'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Clean up expired QR codes.
     */
    public static function cleanupExpired()
    {
        return self::where('expires_at', '<', now()->subDays(7))
            ->update(['is_active' => false]);
    }

    /**
     * Get QR usage statistics.
     */
    public static function getUsageStatistics($messId, $startDate, $endDate)
    {
        $qrCodes = self::where('mess_id', $messId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        return [
            'total_generated' => $qrCodes->count(),
            'by_purpose' => $qrCodes->groupBy('purpose')->map(function ($group) {
                return [
                    'purpose' => $group->first()->purpose,
                    'count' => $group->count(),
                    'total_usage' => $group->sum('usage_count')
                ];
            })->values(),
            'by_user' => $qrCodes->whereNotNull('user_id')
                ->groupBy('user_id')
                ->map(function ($group) {
                    $user = $group->first()->user;
                    return [
                        'user_id' => $group->first()->user_id,
                        'user_name' => $user ? $user->name : 'Unknown',
                        'qr_count' => $group->count(),
                        'total_usage' => $group->sum('usage_count')
                    ];
                })->values(),
            'average_usage_per_qr' => $qrCodes->count() > 0 ?
                $qrCodes->sum('usage_count') / $qrCodes->count() : 0,
            'most_used_qr' => $qrCodes->sortByDesc('usage_count')->first(),
            'usage_rate' => $qrCodes->count() > 0 ?
                ($qrCodes->where('usage_count', '>', 0)->count() / $qrCodes->count()) * 100 : 0
        ];
    }

    /**
     * Generate QR code image.
     */
    public function generateImage($size = 200)
    {
        try {
            $qrData = $this->qr_data;

            // Using simple QR code generation (you may want to use a proper QR library)
            $qrImageUrl = "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data=" . urlencode($qrData);

            return $qrImageUrl;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get QR code for today's meal.
     */
    public static function getTodayMealQR($userId, $mealType)
    {
        return self::where('user_id', $userId)
            ->whereDate('created_at', today())
            ->forPurpose('meal_attendance')
            ->active()
            ->notExpired()
            ->whereJsonContains('metadata->meal_type', $mealType)
            ->withUsage()
            ->first();
    }

    /**
     * Revoke all QR codes for user.
     */
    public static function revokeAllForUser($userId)
    {
        return self::where('user_id', $userId)
            ->update(['is_active' => false]);
    }

    /**
     * Revoke all QR codes for mess.
     */
    public static function revokeAllForMess($messId)
    {
        return self::where('mess_id', $messId)
            ->update(['is_active' => false]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mess;
use App\Models\User;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SettingController extends Controller
{
    /**
     * Get all settings
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'nullable|exists:messes,id',
            'category' => 'nullable|in:system,mess,user,notification,payment,meal',
            'key' => 'nullable|string'
        ]);

        $user = Auth::user();

        // Get settings based on category and user permissions
        if ($validated['category']) {
            $settings = $this->getSettingsByCategory($user, $validated['category'], $validated['mess_id']);
        } else {
            $settings = $this->getAllSettings($user, $validated['mess_id']);
        }

        // Filter by specific key if provided
        if ($validated['key']) {
            $settings = $settings->filter(function ($setting) use ($validated) {
                return str_contains($setting['key'], $validated['key']);
            });
        }

        return response()->json([
            'success' => true,
            'data' => $settings->values()->all()
        ]);
    }

    /**
     * Update settings
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'settings' => 'required|array|min:1',
            'mess_id' => 'nullable|exists:messes,id'
        ]);

        $user = Auth::user();
        $messId = $validated['mess_id'];

        // Check authorization
        if ($messId) {
            $mess = Mess::findOrFail($messId);
            if (
                !$user->hasRole('super_admin') &&
                $mess->manager_id !== $user->id &&
                !$mess->members()->where('user_id', $user->id)->exists()
            ) {
                return response()->json(['message' => 'Unauthorized to update mess settings'], 403);
            }
        }

        try {
            DB::beginTransaction();

            $updatedSettings = [];
            foreach ($validated['settings'] as $settingData) {
                $setting = $this->validateAndUpdateSetting($user, $settingData, $messId);
                if ($setting) {
                    $updatedSettings[] = $setting;
                }
            }

            DB::commit();

            // Clear relevant cache
            $this->clearSettingsCache($user, $messId);

            return response()->json([
                'success' => true,
                'message' => 'Settings updated successfully',
                'data' => $updatedSettings
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get system settings (Super Admin only)
     */
    public function systemSettings(Request $request)
    {
        $user = Auth::user();

        if (!$user->hasRole('super_admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'category' => 'nullable|in:system,security,backup,email,sms,gateway'
        ]);

        $settings = $this->getSystemSettings($validated['category']);

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }

    /**
     * Update system settings (Super Admin only)
     */
    public function updateSystemSettings(Request $request)
    {
        $user = Auth::user();

        if (!$user->hasRole('super_admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'settings' => 'required|array|min:1',
            'category' => 'required|in:system,security,backup,email,sms,gateway'
        ]);

        try {
            DB::beginTransaction();

            $updatedSettings = [];
            foreach ($validated['settings'] as $settingData) {
                $setting = $this->validateAndUpdateSystemSetting($settingData, $validated['category']);
                if ($setting) {
                    $updatedSettings[] = $setting;
                }
            }

            DB::commit();

            // Clear system cache
            Cache::forget('system_settings_' . $validated['category']);

            return response()->json([
                'success' => true,
                'message' => 'System settings updated successfully',
                'data' => $updatedSettings
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update system settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset settings to defaults
     */
    public function reset(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'nullable|exists:messes,id',
            'category' => 'required|in:system,mess,user,notification,payment,meal'
        ]);

        $user = Auth::user();
        $messId = $validated['mess_id'];

        // Check authorization
        if ($messId) {
            $mess = Mess::findOrFail($messId);
            if (
                !$user->hasRole('super_admin') &&
                $mess->manager_id !== $user->id &&
                !$mess->members()->where('user_id', $user->id)->exists()
            ) {
                return response()->json(['message' => 'Unauthorized to reset settings'], 403);
            }
        }

        try {
            DB::beginTransaction();

            $defaultSettings = $this->getDefaultSettings($validated['category']);

            foreach ($defaultSettings as $defaultSetting) {
                Setting::updateOrCreate(
                    [
                        'key' => $defaultSetting['key'],
                        'user_id' => $user->id,
                        'mess_id' => $messId,
                        'category' => $validated['category']
                    ],
                    [
                        'value' => $defaultSetting['value'],
                        'updated_by' => $user->id
                    ]
                );
            }

            DB::commit();

            // Clear cache
            $this->clearSettingsCache($user, $messId);

            return response()->json([
                'success' => true,
                'message' => 'Settings reset to defaults successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to reset settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user preferences
     */
    public function preferences(Request $request)
    {
        $user = Auth::user();

        $preferences = $this->getUserPreferences($user);

        return response()->json([
            'success' => true,
            'data' => $preferences
        ]);
    }

    /**
     * Update user preferences
     */
    public function updatePreferences(Request $request)
    {
        $validated = $request->validate([
            'preferences' => 'required|array|min:1'
        ]);

        $user = Auth::user();

        try {
            DB::beginTransaction();

            $updatedPreferences = [];
            foreach ($validated['preferences'] as $key => $value) {
                $preference = Setting::updateOrCreate(
                    [
                        'key' => $key,
                        'user_id' => $user->id,
                        'category' => 'user_preference'
                    ],
                    [
                        'value' => is_array($value) ? json_encode($value) : $value,
                        'updated_by' => $user->id
                    ]
                );

                $updatedPreferences[] = [
                    'key' => $key,
                    'value' => $value,
                    'updated_at' => now()
                ];
            }

            DB::commit();

            // Clear user preferences cache
            Cache::forget('user_preferences_' . $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Preferences updated successfully',
                'data' => $updatedPreferences
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update preferences: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get settings by category
     */
    private function getSettingsByCategory(User $user, string $category, ?int $messId): \Illuminate\Support\Collection
    {
        $cacheKey = "settings_{$category}_{$user->id}_{$messId}";

        return Cache::remember($cacheKey, 3600, function () use ($user, $category, $messId) {
            $query = Setting::where('category', $category)
                ->where('user_id', $user->id);

            if ($messId) {
                $query->where('mess_id', $messId);
            }

            return $query->get();
        });
    }

    /**
     * Get all settings for user
     */
    private function getAllSettings(User $user, ?int $messId): \Illuminate\Support\Collection
    {
        $cacheKey = "all_settings_{$user->id}_{$messId}";

        return Cache::remember($cacheKey, 3600, function () use ($user, $messId) {
            $query = Setting::where('user_id', $user->id);

            if ($messId) {
                $query->where('mess_id', $messId);
            }

            return $query->get();
        });
    }

    /**
     * Get system settings
     */
    private function getSystemSettings(?string $category): \Illuminate\Support\Collection
    {
        $cacheKey = "system_settings_{$category}";

        return Cache::remember($cacheKey, 3600, function () use ($category) {
            return Setting::where('category', $category)
                ->whereNull('user_id')
                ->whereNull('mess_id')
                ->get();
        });
    }

    /**
     * Get user preferences
     */
    private function getUserPreferences(User $user): \Illuminate\Support\Collection
    {
        return Cache::remember('user_preferences_' . $user->id, 3600, function () use ($user) {
            return Setting::where('category', 'user_preference')
                ->where('user_id', $user->id)
                ->get();
        });
    }

    /**
     * Validate and update setting
     */
    private function validateAndUpdateSetting(User $user, array $settingData, ?int $messId): ?Setting
    {
        $rules = $this->getValidationRules($settingData['key']);

        $validator = validator($settingData, $rules);

        if ($validator->fails()) {
            return null;
        }

        // Check authorization for specific settings
        if (!$this->canUpdateSetting($user, $settingData['key'], $messId)) {
            return null;
        }

        return Setting::updateOrCreate(
            [
                'key' => $settingData['key'],
                'user_id' => $user->id,
                'mess_id' => $messId,
                'category' => $settingData['category'] ?? 'user'
            ],
            [
                'value' => $settingData['value'],
                'updated_by' => $user->id
            ]
        );
    }

    /**
     * Validate and update system setting
     */
    private function validateAndUpdateSystemSetting(array $settingData, string $category): ?Setting
    {
        $rules = $this->getSystemValidationRules($settingData['key']);

        $validator = validator($settingData, $rules);

        if ($validator->fails()) {
            return null;
        }

        return Setting::updateOrCreate(
            [
                'key' => $settingData['key'],
                'category' => $category,
                'user_id' => null,
                'mess_id' => null
            ],
            [
                'value' => $settingData['value'],
                'updated_by' => Auth::id()
            ]
        );
    }

    /**
     * Check if user can update setting
     */
    private function canUpdateSetting(User $user, string $key, ?int $messId): bool
    {
        // Super admin can update any setting
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Mess manager can update mess settings
        if ($messId) {
            $mess = Mess::find($messId);
            if ($mess && $mess->manager_id === $user->id) {
                return true;
            }
        }

        // Users can update their own settings and general settings
        $userOnlySettings = [
            'language',
            'timezone',
            'date_format',
            'currency',
            'notification_email',
            'notification_sms',
            'notification_push',
            'theme',
            'dashboard_layout',
            'meal_reminders'
        ];

        return in_array($key, $userOnlySettings);
    }

    /**
     * Get validation rules for setting
     */
    private function getValidationRules(string $key): array
    {
        $rules = [
            'language' => 'required|string|in:en,bn',
            'timezone' => 'required|string|timezone',
            'date_format' => 'required|string|in:Y-m-d,m/d/Y,d-m-Y',
            'currency' => 'required|string|in:BDT,USD,EUR',
            'notification_email' => 'required|boolean',
            'notification_sms' => 'required|boolean',
            'notification_push' => 'required|boolean',
            'theme' => 'required|string|in:light,dark,auto',
            'dashboard_layout' => 'required|string|in:grid,list,cards',
            'meal_reminders' => 'required|boolean',
            'meal_cutoff_time' => 'required|date_format:H:i',
            'auto_approve_expenses' => 'required|boolean',
            'auto_approve_payments' => 'required|boolean',
            'receipt_required' => 'required|boolean',
            'min_payment_amount' => 'required|numeric|min:0',
            'max_payment_amount' => 'required|numeric|min:0',
            'backup_frequency' => 'required|in:daily,weekly,monthly',
            'sms_gateway_provider' => 'required|string',
            'sms_api_key' => 'required|string',
            'payment_gateway' => 'required|in:stripe,bkash,nagad,manual',
            'payment_gateway_config' => 'required|array',
            'maintenance_mode' => 'required|boolean',
            'maintenance_message' => 'nullable|string',
        ];

        return $rules[$key] ?? [];
    }

    /**
     * Get validation rules for system setting
     */
    private function getSystemValidationRules(string $key): array
    {
        $rules = [
            'site_name' => 'required|string|max:255',
            'site_description' => 'nullable|string|max:1000',
            'default_language' => 'required|string|in:en,bn',
            'default_timezone' => 'required|string|timezone',
            'default_currency' => 'required|string|in:BDT,USD,EUR',
            'registration_enabled' => 'required|boolean',
            'email_verification_required' => 'required|boolean',
            'sms_verification_enabled' => 'required|boolean',
            'max_upload_size' => 'required|integer|min:1|max:20480',
            'allowed_file_types' => 'required|array',
            'backup_retention_days' => 'required|integer|min:1|max:365',
            'auto_cleanup_days' => 'required|integer|min:1|max:365',
            'security_headers' => 'required|array',
            'rate_limiting_enabled' => 'required|boolean',
            'max_requests_per_minute' => 'required|integer|min:1|max:1000',
            'session_timeout' => 'required|integer|min:5|max:120',
        ];

        return $rules[$key] ?? [];
    }

    /**
     * Get default settings
     */
    private function getDefaultSettings(string $category): array
    {
        $defaults = [
            'system' => [
                ['key' => 'language', 'value' => 'en'],
                ['key' => 'timezone', 'value' => 'Asia/Dhaka'],
                ['key' => 'date_format', 'value' => 'Y-m-d'],
                ['key' => 'currency', 'value' => 'BDT'],
                ['key' => 'notification_email', 'value' => true],
                ['key' => 'notification_sms', 'value' => true],
                ['key' => 'notification_push', 'value' => true],
                ['key' => 'theme', 'value' => 'auto'],
                ['key' => 'dashboard_layout', 'value' => 'grid'],
                ['key' => 'meal_reminders', 'value' => true],
            ],
            'mess' => [
                ['key' => 'meal_cutoff_time', 'value' => '10:00'],
                ['key' => 'auto_approve_expenses', 'value' => false],
                ['key' => 'auto_approve_payments', 'value' => false],
                ['key' => 'receipt_required', 'value' => false],
                ['key' => 'min_payment_amount', 'value' => 0],
                ['key' => 'max_payment_amount', 'value' => 10000],
            ],
            'notification' => [
                ['key' => 'due_reminder_days', 'value' => 3],
                ['key' => 'meal_reminder_time', 'value' => '08:00'],
                ['key' => 'announcement_notification', 'value' => true],
            ],
            'payment' => [
                ['key' => 'default_method', 'value' => 'cash'],
                ['key' => 'auto_confirm', 'value' => false],
            ],
        ];

        return $defaults[$category] ?? [];
    }

    /**
     * Clear settings cache
     */
    private function clearSettingsCache(User $user, ?int $messId): void
    {
        $patterns = [
            "settings_{$user->id}_{$messId}",
            "all_settings_{$user->id}_{$messId}",
            "user_preferences_{$user->id}",
        ];

        foreach ($patterns as $pattern) {
            Cache::forget($pattern);
        }
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Setting extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value',
        'description',
        'category',
        'user_id',
        'mess_id',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'value' => 'json',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the user who owns the setting
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the mess associated with the setting
     */
    public function mess()
    {
        return $this->belongsTo(Mess::class);
    }

    /**
     * Get the user who updated the setting
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope to get settings by category
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to get settings by user
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get settings by mess
     */
    public function scopeByMess($query, $messId)
    {
        return $query->where('mess_id', $messId);
    }

    /**
     * Scope to get user settings
     */
    public function scopeUserSettings($query)
    {
        return $query->where('category', 'user');
    }

    /**
     * Scope to get mess settings
     */
    public function scopeMessSettings($query)
    {
        return $query->where('category', 'mess');
    }

    /**
     * Scope to get system settings
     */
    public function scopeSystemSettings($query)
    {
        return $query->where('category', 'system');
    }

    /**
     * Get the formatted value
     */
    public function getFormattedValueAttribute()
    {
        $value = $this->value;

        // Handle JSON values
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }

    /**
     * Check if setting is a boolean
     */
    public function isBoolean(): bool
    {
        return in_array($this->key, [
            'notification_email',
            'notification_sms',
            'notification_push',
            'auto_approve_expenses',
            'auto_approve_payments',
            'receipt_required',
            'registration_enabled'
        ]);
    }

    /**
     * Check if setting is a JSON array
     */
    public function isArray(): bool
    {
        return in_array($this->key, [
            'allowed_file_types',
            'payment_gateway_config',
            'security_headers'
        ]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class ExpenseCategory extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'name',
        'description',
        'color',
        'icon',
        'is_default',
        'is_active'
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean'
    ];

    /**
     * Get expenses for the category.
     */
    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Scope a query to only include active categories.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include default categories.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Check if category is active.
     */
    public function isActive()
    {
        return $this->is_active;
    }

    /**
     * Check if category is default.
     */
    public function isDefault()
    {
        return $this->is_default;
    }

    /**
     * Get formatted color with hash.
     */
    public function getFormattedColorAttribute()
    {
        return '#' . $this->color;
    }

    /**
     * Get icon URL or class.
     */
    public function getIconAttribute()
    {
        if (!$this->icon) {
            return null;
        }

        // If icon starts with 'fas-', it's a Font Awesome class
        if (str_starts_with($this->icon, 'fas-')) {
            return $this->icon;
        }

        // Otherwise, treat as URL
        return asset($this->icon);
    }
}

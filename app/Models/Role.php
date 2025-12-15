<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'permissions',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'permissions' => 'array',
    ];

    /**
     * Get the users for the role.
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Check if role has a specific permission.
     */
    public function hasPermission($permission)
    {
        return is_array($this->permissions) &&
            in_array($permission, $this->permissions);
    }

    /**
     * Get all available permissions.
     */
    public static function getAllPermissions()
    {
        return [
            // User Management
            'users.create',
            'users.read',
            'users.update',
            'users.delete',

            // Member Management
            'members.create',
            'members.read',
            'members.update',
            'members.delete',

            // Meal Management
            'meals.create',
            'meals.read',
            'meals.update',
            'meals.delete',
            'meals.lock',

            // Bazar Management
            'bazars.create',
            'bazars.read',
            'bazars.update',
            'bazars.delete',
            'bazars.approve',

            // Expense Management
            'expenses.create',
            'expenses.read',
            'expenses.update',
            'expenses.delete',
            'expenses.approve',

            // Billing Management
            'bills.create',
            'bills.read',
            'bills.update',
            'bills.delete',

            // Payment Management
            'payments.create',
            'payments.read',
            'payments.update',
            'payments.delete',
            'payments.approve',

            // Dashboard Access
            'dashboard.admin',
            'dashboard.member',

            // Reports
            'reports.generate',
            'reports.export',

            // Settings
            'settings.update',
            'settings.read',

            // Announcements
            'announcements.create',
            'announcements.read',
            'announcements.update',
            'announcements.delete',
        ];
    }

    /**
     * Get default permissions for each role.
     */
    public static function getDefaultPermissions()
    {
        return [
            'super_admin' => self::getAllPermissions(),
            'admin' => [
                'members.create',
                'members.read',
                'members.update',
                'members.delete',
                'meals.create',
                'meals.read',
                'meals.update',
                'meals.delete',
                'meals.lock',
                'bazars.create',
                'bazars.read',
                'bazars.update',
                'bazars.delete',
                'bazars.approve',
                'expenses.create',
                'expenses.read',
                'expenses.update',
                'expenses.delete',
                'expenses.approve',
                'bills.create',
                'bills.read',
                'bills.update',
                'bills.delete',
                'payments.create',
                'payments.read',
                'payments.update',
                'payments.delete',
                'payments.approve',
                'dashboard.admin',
                'reports.generate',
                'reports.export',
                'announcements.create',
                'announcements.read',
                'announcements.update',
                'announcements.delete',
            ],
            'staff' => [
                'meals.create',
                'meals.read',
                'meals.update',
                'bazars.create',
                'bazars.read',
                'bazars.update',
                'expenses.create',
                'expenses.read',
                'expenses.update',
                'dashboard.admin',
                'announcements.read',
            ],
            'member' => [
                'meals.create',
                'meals.read',
                'meals.update',
                'dashboard.member',
                'announcements.read',
            ],
        ];
    }
}

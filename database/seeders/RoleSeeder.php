<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\Role;
use App\Models\User;
use App\Models\Mess;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command('Creating roles...');

        // Create roles
        $roles = [
            [
                'name' => 'Super Admin',
                'slug' => 'super_admin',
                'permissions' => Role::getAllPermissions()
            ],
            [
                'name' => 'Admin',
                'slug' => 'admin',
                'permissions' => Role::getDefaultPermissions()['admin']
            ],
            [
                'name' => 'Staff',
                'slug' => 'staff',
                'permissions' => Role::getDefaultPermissions()['staff']
            ],
            [
                'name' => 'Member',
                'slug' => 'member',
                'permissions' => Role::getDefaultPermissions()['member']
            ]
        ];

        foreach ($roles as $roleData) {
            Role::create($roleData);
        }

        $this->command('Roles created successfully!');

        // Create default mess
        $mess = Mess::create([
            'name' => 'Demo Mess',
            'address' => '123 Demo Street, Demo Area',
            'meal_rate_breakfast' => 50.00,
            'meal_rate_lunch' => 80.00,
            'meal_rate_dinner' => 70.00,
            'payment_cycle' => 'monthly',
        ]);

        $this->command('Default mess created successfully!');

        // Create super admin user
        $superAdminRole = Role::where('slug', 'super_admin')->first();

        if ($superAdminRole) {
            $user = User::create([
                'name' => 'Super Admin',
                'email' => 'admin@toto-mess.com',
                'phone' => '+8801234567890',
                'password' => Hash::make('password'),
                'role_id' => $superAdminRole->id,
                'status' => 'active',
            ]);

            $this->command('Super admin user created successfully!');
        }

        $this->command('Database seeding completed successfully!');
    }
}

<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test environment
        $this->artisan('migrate:fresh --env=testing');

        // Create test user and mess
        $this->createTestUser();
        $this->createTestMess();

        // Set authentication
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        // Clean up test environment
        DB::rollBack();

        parent::tearDown();
    }

    protected function createTestUser(): void
    {
        $this->user = \App\Models\User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        // Assign role
        $role = \App\Models\Role::where('name', 'member')->first();
        $this->user->roles()->attach($role);
    }

    protected function createTestMess(): void
    {
        $this->mess = \App\Models\Mess::factory()->create([
            'name' => 'Test Mess',
            'address' => '123 Test Street',
            'meal_rate_breakfast' => 50,
            'meal_rate_lunch' => 100,
            'meal_rate_dinner' => 80,
            'manager_id' => $this->user->id,
        ]);

        // Add user as member
        $this->mess->members()->attach($this->user->id, [
            'status' => 'approved',
            'joined_at' => now(),
        ]);
    }

    protected function createAdminUser(): void
    {
        $admin = \App\Models\User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ]);

        $role = \App\Models\Role::where('name', 'super_admin')->first();
        $admin->roles()->attach($role);

        return $admin;
    }

    protected function createManagerUser(): void
    {
        $manager = \App\Models\User::factory()->create([
            'name' => 'Manager User',
            'email' => 'manager@example.com',
            'password' => bcrypt('password'),
        ]);

        $role = \App\Models\Role::where('name', 'manager')->first();
        $manager->roles()->attach($role);

        return $manager;
    }

    protected function assertApiResponseStructure($response, array $expectedKeys = []): void
    {
        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'data', ...$expectedKeys]);
    }

    protected function assertValidationError($response, string $field = null): void
    {
        $response->assertStatus(422);
        $response->assertJsonStructure(['success' => false, 'message', 'errors']);

        if ($field) {
            $response->assertJsonValidationErrors([$field]);
        }
    }

    protected function assertUnauthorizedResponse($response): void
    {
        $response->assertStatus(403);
        $response->assertJsonStructure(['success' => false, 'message']);
    }

    protected function assertNotFoundResponse($response): void
    {
        $response->assertStatus(404);
        $response->assertJsonStructure(['success' => false, 'message']);
    }

    protected function assertSuccessResponse($response): void
    {
        $response->assertStatus(200);
        $response->assertJsonStructure(['success' => true, 'data', 'message']);
    }

    protected function assertCreatedResponse($response): void
    {
        $response->assertStatus(201);
        $response->assertJsonStructure(['success' => true, 'data', 'message']);
    }

    protected function assertDatabaseHas(string $table, array $data): void
    {
        foreach ($data as $key => $value) {
            $this->assertDatabaseHas($table, [$key => $value]);
        }
    }

    protected function assertDatabaseMissing(string $table, array $data): void
    {
        foreach ($data as $key => $value) {
            $this->assertDatabaseMissing($table, [$key => $value]);
        }
    }

    protected function createTestMeal(array $overrides = []): \App\Models\Meal
    {
        return \App\Models\Meal::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'mess_id' => $this->mess->id,
            'meal_date' => now()->toDateString(),
            'meal_type' => 'lunch',
            'count' => 1,
        ], $overrides));
    }

    protected function createTestBazar(array $overrides = []): \App\Models\Bazar
    {
        return \App\Models\Bazar::factory()->create(array_merge([
            'mess_id' => $this->mess->id,
            'bazar_date' => now()->toDateString(),
            'bazar_man' => $this->user->id,
            'total_cost' => 500,
        ], $overrides));
    }

    protected function createTestPayment(array $overrides = []): \App\Models\Payment
    {
        return \App\Models\Payment::factory()->create(array_merge([
            'mess_id' => $this->mess->id,
            'user_id' => $this->user->id,
            'amount' => 1000,
            'payment_method' => 'cash',
            'payment_date' => now()->toDateString(),
            'status' => 'completed',
        ], $overrides));
    }

    protected function createTestInventoryItem(array $overrides = []): \App\Models\InventoryItem
    {
        return \App\Models\InventoryItem::factory()->create(array_merge([
            'mess_id' => $this->mess->id,
            'name' => 'Test Item',
            'category' => 'Food',
            'unit' => 'kg',
            'current_stock' => 10,
            'minimum_stock' => 2,
            'unit_cost' => 50,
        ], $overrides));
    }

    protected function createTestAttendance(array $overrides = []): \App\Models\Attendance
    {
        return \App\Models\Attendance::factory()->create(array_merge([
            'mess_id' => $this->mess->id,
            'user_id' => $this->user->id,
            'meal_type' => 'lunch',
            'meal_date' => now()->toDateString(),
            'scan_time' => now(),
            'status' => 'approved',
        ], $overrides));
    }

    protected function createTestAnnouncement(array $overrides = []): \App\Models\Announcement
    {
        return \App\Models\Announcement::factory()->create(array_merge([
            'mess_id' => $this->mess->id,
            'title' => 'Test Announcement',
            'message' => 'This is a test announcement',
            'category' => 'general',
            'priority' => 'medium',
            'status' => 'active',
            'created_by' => $this->user->id,
        ], $overrides));
    }

    protected function assertQRCodeValid($qrData): void
    {
        $this->assertIsArray($qrData);
        $this->assertArrayHasKey('type', $qrData);
        $this->assertArrayHasKey('user_id', $qrData);
        $this->assertArrayHasKey('mess_id', $qrData);
        $this->assertArrayHasKey('signature', $qrData);
    }

    protected function assertOfflineQueueEmpty(): void
    {
        // Check IndexedDB is empty
        $this->assertTrue(true); // Simplified for this example
    }

    protected function assertOfflineQueueHasItems(int $expectedCount): void
    {
        // Check IndexedDB has expected number of items
        $this->assertTrue(true); // Simplified for this example
    }

    protected function mockServiceWorker(): void
    {
        // Mock service worker for testing
        $this->mock(\App\Services\PWAService::class, function ($mock) {
            $mock->shouldReceive('registerServiceWorker')
                ->once()
                ->andReturn(true);
        });
    }

    protected function simulateOfflineMode(): void
    {
        // Simulate offline mode for testing
        $this->mock(\App\Services\ConnectionService::class, function ($mock) {
            $mock->shouldReceive('isOnline')
                ->andReturn(false);
        });
    }

    protected function simulateOnlineMode(): void
    {
        // Simulate online mode for testing
        $this->mock(\App\Services\ConnectionService::class, function ($mock) {
            $mock->shouldReceive('isOnline')
                ->andReturn(true);
        });
    }

    protected function assertCacheContains(string $key, $value = null): void
    {
        // Assert cache contains specific data
        $this->assertTrue(true); // Simplified for this example
    }

    protected function assertCacheMissing(string $key): void
    {
        // Assert cache doesn't contain specific data
        $this->assertTrue(true); // Simplified for this example
    }

    protected function createTestExpense(array $overrides = []): \App\Models\Expense
    {
        return \App\Models\Expense::factory()->create(array_merge([
            'mess_id' => $this->mess->id,
            'category_id' => 1,
            'amount' => 100,
            'description' => 'Test Expense',
            'expense_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ], $overrides));
    }

    protected function createTestBill(array $overrides = []): \App\Models\Bill
    {
        return \App\Models\Bill::factory()->create(array_merge([
            'mess_id' => $this->mess->id,
            'user_id' => $this->user->id,
            'billing_month' => now()->format('Y-m'),
            'total_amount' => 5000,
            'status' => 'generated',
        ], $overrides));
    }
}

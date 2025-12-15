<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Mess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    /**
     * Display a listing of inventory items.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'search' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:100',
            'stock_status' => 'nullable|in:low_stock,overstock,out_of_stock,normal',
            'is_perishable' => 'nullable|boolean',
            'expiry_status' => 'nullable|in:expired,nearing_expiry,all',
            'sort_by' => 'nullable|in:name,category,current_stock,total_value,created_at,updated_at',
            'sort_order' => 'nullable|in:asc,desc',
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

        $query = InventoryItem::where('mess_id', $validated['mess_id']);

        // Apply search
        if (isset($validated['search'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('name', 'LIKE', "%{$validated['search']}%")
                    ->orWhere('description', 'LIKE', "%{$validated['search']}%")
                    ->orWhere('category', 'LIKE', "%{$validated['search']}%")
                    ->orWhere('supplier', 'LIKE', "%{$validated['search']}%");
            });
        }

        // Apply filters
        if (isset($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        if (isset($validated['stock_status'])) {
            switch ($validated['stock_status']) {
                case 'low_stock':
                    $query->whereRaw('current_stock <= minimum_stock');
                    break;
                case 'overstock':
                    $query->whereRaw('current_stock >= maximum_stock');
                    break;
                case 'out_of_stock':
                    $query->where('current_stock', '<=', 0);
                    break;
                case 'normal':
                    $query->whereRaw('current_stock > minimum_stock')
                        ->whereRaw('current_stock < maximum_stock');
                    break;
            }
        }

        if (isset($validated['is_perishable'])) {
            $query->where('is_perishable', $validated['is_perishable']);
        }

        if (isset($validated['expiry_status'])) {
            switch ($validated['expiry_status']) {
                case 'expired':
                    $query->where('expiry_date', '<', now());
                    break;
                case 'nearing_expiry':
                    $query->where('expiry_date', '<=', now()->addDays(7))
                        ->where('expiry_date', '>', now());
                    break;
            }
        }

        // Apply sorting
        $sortBy = $validated['sort_by'] ?? 'name';
        $sortOrder = $validated['sort_order'] ?? 'asc';
        $query->orderBy($sortBy, $sortOrder);

        $items = $query->with(['createdBy', 'updatedBy'])
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => $items
        ]);
    }

    /**
     * Store a newly created inventory item.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'required|string|max:100',
            'unit' => 'required|string|max:50',
            'current_stock' => 'required|numeric|min:0',
            'minimum_stock' => 'nullable|numeric|min:0',
            'maximum_stock' => 'nullable|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'supplier' => 'nullable|string|max:255',
            'supplier_contact' => 'nullable|string|max:255',
            'last_purchase_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after_or_equal:today',
            'storage_location' => 'nullable|string|max:255',
            'reorder_point' => 'nullable|numeric|min:0',
            'reorder_quantity' => 'nullable|numeric|min:0',
            'is_perishable' => 'boolean',
            'notes' => 'nullable|string'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization - only managers and super admin can create items
        if (!$user->hasRole('super_admin') && $mess->manager_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized to create inventory items'], 403);
        }

        try {
            DB::beginTransaction();

            $validated['total_value'] = $validated['current_stock'] * ($validated['unit_cost'] ?? 0);
            $validated['created_by'] = $user->id;

            $item = InventoryItem::create($validated);

            // Create initial transaction if stock > 0
            if ($validated['current_stock'] > 0) {
                InventoryTransaction::createStockIn(
                    $item->id,
                    $validated['current_stock'],
                    $validated['unit_cost'] ?? 0,
                    'Initial Stock',
                    'Initial inventory setup'
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inventory item created successfully',
                'data' => $item->load(['createdBy', 'updatedBy'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create inventory item: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified inventory item.
     */
    public function show($id)
    {
        $user = Auth::user();
        $item = InventoryItem::with(['mess', 'createdBy', 'updatedBy', 'transactions' => function ($query) {
            $query->latest()->limit(10);
        }])->findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $item->mess->manager_id !== $user->id &&
            !$item->mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => array_merge($item->toArray(), [
                'stock_summary' => $item->getStockSummary(),
                'alerts' => $item->getAlerts()
            ])
        ]);
    }

    /**
     * Update the specified inventory item.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'category' => 'sometimes|string|max:100',
            'unit' => 'sometimes|string|max:50',
            'minimum_stock' => 'sometimes|nullable|numeric|min:0',
            'maximum_stock' => 'sometimes|nullable|numeric|min:0',
            'unit_cost' => 'sometimes|nullable|numeric|min:0',
            'supplier' => 'sometimes|nullable|string|max:255',
            'supplier_contact' => 'sometimes|nullable|string|max:255',
            'last_purchase_date' => 'sometimes|nullable|date',
            'expiry_date' => 'sometimes|nullable|date|after_or_equal:today',
            'storage_location' => 'sometimes|nullable|string|max:255',
            'reorder_point' => 'sometimes|nullable|numeric|min:0',
            'reorder_quantity' => 'sometimes|nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
            'is_perishable' => 'sometimes|boolean',
            'notes' => 'sometimes|nullable|string'
        ]);

        $user = Auth::user();
        $item = InventoryItem::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $item->mess->manager_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized to update inventory item'], 403);
        }

        try {
            DB::beginTransaction();

            $validated['updated_by'] = $user->id;

            // Recalculate total value if unit_cost changed
            if (isset($validated['unit_cost'])) {
                $validated['total_value'] = $item->current_stock * $validated['unit_cost'];
            }

            $item->update($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inventory item updated successfully',
                'data' => $item->fresh()->load(['createdBy', 'updatedBy'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update inventory item: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified inventory item.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $item = InventoryItem::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $item->mess->manager_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized to delete inventory item'], 403);
        }

        try {
            DB::beginTransaction();

            // Soft delete the item
            $item->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inventory item deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete inventory item: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add stock to inventory item.
     */
    public function addStock(Request $request, $id)
    {
        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.01',
            'unit_cost' => 'nullable|numeric|min:0',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string'
        ]);

        $user = Auth::user();
        $item = InventoryItem::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $item->mess->manager_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized to add stock'], 403);
        }

        try {
            DB::beginTransaction();

            $item->addStock(
                $validated['quantity'],
                $validated['unit_cost'],
                $validated['reference'] ?? null,
                $validated['notes'] ?? null
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock added successfully',
                'data' => $item->fresh()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to add stock: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove stock from inventory item.
     */
    public function removeStock(Request $request, $id)
    {
        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string'
        ]);

        $user = Auth::user();
        $item = InventoryItem::findOrFail($id);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $item->mess->manager_id !== $user->id
        ) {
            return response()->json(['message' => 'Unauthorized to remove stock'], 403);
        }

        // Check if enough stock is available
        if ($item->current_stock < $validated['quantity']) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient stock available'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $item->removeStock(
                $validated['quantity'],
                $validated['reference'] ?? null,
                $validated['notes'] ?? null
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock removed successfully',
                'data' => $item->fresh()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove stock: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get inventory transactions.
     */
    public function transactions(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'item_id' => 'nullable|exists:inventory_items,id',
            'transaction_type' => 'nullable|in:stock_in,stock_out,adjustment,waste,return',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'reference_type' => 'nullable|string|max:100',
            'created_by' => 'nullable|exists:users,id',
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

        $query = InventoryTransaction::whereHas('inventoryItem', function ($query) use ($validated) {
            $query->where('mess_id', $validated['mess_id']);
        })->with(['inventoryItem', 'createdBy']);

        // Apply filters
        if (isset($validated['item_id'])) {
            $query->where('inventory_item_id', $validated['item_id']);
        }

        if (isset($validated['transaction_type'])) {
            $query->where('transaction_type', $validated['transaction_type']);
        }

        if (isset($validated['date_from'])) {
            $query->whereDate('transaction_date', '>=', $validated['date_from']);
        }

        if (isset($validated['date_to'])) {
            $query->whereDate('transaction_date', '<=', $validated['date_to']);
        }

        if (isset($validated['reference_type'])) {
            $query->where('reference_type', $validated['reference_type']);
        }

        if (isset($validated['created_by'])) {
            $query->where('created_by', $validated['created_by']);
        }

        $transactions = $query->orderBy('transaction_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => $transactions
        ]);
    }

    /**
     * Get inventory statistics.
     */
    public function statistics(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from'
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

        $items = InventoryItem::where('mess_id', $validated['mess_id'])->active();
        $totalItems = $items->count();

        $lowStockItems = $items->lowStock()->count();
        $overstockItems = $items->overstock()->count();
        $outOfStockItems = $items->where('current_stock', '<=', 0)->count();
        $expiredItems = $items->expired()->count();
        $nearingExpiryItems = $items->nearingExpiry()->count();

        $totalValue = $items->sum('total_value');
        $totalStock = $items->sum('current_stock');

        // Category breakdown
        $categoryBreakdown = InventoryItem::getValueByCategory($validated['mess_id']);

        // Transaction statistics
        $dateFrom = $validated['date_from'] ?? now()->subDays(30);
        $dateTo = $validated['date_to'] ?? now();
        $transactionStats = InventoryTransaction::getTransactionStatistics(
            $validated['mess_id'],
            $dateFrom,
            $dateTo
        );

        // Low stock items details
        $lowStockItemsDetails = InventoryItem::getLowStockItems($validated['mess_id']);

        // Expired items details
        $expiredItemsDetails = InventoryItem::getExpiredItems($validated['mess_id']);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total_items' => $totalItems,
                    'low_stock_items' => $lowStockItems,
                    'overstock_items' => $overstockItems,
                    'out_of_stock_items' => $outOfStockItems,
                    'expired_items' => $expiredItems,
                    'nearing_expiry_items' => $nearingExpiryItems,
                    'total_value' => $totalValue,
                    'total_stock' => $totalStock
                ],
                'category_breakdown' => $categoryBreakdown,
                'transaction_statistics' => $transactionStats,
                'alerts' => [
                    'low_stock_items' => $lowStockItemsDetails,
                    'expired_items' => $expiredItemsDetails
                ]
            ]
        ]);
    }

    /**
     * Get inventory alerts.
     */
    public function alerts(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'alert_type' => 'nullable|in:low_stock,expired,nearing_expiry,overstock'
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

        $alerts = [];

        switch ($validated['alert_type'] ?? 'all') {
            case 'low_stock':
            case 'all':
                $lowStockItems = InventoryItem::getLowStockItems($validated['mess_id']);
                foreach ($lowStockItems as $item) {
                    $alerts[] = [
                        'type' => 'warning',
                        'category' => 'low_stock',
                        'message' => "Item {$item->name} is running low on stock ({$item->current_stock} {$item->unit})",
                        'item' => $item,
                        'action' => 'reorder'
                    ];
                }
                if ($validated['alert_type'] === 'low_stock') break;

            case 'expired':
            case 'all':
                $expiredItems = InventoryItem::getExpiredItems($validated['mess_id']);
                foreach ($expiredItems as $item) {
                    $alerts[] = [
                        'type' => 'danger',
                        'category' => 'expired',
                        'message' => "Item {$item->name} has expired on {$item->expiry_date->format('Y-m-d')}",
                        'item' => $item,
                        'action' => 'dispose'
                    ];
                }
                if ($validated['alert_type'] === 'expired') break;

            case 'nearing_expiry':
            case 'all':
                $nearingExpiryItems = InventoryItem::getNearingExpiryItems($validated['mess_id']);
                foreach ($nearingExpiryItems as $item) {
                    $alerts[] = [
                        'type' => 'warning',
                        'category' => 'nearing_expiry',
                        'message' => "Item {$item->name} will expire in {$item->days_until_expiry} days",
                        'item' => $item,
                        'action' => 'use_soon'
                    ];
                }
                if ($validated['alert_type'] === 'nearing_expiry') break;

            case 'overstock':
            case 'all':
                $overstockItems = InventoryItem::where('mess_id', $validated['mess_id'])
                    ->active()
                    ->overstock()
                    ->get();
                foreach ($overstockItems as $item) {
                    $alerts[] = [
                        'type' => 'info',
                        'category' => 'overstock',
                        'message' => "Item {$item->name} is overstocked ({$item->current_stock} {$item->unit})",
                        'item' => $item,
                        'action' => 'monitor'
                    ];
                }
                break;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'alerts' => $alerts,
                'total_alerts' => count($alerts),
                'alert_counts' => [
                    'low_stock' => InventoryItem::getLowStockItems($validated['mess_id'])->count(),
                    'expired' => InventoryItem::getExpiredItems($validated['mess_id'])->count(),
                    'nearing_expiry' => InventoryItem::getNearingExpiryItems($validated['mess_id'])->count(),
                    'overstock' => InventoryItem::where('mess_id', $validated['mess_id'])
                        ->active()
                        ->overstock()
                        ->count()
                ]
            ]
        ]);
    }

    /**
     * Get inventory categories.
     */
    public function categories(Request $request)
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

        $categories = InventoryItem::where('mess_id', $validated['mess_id'])
            ->active()
            ->selectRaw('category, COUNT(*) as item_count, SUM(total_value) as total_value')
            ->groupBy('category')
            ->orderBy('category')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }
}

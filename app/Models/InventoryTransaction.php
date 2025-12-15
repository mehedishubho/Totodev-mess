<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryTransaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'inventory_item_id',
        'transaction_type',
        'quantity',
        'unit_cost',
        'total_cost',
        'reference',
        'reference_type',
        'reference_id',
        'notes',
        'transaction_date',
        'created_by'
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'transaction_date' => 'date'
    ];

    /**
     * Get the inventory item for the transaction.
     */
    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * Get the user who created the transaction.
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the reference model (polymorphic relationship).
     */
    public function reference()
    {
        return $this->morphTo();
    }

    /**
     * Scope a query to only include stock in transactions.
     */
    public function scopeStockIn($query)
    {
        return $query->where('transaction_type', 'stock_in');
    }

    /**
     * Scope a query to only include stock out transactions.
     */
    public function scopeStockOut($query)
    {
        return $query->where('transaction_type', 'stock_out');
    }

    /**
     * Scope a query to only include transactions for a specific date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('transaction_date', [$startDate, $endDate]);
    }

    /**
     * Scope a query to only include transactions for a specific reference type.
     */
    public function scopeByReferenceType($query, $referenceType)
    {
        return $query->where('reference_type', $referenceType);
    }

    /**
     * Scope a query to only include transactions for a specific reference.
     */
    public function scopeByReference($query, $referenceType, $referenceId)
    {
        return $query->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId);
    }

    /**
     * Get transaction type label.
     */
    public function getTransactionTypeLabelAttribute()
    {
        return [
            'stock_in' => 'Stock In',
            'stock_out' => 'Stock Out',
            'adjustment' => 'Adjustment',
            'waste' => 'Waste',
            'return' => 'Return'
        ][$this->transaction_type] ?? $this->transaction_type;
    }

    /**
     * Get formatted quantity with unit.
     */
    public function getFormattedQuantityAttribute()
    {
        return number_format($this->quantity, 2) . ' ' . ($this->inventoryItem->unit ?? '');
    }

    /**
     * Get formatted total cost.
     */
    public function getFormattedTotalCostAttribute()
    {
        return number_format($this->total_cost, 2);
    }

    /**
     * Create stock in transaction.
     */
    public static function createStockIn($inventoryItemId, $quantity, $unitCost, $reference = null, $notes = null)
    {
        return self::create([
            'inventory_item_id' => $inventoryItemId,
            'transaction_type' => 'stock_in',
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $quantity * $unitCost,
            'reference' => $reference,
            'transaction_date' => now(),
            'notes' => $notes,
            'created_by' => auth()->id() ?? 1
        ]);
    }

    /**
     * Create stock out transaction.
     */
    public static function createStockOut($inventoryItemId, $quantity, $reference = null, $notes = null)
    {
        $inventoryItem = InventoryItem::find($inventoryItemId);

        return self::create([
            'inventory_item_id' => $inventoryItemId,
            'transaction_type' => 'stock_out',
            'quantity' => $quantity,
            'unit_cost' => $inventoryItem->unit_cost,
            'total_cost' => $quantity * $inventoryItem->unit_cost,
            'reference' => $reference,
            'transaction_date' => now(),
            'notes' => $notes,
            'created_by' => auth()->id() ?? 1
        ]);
    }

    /**
     * Create adjustment transaction.
     */
    public static function createAdjustment($inventoryItemId, $quantity, $unitCost, $notes = null)
    {
        return self::create([
            'inventory_item_id' => $inventoryItemId,
            'transaction_type' => 'adjustment',
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => abs($quantity * $unitCost),
            'transaction_date' => now(),
            'notes' => $notes,
            'created_by' => auth()->id() ?? 1
        ]);
    }

    /**
     * Create waste transaction.
     */
    public static function createWaste($inventoryItemId, $quantity, $notes = null)
    {
        $inventoryItem = InventoryItem::find($inventoryItemId);

        return self::create([
            'inventory_item_id' => $inventoryItemId,
            'transaction_type' => 'waste',
            'quantity' => $quantity,
            'unit_cost' => $inventoryItem->unit_cost,
            'total_cost' => $quantity * $inventoryItem->unit_cost,
            'transaction_date' => now(),
            'notes' => $notes,
            'created_by' => auth()->id() ?? 1
        ]);
    }

    /**
     * Get transaction summary for a period.
     */
    public static function getTransactionSummary($messId, $startDate, $endDate)
    {
        return self::whereHas('inventoryItem', function ($query) use ($messId) {
            $query->where('mess_id', $messId);
        })
            ->dateRange($startDate, $endDate)
            ->selectRaw('
            transaction_type,
            COUNT(*) as transaction_count,
            SUM(quantity) as total_quantity,
            SUM(total_cost) as total_cost,
            AVG(unit_cost) as avg_unit_cost
        ')
            ->groupBy('transaction_type')
            ->get();
    }

    /**
     * Get daily transaction summary.
     */
    public static function getDailySummary($messId, $days = 30)
    {
        $startDate = now()->subDays($days)->startOfDay();

        return self::whereHas('inventoryItem', function ($query) use ($messId) {
            $query->where('mess_id', $messId);
        })
            ->where('created_at', '>=', $startDate)
            ->selectRaw('
            DATE(created_at) as date,
            transaction_type,
            COUNT(*) as transaction_count,
            SUM(quantity) as total_quantity,
            SUM(total_cost) as total_cost
        ')
            ->groupBy('date', 'transaction_type')
            ->orderBy('date')
            ->get()
            ->groupBy('date');
    }

    /**
     * Get top items by transaction volume.
     */
    public static function getTopItemsByVolume($messId, $startDate, $endDate, $limit = 10)
    {
        return self::whereHas('inventoryItem', function ($query) use ($messId) {
            $query->where('mess_id', $messId);
        })
            ->dateRange($startDate, $endDate)
            ->selectRaw('
            inventory_item_id,
            transaction_type,
            SUM(quantity) as total_quantity,
            SUM(total_cost) as total_cost,
            COUNT(*) as transaction_count
        ')
            ->with('inventoryItem:id,name,unit')
            ->groupBy('inventory_item_id', 'transaction_type')
            ->orderByDesc('total_quantity')
            ->limit($limit)
            ->get()
            ->groupBy('transaction_type');
    }

    /**
     * Get transaction trends.
     */
    public static function getTransactionTrends($messId, $months = 6)
    {
        $startDate = now()->subMonths($months)->startOfMonth();

        return self::whereHas('inventoryItem', function ($query) use ($messId) {
            $query->where('mess_id', $messId);
        })
            ->where('created_at', '>=', $startDate)
            ->selectRaw('
            DATE_FORMAT(created_at, "%Y-%m") as month,
            transaction_type,
            COUNT(*) as transaction_count,
            SUM(quantity) as total_quantity,
            SUM(total_cost) as total_cost
        ')
            ->groupBy('month', 'transaction_type')
            ->orderBy('month')
            ->get()
            ->groupBy('month');
    }

    /**
     * Get waste analysis.
     */
    public static function getWasteAnalysis($messId, $startDate, $endDate)
    {
        return self::whereHas('inventoryItem', function ($query) use ($messId) {
            $query->where('mess_id', $messId);
        })
            ->where('transaction_type', 'waste')
            ->dateRange($startDate, $endDate)
            ->selectRaw('
            inventory_item_id,
            SUM(quantity) as total_waste,
            SUM(total_cost) as total_waste_cost,
            COUNT(*) as waste_incidents
        ')
            ->with('inventoryItem:id,name,unit,category')
            ->groupBy('inventory_item_id')
            ->orderByDesc('total_waste_cost')
            ->get();
    }

    /**
     * Get cost analysis by category.
     */
    public static function getCostAnalysisByCategory($messId, $startDate, $endDate)
    {
        return self::whereHas('inventoryItem', function ($query) use ($messId) {
            $query->where('mess_id', $messId);
        })
            ->dateRange($startDate, $endDate)
            ->join('inventory_items', 'inventory_transactions.inventory_item_id', '=', 'inventory_items.id')
            ->selectRaw('
            inventory_items.category,
            transaction_type,
            COUNT(*) as transaction_count,
            SUM(inventory_transactions.quantity) as total_quantity,
            SUM(inventory_transactions.total_cost) as total_cost,
            AVG(inventory_transactions.unit_cost) as avg_unit_cost
        ')
            ->groupBy('inventory_items.category', 'transaction_type')
            ->orderByDesc('total_cost')
            ->get()
            ->groupBy('category');
    }

    /**
     * Get transaction statistics.
     */
    public static function getTransactionStatistics($messId, $startDate, $endDate)
    {
        $transactions = self::whereHas('inventoryItem', function ($query) use ($messId) {
            $query->where('mess_id', $messId);
        })
            ->dateRange($startDate, $endDate)
            ->get();

        return [
            'total_transactions' => $transactions->count(),
            'total_stock_in' => $transactions->where('transaction_type', 'stock_in')->sum('quantity'),
            'total_stock_out' => $transactions->where('transaction_type', 'stock_out')->sum('quantity'),
            'total_waste' => $transactions->where('transaction_type', 'waste')->sum('quantity'),
            'total_cost_stock_in' => $transactions->where('transaction_type', 'stock_in')->sum('total_cost'),
            'total_cost_stock_out' => $transactions->where('transaction_type', 'stock_out')->sum('total_cost'),
            'total_waste_cost' => $transactions->where('transaction_type', 'waste')->sum('total_cost'),
            'avg_transaction_value' => $transactions->avg('total_cost'),
            'most_active_day' => $transactions->groupBy(function ($item) {
                return $item->created_at->format('Y-m-d');
            })->map->count()->sortDesc()->keys()->first(),
            'transaction_types' => $transactions->groupBy('transaction_type')->map->count()
        ];
    }

    /**
     * Search transactions.
     */
    public static function search($messId, $query, $filters = [])
    {
        $transactions = self::whereHas('inventoryItem', function ($query) use ($messId) {
            $query->where('mess_id', $messId);
        })->with(['inventoryItem', 'createdBy']);

        // Search query
        if ($query) {
            $transactions->where(function ($q) use ($query) {
                $q->where('reference', 'LIKE', "%{$query}%")
                    ->orWhere('notes', 'LIKE', "%{$query}%")
                    ->orWhereHas('inventoryItem', function ($subQuery) use ($query) {
                        $subQuery->where('name', 'LIKE', "%{$query}%")
                            ->orWhere('category', 'LIKE', "%{$query}%");
                    });
            });
        }

        // Apply filters
        if (isset($filters['transaction_type'])) {
            $transactions->where('transaction_type', $filters['transaction_type']);
        }

        if (isset($filters['date_from'])) {
            $transactions->whereDate('transaction_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $transactions->whereDate('transaction_date', '<=', $filters['date_to']);
        }

        if (isset($filters['reference_type'])) {
            $transactions->where('reference_type', $filters['reference_type']);
        }

        if (isset($filters['created_by'])) {
            $transactions->where('created_by', $filters['created_by']);
        }

        return $transactions;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class InventoryItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'mess_id',
        'name',
        'description',
        'category',
        'unit',
        'current_stock',
        'minimum_stock',
        'maximum_stock',
        'unit_cost',
        'total_value',
        'supplier',
        'supplier_contact',
        'last_purchase_date',
        'expiry_date',
        'storage_location',
        'reorder_point',
        'reorder_quantity',
        'is_active',
        'is_perishable',
        'notes',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'current_stock' => 'decimal:4',
        'minimum_stock' => 'decimal:4',
        'maximum_stock' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_value' => 'decimal:4',
        'last_purchase_date' => 'date',
        'expiry_date' => 'date',
        'is_active' => 'boolean',
        'is_perishable' => 'boolean',
        'reorder_point' => 'decimal:4',
        'reorder_quantity' => 'decimal:4'
    ];

    protected $appends = [
        'stock_status',
        'is_low_stock',
        'is_overstock',
        'days_until_expiry',
        'is_expired'
    ];

    /**
     * Get the mess that owns the inventory item.
     */
    public function mess()
    {
        return $this->belongsTo(Mess::class);
    }

    /**
     * Get the user who created the inventory item.
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the inventory item.
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the inventory transactions for the item.
     */
    public function transactions()
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    /**
     * Get the stock ins for the item.
     */
    public function stockIns()
    {
        return $this->hasMany(InventoryTransaction::class)->where('transaction_type', 'stock_in');
    }

    /**
     * Get the stock outs for the item.
     */
    public function stockOuts()
    {
        return $this->hasMany(InventoryTransaction::class)->where('transaction_type', 'stock_out');
    }

    /**
     * Get the bazar items that use this inventory item.
     */
    public function bazarItems()
    {
        return $this->hasMany(BazarItem::class);
    }

    /**
     * Scope a query to only include active items.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include low stock items.
     */
    public function scopeLowStock($query)
    {
        return $query->whereRaw('current_stock <= minimum_stock');
    }

    /**
     * Scope a query to only include overstock items.
     */
    public function scopeOverstock($query)
    {
        return $query->whereRaw('current_stock >= maximum_stock');
    }

    /**
     * Scope a query to only include perishable items.
     */
    public function scopePerishable($query)
    {
        return $query->where('is_perishable', true);
    }

    /**
     * Scope a query to only include expired items.
     */
    public function scopeExpired($query)
    {
        return $query->where('expiry_date', '<', now());
    }

    /**
     * Scope a query to only include items nearing expiry.
     */
    public function scopeNearingExpiry($query, $days = 7)
    {
        return $query->where('expiry_date', '<=', now()->addDays($days))
            ->where('expiry_date', '>', now());
    }

    /**
     * Scope a query to filter by category.
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Get stock status attribute.
     */
    public function getStockStatusAttribute()
    {
        if ($this->current_stock <= 0) {
            return 'out_of_stock';
        } elseif ($this->current_stock <= $this->minimum_stock) {
            return 'low_stock';
        } elseif ($this->current_stock >= $this->maximum_stock) {
            return 'overstock';
        } else {
            return 'normal';
        }
    }

    /**
     * Check if item is low stock.
     */
    public function getIsLowStockAttribute()
    {
        return $this->current_stock <= $this->minimum_stock;
    }

    /**
     * Check if item is overstock.
     */
    public function getIsOverstockAttribute()
    {
        return $this->current_stock >= $this->maximum_stock;
    }

    /**
     * Get days until expiry.
     */
    public function getDaysUntilExpiryAttribute()
    {
        if (!$this->expiry_date) {
            return null;
        }

        return now()->diffInDays($this->expiry_date, false);
    }

    /**
     * Check if item is expired.
     */
    public function getIsExpiredAttribute()
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    /**
     * Update current stock and total value.
     */
    public function updateStock($quantity, $unitCost = null)
    {
        $this->current_stock = $quantity;

        if ($unitCost) {
            $this->unit_cost = $unitCost;
        }

        $this->total_value = $this->current_stock * $this->unit_cost;
        $this->save();
    }

    /**
     * Add stock (stock in).
     */
    public function addStock($quantity, $unitCost = null, $reference = null, $notes = null)
    {
        DB::transaction(function () use ($quantity, $unitCost, $reference, $notes) {
            $oldStock = $this->current_stock;
            $newStock = $oldStock + $quantity;

            $this->updateStock($newStock, $unitCost);

            // Create transaction record
            $this->transactions()->create([
                'transaction_type' => 'stock_in',
                'quantity' => $quantity,
                'unit_cost' => $unitCost ?? $this->unit_cost,
                'total_cost' => $quantity * ($unitCost ?? $this->unit_cost),
                'reference' => $reference,
                'notes' => $notes,
                'created_by' => auth()->id() ?? 1
            ]);
        });
    }

    /**
     * Remove stock (stock out).
     */
    public function removeStock($quantity, $reference = null, $notes = null)
    {
        DB::transaction(function () use ($quantity, $reference, $notes) {
            $oldStock = $this->current_stock;
            $newStock = max(0, $oldStock - $quantity);

            $this->updateStock($newStock);

            // Create transaction record
            $this->transactions()->create([
                'transaction_type' => 'stock_out',
                'quantity' => $quantity,
                'unit_cost' => $this->unit_cost,
                'total_cost' => $quantity * $this->unit_cost,
                'reference' => $reference,
                'notes' => $notes,
                'created_by' => auth()->id() ?? 1
            ]);
        });
    }

    /**
     * Get stock summary for the item.
     */
    public function getStockSummary()
    {
        $totalStockIn = $this->stockIns()->sum('quantity');
        $totalStockOut = $this->stockOuts()->sum('quantity');
        $lastTransaction = $this->transactions()->latest()->first();

        return [
            'current_stock' => $this->current_stock,
            'total_stock_in' => $totalStockIn,
            'total_stock_out' => $totalStockOut,
            'stock_status' => $this->stock_status,
            'is_low_stock' => $this->is_low_stock,
            'is_overstock' => $this->is_overstock,
            'total_value' => $this->total_value,
            'unit_cost' => $this->unit_cost,
            'last_transaction' => $lastTransaction,
            'days_until_expiry' => $this->days_until_expiry,
            'is_expired' => $this->is_expired
        ];
    }

    /**
     * Get monthly stock movement.
     */
    public function getMonthlyStockMovement($months = 6)
    {
        $startDate = now()->subMonths($months)->startOfMonth();

        return $this->transactions()
            ->where('created_at', '>=', $startDate)
            ->selectRaw('
                DATE_FORMAT(created_at, "%Y-%m") as month,
                transaction_type,
                SUM(quantity) as total_quantity,
                SUM(total_cost) as total_cost,
                COUNT(*) as transaction_count
            ')
            ->groupBy('month', 'transaction_type')
            ->orderBy('month')
            ->get()
            ->groupBy('month');
    }

    /**
     * Get top consuming periods.
     */
    public function getTopConsumingPeriods($limit = 5)
    {
        return $this->stockOuts()
            ->selectRaw('
                DATE(created_at) as date,
                SUM(quantity) as total_consumed,
                COUNT(*) as transaction_count
            ')
            ->groupBy('date')
            ->orderByDesc('total_consumed')
            ->limit($limit)
            ->get();
    }

    /**
     * Check if reorder is needed.
     */
    public function needsReorder()
    {
        return $this->current_stock <= $this->reorder_point;
    }

    /**
     * Get suggested reorder quantity.
     */
    public function getSuggestedReorderQuantity()
    {
        if (!$this->needsReorder()) {
            return 0;
        }

        return $this->reorder_quantity ?? ($this->maximum_stock - $this->current_stock);
    }

    /**
     * Get inventory alerts.
     */
    public function getAlerts()
    {
        $alerts = [];

        if ($this->is_expired) {
            $alerts[] = [
                'type' => 'danger',
                'message' => "Item {$this->name} has expired on {$this->expiry_date->format('Y-m-d')}",
                'action' => 'dispose'
            ];
        }

        if ($this->days_until_expiry !== null && $this->days_until_expiry <= 7) {
            $alerts[] = [
                'type' => 'warning',
                'message' => "Item {$this->name} will expire in {$this->days_until_expiry} days",
                'action' => 'use_soon'
            ];
        }

        if ($this->is_low_stock) {
            $alerts[] = [
                'type' => 'warning',
                'message' => "Item {$this->name} is running low on stock ({$this->current_stock} {$this->unit})",
                'action' => 'reorder'
            ];
        }

        if ($this->is_overstock) {
            $alerts[] = [
                'type' => 'info',
                'message' => "Item {$this->name} is overstocked ({$this->current_stock} {$this->unit})",
                'action' => 'monitor'
            ];
        }

        return $alerts;
    }

    /**
     * Get inventory value by category.
     */
    public static function getValueByCategory($messId)
    {
        return self::where('mess_id', $messId)
            ->active()
            ->selectRaw('
                category,
                COUNT(*) as item_count,
                SUM(current_stock) as total_stock,
                SUM(total_value) as total_value,
                AVG(unit_cost) as avg_unit_cost
            ')
            ->groupBy('category')
            ->orderByDesc('total_value')
            ->get();
    }

    /**
     * Get low stock items for mess.
     */
    public static function getLowStockItems($messId)
    {
        return self::where('mess_id', $messId)
            ->active()
            ->lowStock()
            ->with(['transactions' => function ($query) {
                $query->latest()->limit(5);
            }])
            ->get();
    }

    /**
     * Get expired items for mess.
     */
    public static function getExpiredItems($messId)
    {
        return self::where('mess_id', $messId)
            ->expired()
            ->with(['transactions' => function ($query) {
                $query->latest()->limit(5);
            }])
            ->get();
    }

    /**
     * Get items nearing expiry for mess.
     */
    public static function getNearingExpiryItems($messId, $days = 7)
    {
        return self::where('mess_id', $messId)
            ->nearingExpiry($days)
            ->with(['transactions' => function ($query) {
                $query->latest()->limit(5);
            }])
            ->get();
    }

    /**
     * Search inventory items.
     */
    public static function search($messId, $query, $filters = [])
    {
        $items = self::where('mess_id', $messId)->active();

        // Search query
        if ($query) {
            $items->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                    ->orWhere('description', 'LIKE', "%{$query}%")
                    ->orWhere('category', 'LIKE', "%{$query}%")
                    ->orWhere('supplier', 'LIKE', "%{$query}%");
            });
        }

        // Apply filters
        if (isset($filters['category'])) {
            $items->byCategory($filters['category']);
        }

        if (isset($filters['stock_status'])) {
            switch ($filters['stock_status']) {
                case 'low_stock':
                    $items->lowStock();
                    break;
                case 'overstock':
                    $items->overstock();
                    break;
                case 'out_of_stock':
                    $items->where('current_stock', '<=', 0);
                    break;
            }
        }

        if (isset($filters['is_perishable'])) {
            $items->where('is_perishable', $filters['is_perishable']);
        }

        if (isset($filters['expiry_status'])) {
            switch ($filters['expiry_status']) {
                case 'expired':
                    $items->expired();
                    break;
                case 'nearing_expiry':
                    $items->nearingExpiry();
                    break;
            }
        }

        return $items->with(['createdBy', 'updatedBy']);
    }
}

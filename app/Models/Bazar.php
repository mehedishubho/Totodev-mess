<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;

class Bazar extends Model
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'mess_id',
        'bazar_person_id',
        'bazar_date',
        'item_list',
        'total_cost',
        'receipt_image',
        'notes',
        'created_by',
        'approved_at',
        'approved_by'
    ];

    protected $casts = [
        'bazar_date' => 'date',
        'item_list' => 'array',
        'total_cost' => 'decimal:2',
        'approved_at' => 'datetime',
        'notes' => 'string'
    ];

    /**
     * Get the mess that owns the bazar.
     */
    public function mess()
    {
        return $this->belongsTo(Mess::class);
    }

    /**
     * Get the bazar person (user) who performed the bazar.
     */
    public function bazarPerson()
    {
        return $this->belongsTo(User::class, 'bazar_person_id');
    }

    /**
     * Get the user who created the bazar.
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who approved the bazar.
     */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Scope a query to only include bazars for a specific date.
     */
    public function scopeForDate($query, $date)
    {
        return $query->whereDate('bazar_date', $date);
    }

    /**
     * Scope a query to only include bazars for a specific month.
     */
    public function scopeForMonth($query, $year, $month)
    {
        return $query->whereYear('bazar_date', $year)
            ->whereMonth('bazar_date', $month);
    }

    /**
     * Scope a query to only include bazars for today.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('bazar_date', today());
    }

    /**
     * Scope a query to only include upcoming bazars.
     */
    public function scopeUpcoming($query)
    {
        return $query->whereDate('bazar_date', '>=', today());
    }

    /**
     * Scope a query to only include past bazars.
     */
    public function scopePast($query)
    {
        return $query->whereDate('bazar_date', '<', today());
    }

    /**
     * Scope a query to only include approved bazars.
     */
    public function scopeApproved($query)
    {
        return $query->whereNotNull('approved_at');
    }

    /**
     * Scope a query to only include pending bazars.
     */
    public function scopePending($query)
    {
        return $query->whereNull('approved_at');
    }

    /**
     * Scope a query to only include bazars by a specific person.
     */
    public function scopeByPerson($query, $userId)
    {
        return $query->where('bazar_person_id', $userId);
    }

    /**
     * Check if bazar is approved.
     */
    public function isApproved()
    {
        return !is_null($this->approved_at);
    }

    /**
     * Check if bazar is pending.
     */
    public function isPending()
    {
        return is_null($this->approved_at);
    }

    /**
     * Approve the bazar.
     */
    public function approve($approvedBy = null)
    {
        $this->update([
            'approved_at' => now(),
            'approved_by' => $approvedBy ?? auth()->id()
        ]);

        return $this;
    }

    /**
     * Get formatted item list.
     */
    public function getFormattedItemListAttribute()
    {
        if (!$this->item_list) {
            return [];
        }

        return collect($this->item_list)->map(function ($item) {
            return [
                'name' => $item['name'] ?? 'Unknown',
                'quantity' => $item['quantity'] ?? 1,
                'unit' => $item['unit'] ?? 'pcs',
                'price' => $item['price'] ?? 0,
                'total_cost' => ($item['quantity'] ?? 1) * ($item['price'] ?? 0)
            ];
        });
    }

    /**
     * Get calculated total cost from item list.
     */
    public function getCalculatedTotalCostAttribute()
    {
        if (!$this->item_list) {
            return 0;
        }

        return collect($this->item_list)->sum(function ($item) {
            return ($item['quantity'] ?? 1) * ($item['price'] ?? 0);
        });
    }

    /**
     * Get receipt URL.
     */
    public function getReceiptUrlAttribute()
    {
        if (!$this->receipt_image) {
            return null;
        }

        return asset('storage/' . $this->receipt_image);
    }

    /**
     * Get formatted bazar date.
     */
    public function getFormattedBazarDateAttribute()
    {
        return $this->bazar_date->format('M d, Y');
    }

    /**
     * Get bazar summary for a specific month.
     */
    public static function getBazarSummary($messId, $year, $month)
    {
        return self::where('mess_id', $messId)
            ->forMonth($year, $month)
            ->with(['bazarPerson', 'createdBy', 'approvedBy'])
            ->orderBy('bazar_date')
            ->get()
            ->groupBy(function ($item) {
                return $item->bazar_date->format('Y-m-d');
            });
    }

    /**
     * Get total bazar cost for a specific month.
     */
    public static function getTotalBazarCost($messId, $year, $month)
    {
        return self::where('mess_id', $messId)
            ->forMonth($year, $month)
            ->sum('total_cost');
    }

    /**
     * Get bazar statistics for a user in a specific month.
     */
    public static function getUserBazarStatistics($messId, $userId, $year, $month)
    {
        $bazars = self::where('mess_id', $messId)
            ->where('bazar_person_id', $userId)
            ->forMonth($year, $month)
            ->get();

        return [
            'total_bazars' => $bazars->count(),
            'total_cost' => $bazars->sum('total_cost'),
            'average_cost_per_bazar' => $bazars->count() > 0 ? $bazars->sum('total_cost') / $bazars->count() : 0,
            'pending_bazars' => $bazars->whereNull('approved_at')->count(),
            'approved_bazars' => $bazars->whereNotNull('approved_at')->count(),
        ];
    }

    /**
     * Get mess bazar statistics for a specific month.
     */
    public static function getMessBazarStatistics($messId, $year, $month)
    {
        $bazars = self::where('mess_id', $messId)
            ->forMonth($year, $month)
            ->with(['bazarPerson'])
            ->get();

        return [
            'total_bazars' => $bazars->count(),
            'total_cost' => $bazars->sum('total_cost'),
            'average_cost_per_bazar' => $bazars->count() > 0 ? $bazars->sum('total_cost') / $bazars->count() : 0,
            'pending_bazars' => $bazars->whereNull('approved_at')->count(),
            'approved_bazars' => $bazars->whereNotNull('approved_at')->count(),
            'unique_bazar_persons' => $bazars->pluck('bazar_person_id')->unique()->count(),
            'cost_by_person' => $bazars->groupBy('bazar_person_id')->map(function ($personBazars, $personId) {
                $person = $personBazars->first()->bazarPerson;
                return [
                    'person' => [
                        'id' => $personId,
                        'name' => $person->name,
                        'email' => $person->email,
                    ],
                    'total_bazars' => $personBazars->count(),
                    'total_cost' => $personBazars->sum('total_cost'),
                    'average_cost' => $personBazars->sum('total_cost') / $personBazars->count(),
                ];
            })->values(),
        ];
    }

    /**
     * Get upcoming bazars for a mess.
     */
    public static function getUpcomingBazars($messId, $limit = 5)
    {
        return self::where('mess_id', $messId)
            ->upcoming()
            ->with(['bazarPerson'])
            ->orderBy('bazar_date')
            ->limit($limit)
            ->get();
    }

    /**
     * Get recent bazars for a mess.
     */
    public static function getRecentBazars($messId, $limit = 10)
    {
        return self::where('mess_id', $messId)
            ->past()
            ->with(['bazarPerson', 'approvedBy'])
            ->orderBy('bazar_date', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get bazar cost trend for last 6 months.
     */
    public static function getBazarCostTrend($messId)
    {
        $months = collect();

        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $totalCost = self::where('mess_id', $messId)
                ->whereMonth('bazar_date', $month->month)
                ->whereYear('bazar_date', $month->year)
                ->sum('total_cost');

            $months->push([
                'month' => $month->format('M Y'),
                'cost' => $totalCost,
                'bazars_count' => self::where('mess_id', $messId)
                    ->whereMonth('bazar_date', $month->month)
                    ->whereYear('bazar_date', $month->year)
                    ->count()
            ]);
        }

        return $months;
    }

    /**
     * Get next scheduled bazar person.
     */
    public static function getNextBazarPerson($messId)
    {
        $nextBazar = self::where('mess_id', $messId)
            ->upcoming()
            ->orderBy('bazar_date')
            ->first();

        if ($nextBazar) {
            return $nextBazar->bazarPerson;
        }

        return null;
    }
}

<?php

namespace App\Services;

use App\Models\Bazar;
use App\Models\Mess;
use App\Models\MessMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class BazarService
{
    /**
     * Create bazar with validation and receipt handling.
     */
    public function createBazar(array $data, $createdBy)
    {
        try {
            DB::beginTransaction();

            // Validate total cost matches calculated cost
            $calculatedCost = collect($data['item_list'])->sum(function ($item) {
                return ($item['quantity'] ?? 1) * ($item['price'] ?? 0);
            });

            if (abs($calculatedCost - $data['total_cost']) > 0.01) {
                throw new \Exception('Total cost does not match calculated cost from items');
            }

            // Check if bazar person is a member of mess
            $member = MessMember::where('mess_id', $data['mess_id'])
                ->where('user_id', $data['bazar_person_id'])
                ->where('status', 'approved')
                ->first();

            if (!$member) {
                throw new \Exception('Bazar person is not an active member of this mess');
            }

            // Handle receipt upload
            $receiptPath = null;
            if (isset($data['receipt_image']) && $data['receipt_image'] instanceof \Illuminate\Http\UploadedFile) {
                $receiptPath = $data['receipt_image']->store('bazar_receipts', 'public');
            }

            $bazar = Bazar::create([
                'mess_id' => $data['mess_id'],
                'bazar_person_id' => $data['bazar_person_id'],
                'bazar_date' => $data['bazar_date'],
                'item_list' => $data['item_list'],
                'total_cost' => $data['total_cost'],
                'receipt_image' => $receiptPath,
                'notes' => $data['notes'] ?? null,
                'created_by' => $createdBy
            ]);

            DB::commit();

            return $bazar;
        } catch (\Exception $e) {
            DB::rollBack();

            // Remove uploaded receipt if transaction failed
            if (isset($receiptPath)) {
                Storage::disk('public')->delete($receiptPath);
            }

            throw new \Exception('Failed to create bazar: ' . $e->getMessage());
        }
    }

    /**
     * Update bazar with validation.
     */
    public function updateBazar(Bazar $bazar, array $data, $updatedBy)
    {
        try {
            DB::beginTransaction();

            // Don't allow updating approved bazars (only managers can)
            if ($bazar->isApproved() && !auth()->user()->hasRole(['super_admin', 'admin'])) {
                throw new \Exception('Cannot update approved bazar');
            }

            // Validate total cost if item list is provided
            if (isset($data['item_list']) && isset($data['total_cost'])) {
                $calculatedCost = collect($data['item_list'])->sum(function ($item) {
                    return ($item['quantity'] ?? 1) * ($item['price'] ?? 0);
                });

                if (abs($calculatedCost - $data['total_cost']) > 0.01) {
                    throw new \Exception('Total cost does not match calculated cost from items');
                }
            }

            $bazar->update($data);

            DB::commit();

            return $bazar->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('Failed to update bazar: ' . $e->getMessage());
        }
    }

    /**
     * Delete bazar with cleanup.
     */
    public function deleteBazar(Bazar $bazar, $deletedBy)
    {
        try {
            DB::beginTransaction();

            // Don't allow deleting approved bazars (only managers can)
            if ($bazar->isApproved() && !auth()->user()->hasRole(['super_admin', 'admin'])) {
                throw new \Exception('Cannot delete approved bazar');
            }

            // Delete receipt image if exists
            if ($bazar->receipt_image) {
                Storage::disk('public')->delete($bazar->receipt_image);
            }

            $bazar->delete();

            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('Failed to delete bazar: ' . $e->getMessage());
        }
    }

    /**
     * Upload receipt for bazar.
     */
    public function uploadReceipt(Bazar $bazar, $receiptImage)
    {
        try {
            DB::beginTransaction();

            // Delete old receipt if exists
            if ($bazar->receipt_image) {
                Storage::disk('public')->delete($bazar->receipt_image);
            }

            // Upload new receipt
            $receiptPath = $receiptImage->store('bazar_receipts', 'public');

            $bazar->update(['receipt_image' => $receiptPath]);

            DB::commit();

            return [
                'receipt_url' => $bazar->receipt_url
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('Failed to upload receipt: ' . $e->getMessage());
        }
    }

    /**
     * Approve bazar.
     */
    public function approveBazar(Bazar $bazar, $approvedBy)
    {
        try {
            DB::beginTransaction();

            if ($bazar->isApproved()) {
                throw new \Exception('Bazar is already approved');
            }

            $bazar->approve($approvedBy);

            DB::commit();

            return $bazar->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('Failed to approve bazar: ' . $e->getMessage());
        }
    }

    /**
     * Generate monthly bazar report.
     */
    public function generateMonthlyReport($messId, $year, $month, $groupBy = 'date')
    {
        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $bazars = Bazar::where('mess_id', $messId)
            ->whereBetween('bazar_date', [$startDate, $endDate])
            ->with(['bazarPerson', 'createdBy', 'approvedBy'])
            ->orderBy('bazar_date')
            ->get();

        $mess = Mess::findOrFail($messId);

        $data = [];

        if ($groupBy === 'date') {
            $data = $bazars->groupBy('bazar_date')->map(function ($dateBazars, $date) {
                return [
                    'date' => $date,
                    'formatted_date' => Carbon::parse($date)->format('M d, Y'),
                    'total_cost' => $dateBazars->sum('total_cost'),
                    'bazars_count' => $dateBazars->count(),
                    'approved_bazars' => $dateBazars->whereNotNull('approved_at')->count(),
                    'pending_bazars' => $dateBazars->whereNull('approved_at')->count(),
                    'bazars' => $dateBazars
                ];
            })->values();
        } elseif ($groupBy === 'person') {
            $data = $bazars->groupBy('bazar_person_id')->map(function ($personBazars, $personId) {
                $person = $personBazars->first()->bazarPerson;
                return [
                    'person' => [
                        'id' => $personId,
                        'name' => $person->name,
                        'email' => $person->email
                    ],
                    'total_cost' => $personBazars->sum('total_cost'),
                    'bazars_count' => $personBazars->count(),
                    'average_cost' => $personBazars->sum('total_cost') / $personBazars->count(),
                    'approved_bazars' => $personBazars->whereNotNull('approved_at')->count(),
                    'pending_bazars' => $personBazars->whereNull('approved_at')->count(),
                    'bazars' => $personBazars
                ];
            })->values();
        } else {
            $data = [
                'total_cost' => $bazars->sum('total_cost'),
                'bazars_count' => $bazars->count(),
                'average_cost' => $bazars->count() > 0 ? $bazars->sum('total_cost') / $bazars->count() : 0,
                'approved_bazars' => $bazars->whereNotNull('approved_at')->count(),
                'pending_bazars' => $bazars->whereNull('approved_at')->count(),
                'unique_bazar_persons' => $bazars->pluck('bazar_person_id')->unique()->count(),
                'bazars' => $bazars
            ];
        }

        return [
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'month_name' => $startDate->format('F Y'),
            ],
            'mess' => [
                'id' => $mess->id,
                'name' => $mess->name,
            ],
            'summary' => [
                'total_bazars' => $bazars->count(),
                'total_cost' => $bazars->sum('total_cost'),
                'average_cost_per_bazar' => $bazars->count() > 0 ? $bazars->sum('total_cost') / $bazars->count() : 0,
                'approved_bazars' => $bazars->whereNotNull('approved_at')->count(),
                'pending_bazars' => $bazars->whereNull('approved_at')->count(),
                'unique_bazar_persons' => $bazars->pluck('bazar_person_id')->unique()->count(),
            ],
            'data' => $data
        ];
    }

    /**
     * Get upcoming bazars for a mess.
     */
    public function getUpcomingBazars($messId, $limit = 5)
    {
        return Bazar::where('mess_id', $messId)
            ->upcoming()
            ->with(['bazarPerson'])
            ->orderBy('bazar_date')
            ->limit($limit)
            ->get();
    }

    /**
     * Get recent bazars for a mess.
     */
    public function getRecentBazars($messId, $limit = 10)
    {
        return Bazar::where('mess_id', $messId)
            ->past()
            ->with(['bazarPerson', 'approvedBy'])
            ->orderBy('bazar_date', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get next scheduled bazar person.
     */
    public function getNextBazarPerson($messId)
    {
        $nextBazar = Bazar::where('mess_id', $messId)
            ->upcoming()
            ->orderBy('bazar_date')
            ->first();

        if ($nextBazar) {
            return $nextBazar->bazarPerson;
        }

        // If no upcoming bazar, get the last bazar person for rotation
        $lastBazar = Bazar::where('mess_id', $messId)
            ->orderBy('bazar_date', 'desc')
            ->first();

        if ($lastBazar) {
            $mess = Mess::findOrFail($messId);
            return $mess->getNextBazarPerson();
        }

        return null;
    }

    /**
     * Get bazar cost trend for last 6 months.
     */
    public function getBazarCostTrend($messId)
    {
        return Bazar::getBazarCostTrend($messId);
    }

    /**
     * Get bazar statistics for user in a month.
     */
    public function getUserBazarStatistics($messId, $userId, $year, $month)
    {
        return Bazar::getUserBazarStatistics($messId, $userId, $year, $month);
    }

    /**
     * Get bazar statistics for mess in a month.
     */
    public function getMessBazarStatistics($messId, $year, $month)
    {
        return Bazar::getMessBazarStatistics($messId, $year, $month);
    }

    /**
     * Auto-assign next bazar person based on rotation.
     */
    public function autoAssignNextBazarPerson($messId)
    {
        $mess = Mess::findOrFail($messId);

        if (!$mess->auto_bazar_rotation) {
            return null;
        }

        $nextPerson = $mess->getNextBazarPerson();

        if (!$nextPerson) {
            return null;
        }

        // Find the next available date for bazar
        $lastBazarDate = Bazar::where('mess_id', $messId)
            ->orderBy('bazar_date', 'desc')
            ->value('bazar_date');

        $nextBazarDate = $lastBazarDate ?
            Carbon::parse($lastBazarDate)->addDay() :
            Carbon::tomorrow();

        return [
            'person' => $nextPerson,
            'suggested_date' => $nextBazarDate->format('Y-m-d'),
            'rotation_note' => 'Auto-assigned based on rotation'
        ];
    }

    /**
     * Calculate bazar cost comparison between users.
     */
    public function calculateBazarCostComparison($messId, $year, $month)
    {
        $bazars = Bazar::where('mess_id', $messId)
            ->forMonth($year, $month)
            ->with(['bazarPerson'])
            ->get();

        return $bazars->groupBy('bazar_person_id')->map(function ($personBazars, $personId) {
            $person = $personBazars->first()->bazarPerson;
            return [
                'person' => [
                    'id' => $personId,
                    'name' => $person->name,
                    'email' => $person->email
                ],
                'total_bazars' => $personBazars->count(),
                'total_cost' => $personBazars->sum('total_cost'),
                'average_cost' => $personBazars->sum('total_cost') / $personBazars->count(),
                'highest_cost' => $personBazars->max('total_cost'),
                'lowest_cost' => $personBazars->min('total_cost'),
            ];
        })->sortBy('total_cost')->values();
    }

    /**
     * Get bazar performance metrics.
     */
    public function getBazarPerformanceMetrics($messId, $year, $month)
    {
        $bazars = Bazar::where('mess_id', $messId)
            ->forMonth($year, $month)
            ->get();

        $totalBazars = $bazars->count();
        $approvedBazars = $bazars->whereNotNull('approved_at')->count();
        $pendingBazars = $bazars->whereNull('approved_at')->count();
        $totalCost = $bazars->sum('total_cost');
        $averageCost = $totalBazars > 0 ? $totalCost / $totalBazars : 0;

        return [
            'total_bazars' => $totalBazars,
            'approval_rate' => $totalBazars > 0 ? ($approvedBazars / $totalBazars) * 100 : 0,
            'pending_rate' => $totalBazars > 0 ? ($pendingBazars / $totalBazars) * 100 : 0,
            'total_cost' => $totalCost,
            'average_cost_per_bazar' => $averageCost,
            'highest_cost' => $bazars->max('total_cost'),
            'lowest_cost' => $bazars->min('total_cost'),
            'cost_variance' => $totalBazars > 1 ? $bazars->stdDev('total_cost') : 0,
        ];
    }
}

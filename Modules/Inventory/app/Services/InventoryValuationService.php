<?php

namespace Modules\Inventory\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryMonthlyAverageCost;

class InventoryValuationService
{
    /**
     * Record a purchase receipt into the active monthly average cost pool and recalculate weighted average.
     */
    public function recordPurchaseReceipt(
        string $companyId,
        string $branchId,
        string $itemId,
        float $qty,
        int $costHalalas,
        ?string $yearMonth = null
    ): InventoryMonthlyAverageCost {
        $yearMonth = $yearMonth ?? now()->format('Y-m');

        return DB::transaction(function () use ($companyId, $branchId, $itemId, $qty, $costHalalas, $yearMonth) {
            $record = InventoryMonthlyAverageCost::firstOrCreate(
                [
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'item_id' => $itemId,
                    'year_month' => $yearMonth,
                ],
                [
                    'beginning_qty' => 0,
                    'beginning_cost_halalas' => 0,
                    'received_qty' => 0,
                    'received_cost_halalas' => 0,
                    'unit_cost_halalas' => 0,
                    'is_closed' => false,
                ]
            );

            $newReceivedQty = (float) $record->received_qty + $qty;
            $newReceivedCost = (int) $record->received_cost_halalas + $costHalalas;

            $totalQty = (float) $record->beginning_qty + $newReceivedQty;
            $totalCost = (int) $record->beginning_cost_halalas + $newReceivedCost;

            $unitCost = $totalQty > 0
                ? (int) round($totalCost / $totalQty)
                : (int) $record->unit_cost_halalas;

            $record->update([
                'received_qty' => $newReceivedQty,
                'received_cost_halalas' => $newReceivedCost,
                'unit_cost_halalas' => $unitCost,
            ]);

            return $record->fresh();
        });
    }

    /**
     * Retrieve the authoritative unit cost for an inventory item in a given period.
     * In zero-purchase months, strictly inherits from the latest preceding period with non-zero cost (INV-02).
     */
    public function getEffectiveUnitCost(
        string $companyId,
        string $branchId,
        string $itemId,
        ?string $yearMonth = null
    ): int {
        $yearMonth = $yearMonth ?? now()->format('Y-m');

        $record = InventoryMonthlyAverageCost::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('item_id', $itemId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($record && (int) $record->unit_cost_halalas > 0) {
            return (int) $record->unit_cost_halalas;
        }

        // Look up latest preceding period with non-zero unit cost
        $prior = InventoryMonthlyAverageCost::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('item_id', $itemId)
            ->where('year_month', '<', $yearMonth)
            ->where('unit_cost_halalas', '>', 0)
            ->orderByDesc('year_month')
            ->first();

        return $prior ? (int) $prior->unit_cost_halalas : 0;
    }

    /**
     * Close the current period and roll remaining inventory balance into the next period (INV-02).
     */
    public function closePeriodAndRollover(
        string $companyId,
        string $branchId,
        string $itemId,
        string $currentYearMonth,
        float $closingQty
    ): InventoryMonthlyAverageCost {
        return DB::transaction(function () use ($companyId, $branchId, $itemId, $currentYearMonth, $closingQty) {
            $current = InventoryMonthlyAverageCost::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('item_id', $itemId)
                ->where('year_month', $currentYearMonth)
                ->first();

            if ($current) {
                $current->update(['is_closed' => true]);
            }

            $currentUnitCost = $this->getEffectiveUnitCost($companyId, $branchId, $itemId, $currentYearMonth);
            $beginningCost = (int) round($closingQty * $currentUnitCost);

            $nextYearMonth = Carbon::createFromFormat('Y-m', $currentYearMonth)->addMonth()->format('Y-m');

            return InventoryMonthlyAverageCost::updateOrCreate(
                [
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'item_id' => $itemId,
                    'year_month' => $nextYearMonth,
                ],
                [
                    'beginning_qty' => $closingQty,
                    'beginning_cost_halalas' => $beginningCost,
                    'unit_cost_halalas' => $currentUnitCost,
                    'is_closed' => false,
                ]
            );
        });
    }
}

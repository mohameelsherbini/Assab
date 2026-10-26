<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryCountAdjustment;

class InventoryAdjustmentService
{
    public function __construct(
        protected InventoryValuationService $valuationService
    ) {}

    /**
     * Record an inventory count adjustment using compensating counter entries (INV-04).
     *
     * In accordance with ASSAB Business Rules v3.5, physical count discrepancies must
     * never delete historical count rows. Adjustments are append-only compensating entries
     * valuing the variance at the monthly weighted-average unit cost.
     */
    public function recordCountAdjustment(
        string $companyId,
        string $branchId,
        string $itemId,
        float $systemQuantity,
        float $countedQuantity,
        ?string $reason = null,
        ?string $adjustedByUserId = null,
        ?string $sessionId = null,
        ?string $yearMonth = null
    ): ?InventoryCountAdjustment {
        $yearMonth = $yearMonth ?? now()->format('Y-m');
        $varianceQty = round($countedQuantity - $systemQuantity, 4);

        if (abs($varianceQty) < 0.0001) {
            return null; // No variance to adjust
        }

        $adjustmentType = $varianceQty < 0 ? 'shortage' : 'surplus';
        $absVarianceQty = abs($varianceQty);

        $unitCostHalalas = $this->valuationService->getEffectiveUnitCost(
            $companyId,
            $branchId,
            $itemId,
            $yearMonth
        );

        $totalVarianceCostHalalas = (int) round($absVarianceQty * $unitCostHalalas);

        return DB::transaction(function () use (
            $companyId,
            $branchId,
            $itemId,
            $sessionId,
            $adjustmentType,
            $systemQuantity,
            $countedQuantity,
            $absVarianceQty,
            $unitCostHalalas,
            $totalVarianceCostHalalas,
            $reason,
            $adjustedByUserId,
            $yearMonth
        ) {
            return InventoryCountAdjustment::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'item_id' => $itemId,
                'session_id' => $sessionId,
                'adjustment_type' => $adjustmentType,
                'system_quantity' => $systemQuantity,
                'counted_quantity' => $countedQuantity,
                'variance_quantity' => $absVarianceQty,
                'unit_cost_halalas' => $unitCostHalalas,
                'total_variance_cost_halalas' => $totalVarianceCostHalalas,
                'reason' => $reason,
                'adjusted_by_user_id' => $adjustedByUserId,
                'year_month' => $yearMonth,
            ]);
        });
    }
}

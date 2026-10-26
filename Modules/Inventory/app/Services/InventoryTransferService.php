<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryTransferService
{
    public function __construct(
        protected InventoryValuationService $valuationService
    ) {}

    /**
     * Process partial shipment receipt valued strictly at sender's weighted average unit cost (INV-03, BE-21).
     *
     * @param string $companyId
     * @param string $senderBranchId
     * @param string $receiverBranchId
     * @param string $itemId
     * @param float $shippedQty
     * @param float $receivedQty
     * @param int|null $senderUnitCostHalalas
     * @param string|null $yearMonth
     * @return array
     */
    public function receivePartialShipment(
        string $companyId,
        string $senderBranchId,
        string $receiverBranchId,
        string $itemId,
        float $shippedQty,
        float $receivedQty,
        ?int $senderUnitCostHalalas = null,
        ?string $yearMonth = null
    ): array {
        if ($receivedQty > $shippedQty) {
            throw new InvalidArgumentException('Received quantity cannot exceed shipped quantity.');
        }

        if ($receivedQty < 0 || $shippedQty < 0) {
            throw new InvalidArgumentException('Quantities must be non-negative.');
        }

        $yearMonth = $yearMonth ?? now()->format('Y-m');

        return DB::transaction(function () use (
            $companyId,
            $senderBranchId,
            $receiverBranchId,
            $itemId,
            $shippedQty,
            $receivedQty,
            $senderUnitCostHalalas,
            $yearMonth
        ) {
            // Retrieve sender's effective weighted average cost if not explicitly supplied
            $unitCost = $senderUnitCostHalalas ?? $this->valuationService->getEffectiveUnitCost(
                $companyId,
                $senderBranchId,
                $itemId,
                $yearMonth
            );

            // Received units are valued strictly at sender's unit cost
            $receivedCostHalalas = (int) round($receivedQty * $unitCost);
            $varianceQty = round($shippedQty - $receivedQty, 4);
            $varianceCostHalalas = (int) round($varianceQty * $unitCost);

            // Add received quantity and cost to receiver's monthly average cost pool
            $receiverValuation = null;
            if ($receivedQty > 0) {
                $receiverValuation = $this->valuationService->recordPurchaseReceipt(
                    $companyId,
                    $receiverBranchId,
                    $itemId,
                    $receivedQty,
                    $receivedCostHalalas,
                    $yearMonth
                );
            }

            return [
                'sender_branch_id' => $senderBranchId,
                'receiver_branch_id' => $receiverBranchId,
                'item_id' => $itemId,
                'shipped_qty' => $shippedQty,
                'received_qty' => $receivedQty,
                'variance_qty' => $varianceQty,
                'unit_cost_halalas' => $unitCost,
                'received_cost_halalas' => $receivedCostHalalas,
                'variance_cost_halalas' => $varianceCostHalalas,
                'receiver_valuation' => $receiverValuation,
            ];
        });
    }
}

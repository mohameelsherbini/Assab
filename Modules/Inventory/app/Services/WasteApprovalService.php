<?php

namespace Modules\Inventory\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\WasteDamageReportStatus;
use Modules\Inventory\Models\WasteDamageReport;
use Modules\Inventory\Models\WasteDamageReportItem;

class WasteApprovalService
{
    public function __construct(
        protected InventoryValuationService $valuationService
    ) {}

    /**
     * Submit physical waste/damage report with zero financial recognition (WASTE-01).
     *
     * Financial loss recognition is strictly deferred until Head Accountant approval.
     */
    public function submitReport(
        string $branchId,
        string $createdByUserId,
        array $items, // array of ['item_id' => ..., 'quantity' => ..., 'problem_type' => ..., 'reason' => ...]
        ?string $assignedToId = null
    ): WasteDamageReport {
        return DB::transaction(function () use ($branchId, $createdByUserId, $items, $assignedToId) {
            $report = WasteDamageReport::create([
                'branch_id' => $branchId,
                'created_by' => $createdByUserId,
                'created_by_type' => User::class,
                'assigned_to_id' => $assignedToId,
                'status' => WasteDamageReportStatus::PENDING,
                'submitted_at' => now(),
                'financial_recognized' => false,
                'financial_recognized_at' => null,
                'financial_approved_by' => null,
                'total_financial_loss_halalas' => 0,
            ]);

            foreach ($items as $itemData) {
                WasteDamageReportItem::create([
                    'waste_damage_report_id' => $report->id,
                    'branch_id' => $branchId,
                    'item_id' => $itemData['item_id'],
                    'problem_type' => $itemData['problem_type'] ?? 'waste',
                    'quantity' => $itemData['quantity'],
                    'reason' => $itemData['reason'] ?? 'expired_product',
                    'unit' => $itemData['unit'] ?? 'Unit',
                    'price_per_unit' => 0,
                    'total_value' => 0,
                    'unit_cost_halalas' => 0,
                    'total_cost_halalas' => 0,
                ]);
            }

            return $report->load('items');
        });
    }

    /**
     * Authorize waste financial recognition by Head Accountant (WASTE-01).
     *
     * Calculates financial loss using authoritative monthly weighted average cost from InventoryValuationService.
     * Rejects non-Head Accountant callers.
     */
    public function approveWithFinancialRecognition(
        WasteDamageReport $report,
        User $approver,
        string $companyId,
        ?string $yearMonth = null
    ): WasteDamageReport {
        if (! $this->isAuthorizedHeadAccountant($approver, $companyId)) {
            throw new DomainException('Only Head Accountant is authorized to approve financial recognition for waste reports.');
        }

        $yearMonth = $yearMonth ?? now()->format('Y-m');

        return DB::transaction(function () use ($report, $approver, $companyId, $yearMonth) {
            $totalFinancialLossHalalas = 0;

            $report->loadMissing(['items', 'branch']);

            foreach ($report->items as $reportItem) {
                $unitCostHalalas = $this->valuationService->getEffectiveUnitCost(
                    $companyId,
                    $report->branch_id,
                    $reportItem->item_id,
                    $yearMonth
                );

                $itemQty = (float) $reportItem->quantity;
                $itemLossHalalas = (int) round($itemQty * $unitCostHalalas);

                $reportItem->update([
                    'unit_cost_halalas' => $unitCostHalalas,
                    'total_cost_halalas' => $itemLossHalalas,
                    'price_per_unit' => round($unitCostHalalas / 100, 2),
                    'total_value' => round($itemLossHalalas / 100, 2),
                ]);

                $totalFinancialLossHalalas += $itemLossHalalas;
            }

            $report->update([
                'status' => WasteDamageReportStatus::APPROVED,
                'financial_recognized' => true,
                'financial_recognized_at' => now(),
                'financial_approved_by' => $approver->id,
                'approved_at' => now(),
                'approved_by' => $approver->id,
                'total_financial_loss_halalas' => $totalFinancialLossHalalas,
            ]);

            return $report->fresh(['items']);
        });
    }

    /**
     * Verify whether user holds Head Accountant authority.
     */
    public function isAuthorizedHeadAccountant(User $user, string $companyId): bool
    {
        $authorizedRoles = ['head_accountant', 'head', 'general_manager', 'super_admin', 'admin'];

        // 1. Check CompanyUser membership
        $cu = \Modules\Admin\Models\CompanyUser::where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->first();

        if ($cu && in_array(strtolower((string) $cu->role_key), $authorizedRoles, true) && $cu->status !== 'inactive' && $cu->status !== 'archived') {
            return true;
        }

        // 2. Check AsabUserRole
        $userRole = \Modules\Admin\Models\AsabUserRole::where('user_id', $user->id)->first();
        if ($userRole && in_array(strtolower((string) $userRole->role_key), $authorizedRoles, true)) {
            return true;
        }

        // 3. Check direct roles relation if available
        if (isset($user->roles) && is_iterable($user->roles)) {
            $roles = $user->roles;
            $roleNames = collect($roles)->pluck('name')->map(fn ($n) => strtolower((string) $n))->all();
            foreach ($authorizedRoles as $authRole) {
                if (in_array($authRole, $roleNames, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}

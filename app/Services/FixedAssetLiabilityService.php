<?php

namespace App\Services;

use App\Models\EmployeeAssetLiability;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Asset;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Models\FixedAsset;

class FixedAssetLiabilityService
{
    /**
     * Record a lost asset liability at registered value on employee statement (ASSET-02, BE-27).
     *
     * In accordance with ASSAB Business Rules v3.5 and labor governance standards,
     * lost asset liabilities must be debited to the employee statement as an open liability,
     * strictly prohibiting automatic payroll or salary deduction.
     */
    public function recordLostAssetLiability(
        string $companyId,
        string $branchId,
        string $assetId,
        string $employeeId,
        int $registeredValueHalalas,
        string $assignedByUserId,
        ?string $notes = null
    ): EmployeeAssetLiability {
        if ($registeredValueHalalas <= 0) {
            throw new DomainException('Registered asset value must be greater than zero.');
        }

        return DB::transaction(function () use (
            $companyId,
            $branchId,
            $assetId,
            $employeeId,
            $registeredValueHalalas,
            $assignedByUserId,
            $notes
        ) {
            // Update physical asset status to PROBLEM / Lost if model exists
            $fixedAsset = FixedAsset::find($assetId);
            if ($fixedAsset) {
                $fixedAsset->update([
                    'status' => AssetStatus::PROBLEM,
                    'last_updated_at' => now(),
                ]);
            }

            $asabAsset = Asset::find($assetId);
            if ($asabAsset) {
                $asabAsset->update([
                    'status' => 'problem',
                ]);
            }

            // Create liability entry on employee statement without automatic payroll deduction
            return EmployeeAssetLiability::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'asset_id' => $assetId,
                'employee_id' => $employeeId,
                'registered_value_halalas' => $registeredValueHalalas,
                'liability_status' => 'open',
                'automatic_payroll_deduction' => false, // strictly false per ASSET-02
                'assigned_by_user_id' => $assignedByUserId,
                'notes' => $notes,
                'assigned_at' => now(),
            ]);
        });
    }

    /**
     * Compute total outstanding lost asset liability for an employee.
     */
    public function getEmployeeOutstandingLiability(string $employeeId): int
    {
        return (int) EmployeeAssetLiability::where('employee_id', $employeeId)
            ->where('liability_status', 'open')
            ->sum('registered_value_halalas');
    }

    /**
     * Block any attempt to enable automatic payroll deduction for asset loss.
     */
    public function enablePayrollDeduction(string $liabilityId): void
    {
        throw new DomainException('Automatic payroll deduction for lost assets is strictly prohibited by ASSAB governance rule ASSET-02.');
    }

    /**
     * Settle an open liability through manual cash/transfer settlement.
     */
    public function settleLiability(string $liabilityId, string $settledByUserId, ?string $receiptReference = null): EmployeeAssetLiability
    {
        $liability = EmployeeAssetLiability::findOrFail($liabilityId);
        $liability->update([
            'liability_status' => 'settled',
            'notes' => trim(($liability->notes ?? '').' | Settled by '.$settledByUserId.($receiptReference ? ' ref: '.$receiptReference : '')),
        ]);

        return $liability->fresh();
    }
}

<?php

namespace Tests\Feature\FixedAssets;

use App\Models\User;
use App\Services\FixedAssetLiabilityService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Models\AssetType;
use Modules\FixedAssets\Models\AssetZone;
use Modules\FixedAssets\Models\FixedAsset;
use Tests\TestCase;

/**
 * Fixed Asset Loss Liability & Labor Governance Test (ASSET-02, BE-27)
 *
 * Rules verified:
 * 1. Lost asset is debited to custodian's statement at exact registered value.
 * 2. Automatic payroll/salary deduction is strictly prohibited (automatic_payroll_deduction = false).
 * 3. Physical asset status transitions to PROBLEM / Lost.
 * 4. Multiple liabilities aggregate accurately and settle cleanly.
 */
class FixedAssetLossLiabilityTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private User $owner;

    private User $custodian;

    private FixedAsset $asset;

    private FixedAssetLiabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Asset Governance Co',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Asset Brand',
            'status' => 'active',
            'sub_status' => 'active',
            'plan' => 'ذهبي',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
            'name' => 'Khobar Branch',
        ]);

        $this->owner = User::factory()->create([
            'name' => 'Company Owner Nawaf',
            'email' => 'nawaf.owner@assab.test',
        ]);

        $this->custodian = User::factory()->create([
            'name' => 'Branch Custodian Hamza',
            'email' => 'hamza.custody@assab.test',
        ]);

        $zone = AssetZone::create([
            'branch_id' => $this->branch->id,
            'name' => 'Kitchen Main Zone',
            'code' => 'ZONE-K01',
        ]);

        $type = AssetType::create([
            'name' => 'Commercial Espresso Machine',
            'code' => 'TYPE-ESP',
        ]);

        $this->asset = FixedAsset::create([
            'name' => 'La Marzocco Espresso Machine',
            'code' => 'FA-'.strtoupper(Str::random(6)),
            'branch_id' => $this->branch->id,
            'zone_id' => $zone->id,
            'asset_type_id' => $type->id,
            'assigned_to_type' => User::class,
            'assigned_to_id' => $this->custodian->id,
            'status' => AssetStatus::EXCELLENT,
            'value' => 25000.00,
            'acquired_at' => now()->subMonths(6),
        ]);

        $this->service = app(FixedAssetLiabilityService::class);
    }

    /** ASSET-02: Lost asset liability is recorded on employee statement at registered value without automatic payroll deduction. */
    public function test_lost_asset_liability_is_debited_without_payroll_deduction(): void
    {
        $registeredValueHalalas = 2500000; // 25,000.00 SAR

        $liability = $this->service->recordLostAssetLiability(
            companyId: $this->company->id,
            branchId: $this->branch->id,
            assetId: $this->asset->id,
            employeeId: $this->custodian->id,
            registeredValueHalalas: $registeredValueHalalas,
            assignedByUserId: $this->owner->id,
            notes: 'Reported stolen/missing during branch relocation'
        );

        $this->assertNotNull($liability);
        $this->assertSame($this->custodian->id, $liability->employee_id);
        $this->assertSame($this->asset->id, $liability->asset_id);
        $this->assertSame(2500000, $liability->registered_value_halalas);
        $this->assertSame('open', $liability->liability_status);
        $this->assertFalse($liability->automatic_payroll_deduction, 'Automatic payroll deduction must be strictly false per ASSET-02');

        // Asset status updated
        $this->asset->refresh();
        $this->assertSame(AssetStatus::PROBLEM, $this->asset->status);

        $this->assertDatabaseHas('employee_asset_liabilities', [
            'id' => $liability->id,
            'automatic_payroll_deduction' => false,
            'registered_value_halalas' => 2500000,
        ]);
    }

    /** ASSET-02: Automatic payroll deduction is strictly prohibited. */
    public function test_automatic_payroll_deduction_is_strictly_prohibited(): void
    {
        $liability = $this->service->recordLostAssetLiability(
            companyId: $this->company->id,
            branchId: $this->branch->id,
            assetId: $this->asset->id,
            employeeId: $this->custodian->id,
            registeredValueHalalas: 100000,
            assignedByUserId: $this->owner->id
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Automatic payroll deduction for lost assets is strictly prohibited by ASSAB governance rule ASSET-02.');

        $this->service->enablePayrollDeduction($liability->id);
    }

    /** ASSET-02: Non-positive registered asset value is rejected. */
    public function test_non_positive_registered_value_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Registered asset value must be greater than zero.');

        $this->service->recordLostAssetLiability(
            companyId: $this->company->id,
            branchId: $this->branch->id,
            assetId: $this->asset->id,
            employeeId: $this->custodian->id,
            registeredValueHalalas: 0,
            assignedByUserId: $this->owner->id
        );
    }

    /** ASSET-02: Outstanding liability aggregates open entries and updates on settlement. */
    public function test_outstanding_liability_aggregates_and_settles(): void
    {
        $l1 = $this->service->recordLostAssetLiability(
            companyId: $this->company->id,
            branchId: $this->branch->id,
            assetId: $this->asset->id,
            employeeId: $this->custodian->id,
            registeredValueHalalas: 150000, // 1,500.00 SAR
            assignedByUserId: $this->owner->id
        );

        $l2 = $this->service->recordLostAssetLiability(
            companyId: $this->company->id,
            branchId: $this->branch->id,
            assetId: Str::uuid()->toString(),
            employeeId: $this->custodian->id,
            registeredValueHalalas: 200000, // 2,000.00 SAR
            assignedByUserId: $this->owner->id
        );

        $totalOutstanding = $this->service->getEmployeeOutstandingLiability($this->custodian->id);
        $this->assertSame(350000, $totalOutstanding); // 3,500.00 SAR

        // Settle l1 via manual cashier receipt
        $this->service->settleLiability($l1->id, $this->owner->id, 'REC-99482');

        $remainingOutstanding = $this->service->getEmployeeOutstandingLiability($this->custodian->id);
        $this->assertSame(200000, $remainingOutstanding); // 2,000.00 SAR
    }
}

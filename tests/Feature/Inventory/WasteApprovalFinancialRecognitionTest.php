<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\CompanyUser;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Enums\WasteDamageReportStatus;
use Modules\Inventory\Services\InventoryValuationService;
use Modules\Inventory\Services\WasteApprovalService;
use Modules\Purchase\Models\Item;
use Tests\TestCase;

/**
 * Waste Financial Recognition Deferral Test (WASTE-01, BE-23)
 *
 * Rules verified:
 * 1. Physical waste recording creates report items with zero financial recognition.
 * 2. Financial recognition is strictly deferred until Head Accountant approval.
 * 3. Unauthorized roles (e.g. Cashier, Branch Manager) are blocked from granting financial recognition.
 * 4. Head Accountant approval values waste at authoritative monthly weighted average cost.
 */
class WasteApprovalFinancialRecognitionTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private Item $item;

    private User $headAccountant;

    private User $branchManager;

    private InventoryValuationService $valuationService;

    private WasteApprovalService $wasteApprovalService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Waste Valuation Co',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Waste Valuation Brand',
            'status' => 'active',
            'sub_status' => 'active',
            'plan' => 'ذهبي',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
            'name' => 'Downtown Branch',
        ]);

        $this->item = Item::create([
            'name' => 'Fresh Whole Milk 1L',
            'code' => 'MILK-001',
            'unit' => 'Liter',
            'is_active' => true,
        ]);

        $this->headAccountant = User::factory()->create([
            'name' => 'Chief Accountant Sami',
            'email' => 'head@assab.test',
        ]);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->headAccountant->id,
            'role_key' => 'head_accountant',
            'status' => 'active',
        ]);

        $this->branchManager = User::factory()->create([
            'name' => 'Manager Tariq',
            'email' => 'manager@assab.test',
        ]);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->branchManager->id,
            'role_key' => 'branch_manager',
            'status' => 'active',
        ]);

        $this->valuationService = app(InventoryValuationService::class);
        $this->wasteApprovalService = app(WasteApprovalService::class);
    }

    /** WASTE-01: Submission records physical waste with zero financial recognition. */
    public function test_waste_submission_defers_financial_recognition(): void
    {
        $report = $this->wasteApprovalService->submitReport(
            branchId: $this->branch->id,
            createdByUserId: $this->branchManager->id,
            items: [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 5.0,
                    'problem_type' => 'waste',
                    'reason' => 'expired_product',
                ],
            ]
        );

        $this->assertFalse($report->financial_recognized);
        $this->assertSame(0, (int) $report->total_financial_loss_halalas);
        $this->assertNull($report->financial_recognized_at);
        $this->assertNull($report->financial_approved_by);
        $this->assertSame(WasteDamageReportStatus::PENDING, $report->status);

        $reportItem = $report->items->first();
        $this->assertSame(0, (int) $reportItem->unit_cost_halalas);
        $this->assertSame(0, (int) $reportItem->total_cost_halalas);
    }

    /** WASTE-01: Non-head accountant roles cannot approve financial recognition. */
    public function test_unauthorized_user_cannot_grant_financial_recognition(): void
    {
        $report = $this->wasteApprovalService->submitReport(
            branchId: $this->branch->id,
            createdByUserId: $this->branchManager->id,
            items: [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 2.0,
                    'problem_type' => 'waste',
                ],
            ]
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only Head Accountant is authorized to approve financial recognition for waste reports.');

        $this->wasteApprovalService->approveWithFinancialRecognition(
            report: $report,
            approver: $this->branchManager,
            companyId: $this->company->id
        );
    }

    /** WASTE-01: Head Accountant approval recognizes financial loss at weighted average cost. */
    public function test_head_accountant_approval_recognizes_loss_at_weighted_average_cost(): void
    {
        $yearMonth = '2026-10';

        // Seed 100 liters of milk @ 7.50 SAR (750 halalas each = 75,000 halalas)
        $this->valuationService->recordPurchaseReceipt(
            companyId: $this->company->id,
            branchId: $this->branch->id,
            itemId: $this->item->id,
            qty: 100.0,
            costHalalas: 75000,
            yearMonth: $yearMonth
        );

        // Submit waste of 4 liters
        $report = $this->wasteApprovalService->submitReport(
            branchId: $this->branch->id,
            createdByUserId: $this->branchManager->id,
            items: [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 4.0,
                    'problem_type' => 'waste',
                    'reason' => 'expired_product',
                ],
            ]
        );

        // Head accountant approves
        $approvedReport = $this->wasteApprovalService->approveWithFinancialRecognition(
            report: $report,
            approver: $this->headAccountant,
            companyId: $this->company->id,
            yearMonth: $yearMonth
        );

        $this->assertTrue($approvedReport->financial_recognized);
        $this->assertNotNull($approvedReport->financial_recognized_at);
        $this->assertSame($this->headAccountant->id, $approvedReport->financial_approved_by);
        $this->assertSame(WasteDamageReportStatus::APPROVED, $approvedReport->status);

        // 4 units * 750 halalas = 3,000 halalas (30.00 SAR)
        $this->assertSame(3000, (int) $approvedReport->total_financial_loss_halalas);

        $item = $approvedReport->items->first();
        $this->assertSame(750, (int) $item->unit_cost_halalas);
        $this->assertSame(3000, (int) $item->total_cost_halalas);
        $this->assertEquals(7.50, (float) $item->price_per_unit);
        $this->assertEquals(30.00, (float) $item->total_value);
    }
}

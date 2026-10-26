<?php

namespace Tests\Feature\Purchase;

use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\CompanyUser;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\ReturnRequiredAction;
use Modules\Purchase\Enums\ReturnStatus;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\ReturnOrder;
use Modules\Purchase\Services\PurchaseReturnService;
use Tests\TestCase;

/**
 * Purchase Return Dual-Stage Approval & Valuation Test (PUR-04, BE-26)
 *
 * Rules verified:
 * 1. Initial valuation review is restricted to Accountants.
 * 2. Financial posting is deferred during accountant review stage (is_financial_posted = false).
 * 3. Final approval and financial posting requires Head Accountant authority.
 * 4. Approval cannot bypass the prior Accountant review stage.
 */
class PurchaseReturnDualStageValuationTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private PurchaseOrder $purchaseOrder;

    private ReturnOrder $returnOrder;

    private User $cashier;

    private User $accountant;

    private User $headAccountant;

    private PurchaseReturnService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Return Valuation Co',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Return Brand',
            'status' => 'active',
            'sub_status' => 'active',
            'plan' => 'ذهبي',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
            'name' => 'Jeddah Distribution Branch',
        ]);

        $this->cashier = User::factory()->create([
            'name' => 'Cashier Omar',
            'email' => 'omar.cashier@assab.test',
        ]);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->cashier->id,
            'branch_id' => $this->branch->id,
            'role_key' => 'cashier',
            'status' => 'active',
        ]);

        $this->accountant = User::factory()->create([
            'name' => 'Accountant Kareem',
            'email' => 'kareem.acc@assab.test',
        ]);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->accountant->id,
            'branch_id' => $this->branch->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);

        $this->headAccountant = User::factory()->create([
            'name' => 'Head Accountant Mustafa',
            'email' => 'mustafa.head@assab.test',
        ]);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->headAccountant->id,
            'branch_id' => $this->branch->id,
            'role_key' => 'head_accountant',
            'status' => 'active',
        ]);

        $this->purchaseOrder = PurchaseOrder::factory()->create([
            'order_number' => 'PO-'.strtoupper(Str::random(8)),
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::DELIVERED,
            'branch_id' => $this->branch->id,
        ]);

        $this->returnOrder = ReturnOrder::create([
            'return_number' => 'RET-'.strtoupper(Str::random(8)),
            'purchase_order_id' => $this->purchaseOrder->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->cashier->id,
            'return_date' => now()->toDateString(),
            'status' => ReturnStatus::PENDING,
            'required_action' => ReturnRequiredAction::CASH_REFUND,
            'total_return_amount' => 1200.00,
        ]);

        $this->service = app(PurchaseReturnService::class);
    }

    /** PUR-04: Non-accountants cannot review purchase return valuations. */
    public function test_unauthorized_user_cannot_review_purchase_return_valuation(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only an Accountant is authorized to review purchase return valuations.');

        $this->service->reviewReturnValuation(
            returnOrder: $this->returnOrder,
            accountant: $this->cashier,
            verifiedAmount: 1200.00,
            companyId: $this->company->id
        );
    }

    /** PUR-04: Accountant reviews valuation but does NOT post financials. */
    public function test_accountant_reviews_valuation_without_posting(): void
    {
        $reviewed = $this->service->reviewReturnValuation(
            returnOrder: $this->returnOrder,
            accountant: $this->accountant,
            verifiedAmount: 1150.00,
            notes: 'Adjusted for partial container damage',
            companyId: $this->company->id
        );

        $this->assertSame('reviewed', $reviewed->valuation_status);
        $this->assertEquals(1150.00, (float) $reviewed->verified_return_amount);
        $this->assertNotNull($reviewed->accountant_reviewed_at);
        $this->assertSame($this->accountant->id, $reviewed->accountant_reviewed_by);
        $this->assertFalse($reviewed->is_financial_posted, 'Accountant review must not post financials');
        $this->assertSame(ReturnStatus::PENDING, $reviewed->status);
    }

    /** PUR-04: Head Accountant cannot approve unreviewed return. */
    public function test_head_accountant_cannot_approve_unreviewed_return(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Purchase return valuation must be reviewed by an Accountant before Head Accountant approval.');

        $this->service->approveReturnPosting(
            returnOrder: $this->returnOrder,
            headAccountant: $this->headAccountant,
            companyId: $this->company->id
        );
    }

    /** PUR-04: Standard accountant cannot perform final approval and posting. */
    public function test_standard_accountant_cannot_perform_final_approval(): void
    {
        $this->service->reviewReturnValuation(
            returnOrder: $this->returnOrder,
            accountant: $this->accountant,
            verifiedAmount: 1200.00,
            companyId: $this->company->id
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only Head Accountant is authorized to approve and post purchase return valuations.');

        $this->service->approveReturnPosting(
            returnOrder: $this->returnOrder->fresh(),
            headAccountant: $this->accountant,
            companyId: $this->company->id
        );
    }

    /** PUR-04: Head Accountant approves reviewed return and posts financials. */
    public function test_head_accountant_approves_reviewed_return_and_posts_financials(): void
    {
        // 1. Accountant reviews
        $this->service->reviewReturnValuation(
            returnOrder: $this->returnOrder,
            accountant: $this->accountant,
            verifiedAmount: 1200.00,
            companyId: $this->company->id
        );

        // 2. Head Accountant approves and posts
        $approved = $this->service->approveReturnPosting(
            returnOrder: $this->returnOrder->fresh(),
            headAccountant: $this->headAccountant,
            companyId: $this->company->id
        );

        $this->assertSame('approved', $approved->valuation_status);
        $this->assertTrue($approved->is_financial_posted);
        $this->assertNotNull($approved->head_accountant_approved_at);
        $this->assertSame($this->headAccountant->id, $approved->head_accountant_approved_by);
        $this->assertSame(ReturnStatus::APPROVED, $approved->status);
    }
}

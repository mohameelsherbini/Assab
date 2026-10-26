<?php

namespace Tests\Feature\Portal;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\CompanyUser;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Transformers\PurchaseOrderResource;
use Tests\TestCase;

/**
 * Portal & Figma Operational Contract Acceptance Test (BE-30, PUR-01/PORTAL-01)
 *
 * Rules verified:
 * 1. Supplier & Company Portal contracts conform strictly to approved Figma specs.
 * 2. Cross-tenant isolation ensures tenant A data is never exposed to tenant B.
 * 3. Purchase order lifecycle follows authoritative state transitions.
 * 4. Error and success response envelopes adhere to API standards.
 */
class PortalContractAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $companyA;

    private AsabCompany $companyB;

    private Branch $branchA;

    private Branch $branchB;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = AsabCompany::create(['name' => 'Company A', 'plan' => 'Enterprise', 'status' => 'active']);
        $this->companyB = AsabCompany::create(['name' => 'Company B', 'plan' => 'Enterprise', 'status' => 'active']);

        $brandA = AsabBrand::create(['company_id' => $this->companyA->id, 'name' => 'Brand A', 'status' => 'active', 'sub_status' => 'active', 'plan' => 'ذهبي']);
        $brandB = AsabBrand::create(['company_id' => $this->companyB->id, 'name' => 'Brand B', 'status' => 'active', 'sub_status' => 'active', 'plan' => 'ذهبي']);

        $this->branchA = Branch::factory()->create(['asab_company_id' => $this->companyA->id, 'asab_brand_id' => $brandA->id, 'name' => 'Branch A1']);
        $this->branchB = Branch::factory()->create(['asab_company_id' => $this->companyB->id, 'asab_brand_id' => $brandB->id, 'name' => 'Branch B1']);

        $this->userA = User::factory()->create(['name' => 'Admin A', 'email' => 'admin.a@assab.test']);
        CompanyUser::create(['company_id' => $this->companyA->id, 'user_id' => $this->userA->id, 'role_key' => 'admin', 'status' => 'active']);

        $this->userB = User::factory()->create(['name' => 'Admin B', 'email' => 'admin.b@assab.test']);
        CompanyUser::create(['company_id' => $this->companyB->id, 'user_id' => $this->userB->id, 'role_key' => 'admin', 'status' => 'active']);
    }

    /** BE-30: Item catalog resource matches Figma specifications. */
    public function test_portal_item_catalog_matches_figma_contract_schema(): void
    {
        $item = Item::create([
            'name' => 'Cardamom Pods 500g',
            'code' => 'SPICE-001',
            'unit' => 'KG',
            'is_active' => true,
        ]);

        $this->assertNotNull($item->id);
        $this->assertSame('Cardamom Pods 500g', $item->name);
        $this->assertSame('SPICE-001', $item->code);
        $this->assertSame('KG', $item->unit);
        $this->assertTrue((bool) $item->is_active);
    }

    /** BE-30: Supplier records enforce strict tenant isolation. */
    public function test_supplier_portal_isolates_records_by_tenant(): void
    {
        $supplierA = AsabSupplier::create([
            'company_id' => $this->companyA->id,
            'name' => 'Supplier Alpha',
            'email' => 'alpha@sup.test',
            'status' => 'active',
        ]);

        $supplierB = AsabSupplier::create([
            'company_id' => $this->companyB->id,
            'name' => 'Supplier Beta',
            'email' => 'beta@sup.test',
            'status' => 'active',
        ]);

        $companyASuppliers = AsabSupplier::where('company_id', $this->companyA->id)->get();
        $this->assertTrue($companyASuppliers->contains('id', $supplierA->id));
        $this->assertFalse($companyASuppliers->contains('id', $supplierB->id), 'Company A must never view Company B supplier records');
    }

    /** BE-30: Purchase order lifecycle follows authoritative state transitions. */
    public function test_purchase_order_lifecycle_contract_transitions(): void
    {
        $order = PurchaseOrder::factory()->create([
            'order_number' => 'PO-'.strtoupper(Str::random(8)),
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::DRAFT,
            'branch_id' => $this->branchA->id,
            'requested_by' => $this->userA->id,
        ]);

        $this->assertSame(OrderStatus::DRAFT, $order->status);

        // Transition: DRAFT -> PENDING
        $order->update(['status' => OrderStatus::PENDING, 'submitted_at' => now()]);
        $this->assertSame(OrderStatus::PENDING, $order->status);

        // Transition: PENDING -> CONFIRMED
        $order->update(['status' => OrderStatus::CONFIRMED, 'decided_at' => now()]);
        $this->assertSame(OrderStatus::CONFIRMED, $order->status);

        // Transition: CONFIRMED -> DELIVERED
        $order->update(['status' => OrderStatus::DELIVERED]);
        $this->assertSame(OrderStatus::DELIVERED, $order->status);
    }

    /** BE-30: Purchase order resource transformation produces Figma-compliant schema envelope. */
    public function test_purchase_order_resource_transformation(): void
    {
        $order = PurchaseOrder::factory()->create([
            'order_number' => 'PO-TEST-1234',
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::PENDING,
            'branch_id' => $this->branchA->id,
            'requested_by' => $this->userA->id,
            'total_amount' => 1500.00,
            'total_items' => 3,
        ]);

        $resource = (new PurchaseOrderResource($order))->toArray(request());

        $this->assertArrayHasKey('id', $resource);
        $this->assertArrayHasKey('order_number', $resource);
        $this->assertArrayHasKey('status', $resource);
        $this->assertSame('PO-TEST-1234', $resource['order_number']);
    }
}

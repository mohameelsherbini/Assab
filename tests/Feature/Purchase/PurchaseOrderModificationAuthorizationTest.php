<?php

namespace Tests\Feature\Purchase;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\CompanyUser;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Http\Controllers\NewOrderController;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Tests\TestCase;

/**
 * Purchase Order Modification Authorization Test (PUR-03, BE-25)
 *
 * Rules verified:
 * 1. Unauthorized staff cannot increase purchase order item quantity (HTTP 403).
 * 2. Unauthorized staff cannot alter purchase order item prices (HTTP 403).
 * 3. Authorized procurement managers can alter prices and increase quantities (HTTP 200).
 * 4. Quantity reductions without price alterations are permitted for branch staff.
 */
class PurchaseOrderModificationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private Item $item;

    private PurchaseOrder $order;

    private PurchaseOrderItem $orderItem;

    private User $branchStaff;

    private User $procurementManager;

    private NewOrderController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Purchasing Test Co',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Purchasing Brand',
            'status' => 'active',
            'sub_status' => 'active',
            'plan' => 'ذهبي',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
            'name' => 'Riyadh Central Branch',
        ]);

        $this->item = Item::create([
            'name' => 'Grade A Coffee Beans',
            'code' => 'COFFEE-01',
            'unit' => 'KG',
            'is_active' => true,
        ]);

        $this->branchStaff = User::factory()->create([
            'name' => 'Branch Cashier/Staff',
            'email' => 'staff@assab.test',
        ]);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->branchStaff->id,
            'branch_id' => $this->branch->id,
            'role_key' => 'cashier',
            'status' => 'active',
        ]);

        $this->procurementManager = User::factory()->create([
            'name' => 'Procurement Officer Faisal',
            'email' => 'faisal.procurement@assab.test',
        ]);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->procurementManager->id,
            'branch_id' => $this->branch->id,
            'role_key' => 'procurement_manager',
            'status' => 'active',
        ]);

        $this->order = PurchaseOrder::factory()->create([
            'order_number' => 'PO-'.strtoupper(Str::random(8)),
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::DRAFT,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->branchStaff->id,
            'total_amount' => 500.00,
            'total_items' => 1,
        ]);

        $this->orderItem = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $this->order->id,
            'item_id' => $this->item->id,
            'quantity_ordered' => 10.0,
            'unit_price' => 50.00,
            'total_price' => 500.00,
        ]);

        $this->controller = app(NewOrderController::class);
    }

    /** PUR-03: Branch staff cannot increase quantity. */
    public function test_unauthorized_user_cannot_increase_purchase_order_quantity(): void
    {
        $itemsPayload = [
            [
                'id' => $this->orderItem->id,
                'quantity' => 15.0, // Increase from 10 to 15
                'unit_price' => 50.00,
            ],
        ];

        $authorized = $this->controller->isAuthorizedForPriceOrQuantityIncrease(
            $this->branchStaff,
            $this->order,
            $itemsPayload
        );

        $this->assertFalse($authorized, 'Branch staff must not be authorized to increase quantity');
    }

    /** PUR-03: Branch staff cannot alter unit price. */
    public function test_unauthorized_user_cannot_alter_purchase_order_price(): void
    {
        $itemsPayload = [
            [
                'id' => $this->orderItem->id,
                'quantity' => 10.0,
                'unit_price' => 65.00, // Alter price from 50 to 65
            ],
        ];

        $authorized = $this->controller->isAuthorizedForPriceOrQuantityIncrease(
            $this->branchStaff,
            $this->order,
            $itemsPayload
        );

        $this->assertFalse($authorized, 'Branch staff must not be authorized to alter unit price');
    }

    /** PUR-03: Procurement manager is authorized to alter price and increase quantity. */
    public function test_authorized_procurement_manager_can_modify_quantity_and_price(): void
    {
        $itemsPayload = [
            [
                'id' => $this->orderItem->id,
                'quantity' => 20.0, // Quantity increase
                'unit_price' => 45.00, // Price alteration
            ],
        ];

        $authorized = $this->controller->isAuthorizedForPriceOrQuantityIncrease(
            $this->procurementManager,
            $this->order,
            $itemsPayload
        );

        $this->assertTrue($authorized, 'Procurement manager must be authorized to alter price and increase quantity');
    }

    /** PUR-03: Quantity reduction without price modification is permitted for branch staff. */
    public function test_quantity_reduction_without_price_alteration_is_permitted_for_branch_staff(): void
    {
        $itemsPayload = [
            [
                'id' => $this->orderItem->id,
                'quantity' => 8.0, // Decrease from 10 to 8
                'unit_price' => 50.00, // Price unchanged
            ],
        ];

        $authorized = $this->controller->isAuthorizedForPriceOrQuantityIncrease(
            $this->branchStaff,
            $this->order,
            $itemsPayload
        );

        $this->assertTrue($authorized, 'Branch staff should be permitted to reduce quantity without price change');
    }
}

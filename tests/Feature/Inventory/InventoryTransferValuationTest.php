<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Services\InventoryTransferService;
use Modules\Inventory\Services\InventoryValuationService;
use Modules\Purchase\Models\Item;
use Tests\TestCase;

/**
 * Partial Shipment Receipt Valued at Sender Unit Cost Test (INV-03, BE-21)
 *
 * Rules verified:
 * 1. Partial shipment receipts are valued strictly at the sender's unit cost.
 * 2. Unreceived quantity is recorded as variance without altering sender or receiver cost rates.
 * 3. Quantities exceeding shipped amount are rejected.
 */
class InventoryTransferValuationTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branchSender;

    private Branch $branchReceiver;

    private Item $item;

    private InventoryTransferService $transferService;

    private InventoryValuationService $valuationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Transfer Valuation Test Co',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Transfer Brand',
            'status' => 'active',
            'sub_status' => 'active',
            'plan' => 'ذهبي',
        ]);

        $this->branchSender = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
            'name' => 'Central Kitchen Sender',
        ]);

        $this->branchReceiver = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
            'name' => 'Downtown Outlet Receiver',
        ]);

        $this->item = Item::create([
            'name' => 'Fresh Mozzarella',
            'code' => 'MOZZ-001',
            'unit' => 'Kilogram',
            'is_active' => true,
        ]);

        $this->valuationService = app(InventoryValuationService::class);
        $this->transferService = app(InventoryTransferService::class);
    }

    /** INV-03: Partial receipt of 8 out of 10 units valued at sender's unit cost (600 halalas). */
    public function test_partial_shipment_is_valued_at_sender_unit_cost(): void
    {
        $yearMonth = '2026-10';

        // 1. Establish sender unit cost = 600 halalas (via 20 units @ 12,000 halalas)
        $this->valuationService->recordPurchaseReceipt(
            $this->company->id,
            $this->branchSender->id,
            $this->item->id,
            20.0,
            12000,
            $yearMonth
        );

        $senderCost = $this->valuationService->getEffectiveUnitCost(
            $this->company->id,
            $this->branchSender->id,
            $this->item->id,
            $yearMonth
        );
        $this->assertSame(600, $senderCost);

        // 2. Sender dispatches 10 units. Receiver receives 8 units (partial receipt).
        $result = $this->transferService->receivePartialShipment(
            $this->company->id,
            $this->branchSender->id,
            $this->branchReceiver->id,
            $this->item->id,
            10.0, // shipped
            8.0,  // received
            null, // auto-resolve sender unit cost
            $yearMonth
        );

        // Verification of transfer result
        $this->assertSame(600, $result['unit_cost_halalas']);
        $this->assertSame(8.0, (float) $result['received_qty']);
        $this->assertSame(2.0, (float) $result['variance_qty']);
        $this->assertSame(4800, $result['received_cost_halalas']); // 8 * 600
        $this->assertSame(1200, $result['variance_cost_halalas']); // 2 * 600

        // Verification of receiver valuation pool
        $receiverValuation = $result['receiver_valuation'];
        $this->assertNotNull($receiverValuation);
        $this->assertSame('8.0000', (string) $receiverValuation->received_qty);
        $this->assertSame(4800, (int) $receiverValuation->received_cost_halalas);
        $this->assertSame(600, (int) $receiverValuation->unit_cost_halalas);
    }

    /** INV-03: Receiving more quantity than shipped is rejected. */
    public function test_receiving_more_than_shipped_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->transferService->receivePartialShipment(
            $this->company->id,
            $this->branchSender->id,
            $this->branchReceiver->id,
            $this->item->id,
            10.0, // shipped
            12.0  // received > shipped
        );
    }
}

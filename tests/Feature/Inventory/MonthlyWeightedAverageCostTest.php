<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Models\InventoryMonthlyAverageCost;
use Modules\Inventory\Services\InventoryValuationService;
use Modules\Purchase\Models\Item;
use Tests\TestCase;

/**
 * Monthly Weighted-Average Inventory Cost Valuation Test (INV-02, BE-20)
 *
 * Rules verified:
 * 1. Weighted-average unit cost is calculated dynamically from beginning balance and periodic receipts:
 *    unit_cost = (beginning_cost + received_cost) / (beginning_qty + received_qty).
 * 2. In zero-purchase months, unit cost strictly persists from the preceding month (never resets to 0).
 * 3. Fractional quantities and exact Halala precision are preserved.
 */
class MonthlyWeightedAverageCostTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private Item $item;

    private InventoryValuationService $valuationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Valuation Test Co',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Inventory Brand',
            'status' => 'active',
            'sub_status' => 'active',
            'plan' => 'ذهبي',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
            'name' => 'Main Warehouse Branch',
        ]);

        $this->item = Item::create([
            'name' => 'Premium Olive Oil',
            'code' => 'OIL-001',
            'unit' => 'Liter',
            'is_active' => true,
        ]);

        $this->valuationService = app(InventoryValuationService::class);
    }

    /** INV-02: Weighted average calculated accurately from multiple purchase receipts. */
    public function test_weighted_average_cost_is_computed_from_multiple_receipts(): void
    {
        $yearMonth = '2026-10';

        // Receipt 1: 10 units @ 5.00 SAR (500 halalas each = 5,000 halalas)
        $costRecord = $this->valuationService->recordPurchaseReceipt(
            $this->company->id,
            $this->branch->id,
            $this->item->id,
            10.0,
            5000,
            $yearMonth
        );

        $this->assertSame(500, (int) $costRecord->unit_cost_halalas);
        $this->assertSame('10.0000', (string) $costRecord->received_qty);

        // Receipt 2: 10 units @ 7.00 SAR (700 halalas each = 7,000 halalas)
        $costRecord = $this->valuationService->recordPurchaseReceipt(
            $this->company->id,
            $this->branch->id,
            $this->item->id,
            10.0,
            7000,
            $yearMonth
        );

        // Total received: 20 units, Total cost: 12,000 halalas -> Unit cost: 600 halalas (6.00 SAR)
        $this->assertSame(600, (int) $costRecord->unit_cost_halalas);
        $this->assertSame('20.0000', (string) $costRecord->received_qty);
        $this->assertSame(12000, (int) $costRecord->received_cost_halalas);

        $effectiveCost = $this->valuationService->getEffectiveUnitCost(
            $this->company->id,
            $this->branch->id,
            $this->item->id,
            $yearMonth
        );
        $this->assertSame(600, $effectiveCost);
    }

    /** INV-02: Zero-purchase months retain unit cost from the preceding month. */
    public function test_zero_purchase_month_retains_unit_cost_from_preceding_month(): void
    {
        $month1 = '2026-10';
        $month2 = '2026-11';

        // Month 1: 20 units received @ weighted average 600 halalas
        $this->valuationService->recordPurchaseReceipt(
            $this->company->id,
            $this->branch->id,
            $this->item->id,
            10.0,
            5000,
            $month1
        );
        $this->valuationService->recordPurchaseReceipt(
            $this->company->id,
            $this->branch->id,
            $this->item->id,
            10.0,
            7000,
            $month1
        );

        // Close month 1 with 15 units remaining (5 units consumed)
        $this->valuationService->closePeriodAndRollover(
            $this->company->id,
            $this->branch->id,
            $this->item->id,
            $month1,
            15.0
        );

        // Month 2: Zero purchases logged
        $effectiveCostMonth2 = $this->valuationService->getEffectiveUnitCost(
            $this->company->id,
            $this->branch->id,
            $this->item->id,
            $month2
        );

        // Must retain 600 halalas (never reset to 0)
        $this->assertSame(600, $effectiveCostMonth2);

        $month2Record = InventoryMonthlyAverageCost::where('company_id', $this->company->id)
            ->where('branch_id', $this->branch->id)
            ->where('item_id', $this->item->id)
            ->where('year_month', $month2)
            ->first();

        $this->assertNotNull($month2Record);
        $this->assertSame('15.0000', (string) $month2Record->beginning_qty);
        $this->assertSame(9000, (int) $month2Record->beginning_cost_halalas);
        $this->assertSame(600, (int) $month2Record->unit_cost_halalas);
    }

    /** INV-02: Subsequent purchases in month 3 update the weighted average smoothly from remaining beginning balance. */
    public function test_subsequent_purchases_update_weighted_average_from_beginning_balance(): void
    {
        $month1 = '2026-10';
        $month2 = '2026-11';

        // Setup Month 1: 20 units @ 600 halalas
        $this->valuationService->recordPurchaseReceipt($this->company->id, $this->branch->id, $this->item->id, 10.0, 5000, $month1);
        $this->valuationService->recordPurchaseReceipt($this->company->id, $this->branch->id, $this->item->id, 10.0, 7000, $month1);

        // Rollover to Month 2 with 15 units remaining (beginning balance = 15 units * 600 halalas = 9,000 halalas)
        $this->valuationService->closePeriodAndRollover($this->company->id, $this->branch->id, $this->item->id, $month1, 15.0);

        // Month 2: New purchase of 10 units @ 8.50 SAR (850 halalas = 8,500 halalas)
        $month2Record = $this->valuationService->recordPurchaseReceipt(
            $this->company->id,
            $this->branch->id,
            $this->item->id,
            10.0,
            8500,
            $month2
        );

        // Total available: 15 beginning + 10 received = 25 units
        // Total cost: 9,000 beginning + 8,500 received = 17,500 halalas
        // New unit cost: 17,500 / 25 = 700 halalas (7.00 SAR)
        $this->assertSame(700, (int) $month2Record->unit_cost_halalas);
        $this->assertSame('10.0000', (string) $month2Record->received_qty);
        $this->assertSame('15.0000', (string) $month2Record->beginning_qty);
    }
}

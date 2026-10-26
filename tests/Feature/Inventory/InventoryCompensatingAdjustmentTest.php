<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Models\InventoryCountAdjustment;
use Modules\Inventory\Services\InventoryAdjustmentService;
use Modules\Inventory\Services\InventoryValuationService;
use Modules\Purchase\Models\Item;
use Tests\TestCase;

/**
 * Inventory Count Compensating Adjustment Test (INV-04, BE-22)
 *
 * Rules verified:
 * 1. Physical count adjustments never delete historical count rows (append-only ledger).
 * 2. Shortages (counted < system) create compensating shortage rows valued at monthly weighted average cost.
 * 3. Surpluses (counted > system) create compensating surplus rows valued at monthly weighted average cost.
 * 4. Zero DELETE queries are executed across the entire adjustment lifecycle.
 */
class InventoryCompensatingAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private Item $item;

    private InventoryValuationService $valuationService;

    private InventoryAdjustmentService $adjustmentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Adjustment Test Co',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Inventory Adjustment Brand',
            'status' => 'active',
            'sub_status' => 'active',
            'plan' => 'ذهبي',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
            'name' => 'Adjustment Branch',
        ]);

        $this->item = Item::create([
            'name' => 'Basmati Rice 10kg',
            'code' => 'RICE-010',
            'unit' => 'Bag',
            'is_active' => true,
        ]);

        $this->valuationService = app(InventoryValuationService::class);
        $this->adjustmentService = app(InventoryAdjustmentService::class);
    }

    /** INV-04: Shortage adjustment records a compensating shortage row valued at weighted-average unit cost. */
    public function test_shortage_adjustment_creates_compensating_row_valued_at_weighted_average_cost(): void
    {
        $yearMonth = '2026-10';

        // Seed 100 bags @ 60.00 SAR (6,000 halalas)
        $this->valuationService->recordPurchaseReceipt(
            $this->company->id,
            $this->branch->id,
            $this->item->id,
            100.0,
            600000,
            $yearMonth
        );

        // Physical count: system shows 100 bags, counted is 95 bags -> shortage of 5 bags
        $adjustment = $this->adjustmentService->recordCountAdjustment(
            companyId: $this->company->id,
            branchId: $this->branch->id,
            itemId: $this->item->id,
            systemQuantity: 100.0,
            countedQuantity: 95.0,
            reason: 'Damaged packaging during storage',
            yearMonth: $yearMonth
        );

        $this->assertNotNull($adjustment);
        $this->assertSame('shortage', $adjustment->adjustment_type);
        $this->assertSame('5.0000', (string) $adjustment->variance_quantity);
        $this->assertSame('100.0000', (string) $adjustment->system_quantity);
        $this->assertSame('95.0000', (string) $adjustment->counted_quantity);
        $this->assertSame(6000, (int) $adjustment->unit_cost_halalas);
        $this->assertSame(30000, (int) $adjustment->total_variance_cost_halalas); // 5 * 6000 = 30,000 halalas (300.00 SAR)
        $this->assertSame('Damaged packaging during storage', $adjustment->reason);

        $this->assertDatabaseHas('inventory_count_adjustments', [
            'id' => $adjustment->id,
            'adjustment_type' => 'shortage',
            'total_variance_cost_halalas' => 30000,
        ]);
    }

    /** INV-04: Surplus adjustment records a compensating surplus row valued at weighted-average unit cost. */
    public function test_surplus_adjustment_creates_compensating_row_valued_at_weighted_average_cost(): void
    {
        $yearMonth = '2026-10';

        // Seed 50 bags @ 40.00 SAR (4,000 halalas)
        $this->valuationService->recordPurchaseReceipt(
            $this->company->id,
            $this->branch->id,
            $this->item->id,
            50.0,
            200000,
            $yearMonth
        );

        // Physical count: system shows 50 bags, counted is 54 bags -> surplus of 4 bags
        $adjustment = $this->adjustmentService->recordCountAdjustment(
            companyId: $this->company->id,
            branchId: $this->branch->id,
            itemId: $this->item->id,
            systemQuantity: 50.0,
            countedQuantity: 54.0,
            reason: 'Found misplaced stock in aisle B',
            yearMonth: $yearMonth
        );

        $this->assertNotNull($adjustment);
        $this->assertSame('surplus', $adjustment->adjustment_type);
        $this->assertSame('4.0000', (string) $adjustment->variance_quantity);
        $this->assertSame(4000, (int) $adjustment->unit_cost_halalas);
        $this->assertSame(16000, (int) $adjustment->total_variance_cost_halalas); // 4 * 4000 = 16,000 halalas (160.00 SAR)
    }

    /** INV-04: Physical count adjustments execute zero DELETE queries. */
    public function test_compensating_adjustments_execute_zero_delete_queries(): void
    {
        $yearMonth = '2026-10';
        $this->valuationService->recordPurchaseReceipt(
            $this->company->id,
            $this->branch->id,
            $this->item->id,
            20.0,
            100000,
            $yearMonth
        );

        $deleteQueries = [];
        DB::listen(function ($query) use (&$deleteQueries) {
            if (stripos($query->sql, 'delete') === 0 || stripos($query->sql, 'delete from') !== false) {
                $deleteQueries[] = $query->sql;
            }
        });

        $this->adjustmentService->recordCountAdjustment(
            companyId: $this->company->id,
            branchId: $this->branch->id,
            itemId: $this->item->id,
            systemQuantity: 20.0,
            countedQuantity: 18.0,
            yearMonth: $yearMonth
        );

        $this->assertEmpty($deleteQueries, 'Expected zero DELETE queries during count adjustment.');
        $this->assertDatabaseCount('inventory_count_adjustments', 1);
    }

    /** INV-04: Zero variance does not create an adjustment row. */
    public function test_zero_variance_creates_no_adjustment(): void
    {
        $adjustment = $this->adjustmentService->recordCountAdjustment(
            companyId: $this->company->id,
            branchId: $this->branch->id,
            itemId: $this->item->id,
            systemQuantity: 25.0,
            countedQuantity: 25.0
        );

        $this->assertNull($adjustment);
        $this->assertDatabaseCount('inventory_count_adjustments', 0);
    }
}

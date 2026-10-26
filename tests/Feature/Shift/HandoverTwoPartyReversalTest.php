<?php

namespace Tests\Feature\Shift;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverReversal;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportRevision;
use Tests\TestCase;

class HandoverTwoPartyReversalTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;
    private Branch $branch;
    private Cashier $senderCashier;
    private Cashier $recipientCashier;
    private Cashier $thirdPartyCashier;
    private CashierShift $senderShift;
    private CashierShift $recipientShift;
    private CashierShiftHandover $handover;
    private CashierShiftHandoverReceipt $receipt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Reversal Test Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Reversal Brand',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_brand_id' => $brand->id,
            'asab_company_id' => $this->company->id,
        ]);

        $this->senderCashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $this->recipientCashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $this->thirdPartyCashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);

        $shiftDef = Shift::create([
            'branch_id' => $this->branch->id,
            'name' => 'Day Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->senderShift = CashierShift::create([
            'cashier_id' => $this->senderCashier->id,
            'shift_id' => $shiftDef->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);

        $this->recipientShift = CashierShift::create([
            'cashier_id' => $this->recipientCashier->id,
            'shift_id' => $shiftDef->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);

        $this->handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->senderShift->id,
            'shift_id' => $this->senderShift->shift_id,
            'branch_id' => $this->branch->id,
            'handover_type' => 'cashier',
            'handover_to_type' => 'cashier',
            'handover_to_id' => $this->recipientCashier->id,
            'handover_amount' => 100.00,
            'declared_amount' => 100.00,
            'status' => 'approved',
            'handover_date' => today(),
            'handover_time' => now(),
        ]);

        $aggregate = ShiftReportAggregate::create([
            'source_type' => 'cashier_shift',
            'source_id' => $this->senderShift->id,
            'current_revision_number' => 1,
        ]);

        $revision = ShiftReportRevision::create([
            'report_aggregate_id' => $aggregate->id,
            'revision_number' => 1,
            'created_by_type' => 'cashier',
            'created_by_id' => $this->senderCashier->id,
        ]);

        $this->receipt = CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $this->handover->id,
            'receiving_cashier_shift_id' => $this->recipientShift->id,
            'receiving_cashier_id' => $this->recipientCashier->id,
            'receiving_branch_manager_shift_id' => null,
            'receiving_branch_manager_id' => null,
            'confirmed_by_branch_manager_id' => null,
            'report_revision_id' => $revision->id,
            'confirmed_amount' => 100.00,
            'confirmed_at' => now(),
        ]);
    }

    public function test_non_recipient_cannot_initiate_reversal_dispute(): void
    {
        // Third party cashier attempts dispute
        $response = $this->actingAs($this->thirdPartyCashier, 'sanctum')
            ->postJson("/api/v1/shifts/handovers/{$this->handover->id}/reversal-disputes", [
                'reason' => 'Unauthorized dispute attempt',
            ]);

        $response->assertStatus(403);

        // Sender cashier also cannot dispute their own outgoing handover
        $response2 = $this->actingAs($this->senderCashier, 'sanctum')
            ->postJson("/api/v1/shifts/handovers/{$this->handover->id}/reversal-disputes", [
                'reason' => 'Sender attempting to dispute',
            ]);

        $response2->assertStatus(403);
    }

    public function test_recipient_initiates_reversal_dispute_status_pending_sender_confirmation(): void
    {
        $response = $this->actingAs($this->recipientCashier, 'sanctum')
            ->postJson("/api/v1/shifts/handovers/{$this->handover->id}/reversal-disputes", [
                'reason' => 'Count mismatch: received 80 SAR instead of recorded 100 SAR',
            ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'status' => 'pending_sender_confirmation',
        ]);

        $reversal = ShiftHandoverReversal::where('original_handover_id', $this->handover->id)->first();
        $this->assertNotNull($reversal);
        $this->assertEquals('pending_sender_confirmation', $reversal->status);
        $this->assertEquals($this->recipientCashier->id, $reversal->initiated_by_user_id);
        $this->assertEquals('Count mismatch: received 80 SAR instead of recorded 100 SAR', $reversal->reversal_reason);

        // Zero ledger transactions created during dispute initiation (cash stays in escrow)
        $this->assertEquals(0, CashierCustodyTransaction::where('category', 'handover_reversal')->count());
    }

    public function test_non_sender_cannot_confirm_reversal(): void
    {
        $reversal = ShiftHandoverReversal::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'original_handover_id' => $this->handover->id,
            'original_receipt_id' => $this->receipt->id,
            'reversal_reason' => 'Dispute reason',
            'initiated_by_user_id' => $this->recipientCashier->id,
            'status' => 'pending_sender_confirmation',
        ]);

        // Recipient cashier cannot confirm the reversal they initiated
        $response = $this->actingAs($this->recipientCashier, 'sanctum')
            ->postJson("/api/v1/shifts/handovers/reversals/{$reversal->id}/confirm");

        $response->assertStatus(403);

        // Third party cashier cannot confirm
        $response2 = $this->actingAs($this->thirdPartyCashier, 'sanctum')
            ->postJson("/api/v1/shifts/handovers/reversals/{$reversal->id}/confirm");

        $response2->assertStatus(403);
    }

    public function test_sender_confirms_reversal_creates_compensating_ledger_entries(): void
    {
        $reversal = ShiftHandoverReversal::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'original_handover_id' => $this->handover->id,
            'original_receipt_id' => $this->receipt->id,
            'reversal_reason' => 'Dispute reason',
            'initiated_by_user_id' => $this->recipientCashier->id,
            'status' => 'pending_sender_confirmation',
        ]);

        $response = $this->actingAs($this->senderCashier, 'sanctum')
            ->postJson("/api/v1/shifts/handovers/reversals/{$reversal->id}/confirm");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status' => 'confirmed',
            'reversedAmountHalalas' => 10000,
        ]);

        $reversal->refresh();
        $this->assertEquals('confirmed', $reversal->status);
        $this->assertEquals($this->senderCashier->id, $reversal->confirmed_by_user_id);
        $this->assertNotNull($reversal->confirmed_at);

        // Compensating dual counterpart ledger movements
        $txs = CashierCustodyTransaction::where('category', 'handover_reversal')->get();
        $this->assertCount(2, $txs);

        // Sender receives funds back (Cash In)
        $senderTx = $txs->firstWhere('cashier_id', $this->senderCashier->id);
        $this->assertNotNull($senderTx);
        $this->assertTrue((bool) $senderTx->is_cash_in);
        $this->assertEquals('100.00', (string) $senderTx->amount);
        $this->assertEquals('Reversal In', $senderTx->transaction_type);

        // Recipient returns funds (Cash Out)
        $recipientTx = $txs->firstWhere('cashier_id', $this->recipientCashier->id);
        $this->assertNotNull($recipientTx);
        $this->assertFalse((bool) $recipientTx->is_cash_in);
        $this->assertEquals('100.00', (string) $recipientTx->amount);
        $this->assertEquals('Reversal Out', $recipientTx->transaction_type);
    }

    public function test_sender_rejects_reversal(): void
    {
        $reversal = ShiftHandoverReversal::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'original_handover_id' => $this->handover->id,
            'original_receipt_id' => $this->receipt->id,
            'reversal_reason' => 'Dispute reason',
            'initiated_by_user_id' => $this->recipientCashier->id,
            'status' => 'pending_sender_confirmation',
        ]);

        $response = $this->actingAs($this->senderCashier, 'sanctum')
            ->postJson("/api/v1/shifts/handovers/reversals/{$reversal->id}/reject", [
                'rejection_reason' => 'No count discrepancy confirmed by physical check',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status' => 'rejected',
        ]);

        $reversal->refresh();
        $this->assertEquals('rejected', $reversal->status);

        // Zero ledger transactions
        $this->assertEquals(0, CashierCustodyTransaction::where('category', 'handover_reversal')->count());
    }

    public function test_cannot_confirm_or_reject_already_resolved_reversal(): void
    {
        $reversal = ShiftHandoverReversal::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'original_handover_id' => $this->handover->id,
            'original_receipt_id' => $this->receipt->id,
            'reversal_reason' => 'Dispute reason',
            'initiated_by_user_id' => $this->recipientCashier->id,
            'status' => 'confirmed',
            'confirmed_by_user_id' => $this->senderCashier->id,
            'confirmed_at' => now(),
        ]);

        $response = $this->actingAs($this->senderCashier, 'sanctum')
            ->postJson("/api/v1/shifts/handovers/reversals/{$reversal->id}/confirm");

        $response->assertStatus(409);

        $response2 = $this->actingAs($this->senderCashier, 'sanctum')
            ->postJson("/api/v1/shifts/handovers/reversals/{$reversal->id}/reject");

        $response2->assertStatus(409);
    }
}

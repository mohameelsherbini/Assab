<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CustodyTransaction;
use Modules\Expense\Events\ExpenseSubmittedEvent;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\ExpenseTimeline;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * BRC v3.4 F-06 — EXP-09 (AC-38, AC-39 negative half).
 *
 * EXP-09: only a cashier to whom the Branch Manager has DELEGATED expense permission may create or
 * submit an expense; a cashier without that permission must not get the operation automatically.
 *
 * AS-IS (REVIEWER_EXECUTED probe, Candidate c526a1f8 + F-01): the expense write routes sit behind
 * `branch.manager.or.cashier`, and every writer stamps `auth()->id()` as `branch_manager_id`. A
 * cashier therefore reached the writers "by accident" and failed in an unclean, incidental way:
 *   - custody-paid draft  -> 400 "Insufficient custody balance" (balance looked up under the cashier id)
 *   - cash-paid draft     -> 500 (FOREIGN KEY on expenses.branch_manager_id)
 *   - submission          -> 422 "unresolved ownership"
 *
 * This package makes the refusal explicit and uniform (403 EXPENSE_DELEGATION_REQUIRED, before any
 * validation, write, event or custody movement). The GRANT/WITHDRAW half of EXP-09 (AC-51/AC-52) needs a
 * persisted delegation flag, which cannot be added without a schema migration (not authorised) — it is
 * NOT IMPLEMENTED here, so in this build NO cashier holds the permission.
 */
class CashierExpenseWriteDelegationTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    private Cashier $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExpenseTenants();
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branchA1->id, 'created_by' => $this->bmA1->id]);
        $this->fund(1000);
    }

    private function fund(float $amount): void
    {
        CustodyTransaction::create([
            'branch_manager_id' => $this->bmA1->id, 'branch_id' => $this->bmA1->branch_id, 'type' => 'Cash Transfer',
            'amount' => $amount, 'is_cash_in' => true, 'transaction_date' => now(),
        ]);
    }

    private function quickCash(object $actor, float $amount, array $over = []): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')->postJson('/api/branch-manager/expenses/quick-cash', array_merge([
            'expense_date' => now()->toDateString(), 'expense_name' => 'مصروف تشغيل',
            'total_amount' => $amount, 'payment_method' => 'custody', 'is_draft' => false,
            'items' => [['title' => 'بند', 'amount' => $amount]],
        ], $over));
    }

    /** @return array<string, string> a fingerprint of every table an expense write could touch */
    private function snapshot(): array
    {
        $out = [];
        foreach (['expenses', (new ExpenseTimeline)->getTable(), 'custody_transactions', 'personal_ledger_transactions'] as $table) {
            $rows = DB::table($table)->get()->map(fn ($r) => (array) $r)->sortBy('id')->values()->toJson();
            $out[$table] = md5($rows).':'.DB::table($table)->count();
        }

        return $out;
    }

    private function assertDelegationRequired(TestResponse $response): void
    {
        $response->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.code', 'EXPENSE_DELEGATION_REQUIRED');
    }

    /**
     * AC-38 (negative half): a cashier without delegation cannot create an expense through ANY of the
     * four entry points, for any payment method, draft or submitted — and nothing is written.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function createEntryPoints(): array
    {
        return [
            'quick-cash custody submit' => ['quick-cash', 'custody', false],
            'quick-cash custody draft' => ['quick-cash', 'custody', true],
            'quick-cash cash draft' => ['quick-cash', 'cash', true],
            'quick-cash cash submit' => ['quick-cash', 'cash', false],
            'single-invoice submit' => ['single-invoice', 'custody', false],
            'single-invoice draft' => ['single-invoice', 'custody', true],
            'grouped-invoice submit' => ['grouped-invoice', 'custody', false],
            'pre-approval submit' => ['pre-approval', 'custody', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('createEntryPoints')]
    public function test_cashier_without_delegation_cannot_create_an_expense(string $route, string $method, bool $draft): void
    {
        Event::fake([ExpenseSubmittedEvent::class]);
        $before = $this->snapshot();

        $this->assertDelegationRequired(
            $this->actingAs($this->cashier, 'sanctum')->postJson("/api/branch-manager/expenses/{$route}", [
                'expense_date' => now()->toDateString(), 'expense_name' => 'x', 'total_amount' => 100,
                'payment_method' => $method, 'is_draft' => $draft, 'items' => [['title' => 'b', 'amount' => 100]],
            ])
        );

        $this->assertSame($before, $this->snapshot(), 'a refused cashier request wrote to the database');
        Event::assertNotDispatched(ExpenseSubmittedEvent::class);
    }

    /** AC-38: a cashier cannot drive the manager's existing expenses either (update/submit/resubmit/delete/attachments). */
    public function test_cashier_without_delegation_cannot_advance_or_alter_a_managers_expense(): void
    {
        $draftId = (string) $this->quickCash($this->bmA1, 120, ['is_draft' => true])->assertSuccessful()->json('data.id');
        $pendingId = (string) $this->quickCash($this->bmA1, 80)->assertSuccessful()->json('data.id');

        Event::fake([ExpenseSubmittedEvent::class]);
        $before = $this->snapshot();
        $c = $this->actingAs($this->cashier, 'sanctum');

        $this->assertDelegationRequired($c->postJson("/api/branch-manager/expenses/{$draftId}/submit"));
        $this->assertDelegationRequired($c->postJson("/api/branch-manager/expenses/{$pendingId}/resubmit"));
        $this->assertDelegationRequired($c->putJson("/api/branch-manager/expenses/quick-cash/{$draftId}", [
            'expense_date' => now()->toDateString(), 'expense_name' => 'y', 'total_amount' => 10, 'payment_method' => 'custody', 'is_draft' => false,
        ]));
        $this->assertDelegationRequired($c->deleteJson("/api/branch-manager/expenses/{$draftId}"));
        $this->assertDelegationRequired($c->deleteJson("/api/branch-manager/expenses/quick-cash/{$draftId}"));
        $this->assertDelegationRequired($c->postJson("/api/branch-manager/expenses/{$draftId}/attachments", []));
        $this->assertDelegationRequired($c->deleteJson("/api/branch-manager/expenses/{$draftId}/attachments"));

        $this->assertSame($before, $this->snapshot(), 'a refused cashier request wrote to the database');
        $this->assertSame('draft', Expense::withoutGlobalScopes()->find($draftId)->status);
        $this->assertSame('pending', Expense::withoutGlobalScopes()->find($pendingId)->status);
        Event::assertNotDispatched(ExpenseSubmittedEvent::class);
    }

    /** Compatibility guard: the Branch Manager's own flow is untouched and still charges the custody once. */
    public function test_branch_manager_flow_is_unchanged(): void
    {
        $id = (string) $this->quickCash($this->bmA1, 150)->assertSuccessful()->json('data.id');

        $rows = CustodyTransaction::withoutGlobalScopes()->where('related_expense_id', $id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('150.00', (string) $rows->first()->amount);
        $this->assertFalse((bool) $rows->first()->is_cash_in);
    }

    /** Compatibility guard: cashiers keep their existing READ access (no delegation is needed to look). */
    public function test_cashier_read_access_is_unchanged(): void
    {
        $this->actingAs($this->cashier, 'sanctum')->getJson('/api/branch-manager/expenses')->assertOk();
    }

    /** The refusal also holds for a cashier of ANOTHER tenant (no cross-branch path around it). */
    public function test_cashier_of_another_tenant_is_refused_the_same_way(): void
    {
        $other = Cashier::factory()->create(['branch_id' => $this->branchB1->id, 'created_by' => $this->bmB1->id]);
        $before = $this->snapshot();

        $this->assertDelegationRequired($this->quickCash($other, 50));

        $this->assertSame($before, $this->snapshot());
    }

    /** EXP-09: Branch Manager can grant and revoke expense delegation to cashiers within their branch. */
    public function test_branch_manager_can_grant_and_revoke_delegation_to_same_branch_cashier(): void
    {
        // 1. Grant delegation
        $response = $this->actingAs($this->bmA1, 'sanctum')
            ->postJson("/api/branch-manager/cashiers/{$this->cashier->id}/expense-delegation/grant");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('branch_cashier_expense_delegations', [
            'branch_id' => $this->branchA1->id,
            'cashier_user_id' => $this->cashier->id,
            'granted_by_user_id' => $this->bmA1->id,
            'is_active' => true,
        ]);

        // 2. Revoke delegation
        $revokeResponse = $this->actingAs($this->bmA1, 'sanctum')
            ->postJson("/api/branch-manager/cashiers/{$this->cashier->id}/expense-delegation/revoke");

        $revokeResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('branch_cashier_expense_delegations', [
            'branch_id' => $this->branchA1->id,
            'cashier_user_id' => $this->cashier->id,
            'is_active' => false,
        ]);
    }

    /** EXP-09: Cross-branch delegation attempts are prohibited with HTTP 403. */
    public function test_branch_manager_cannot_delegate_to_cross_branch_cashier(): void
    {
        $crossBranchCashier = Cashier::factory()->create(['branch_id' => $this->branchB1->id, 'created_by' => $this->bmB1->id]);

        $response = $this->actingAs($this->bmA1, 'sanctum')
            ->postJson("/api/branch-manager/cashiers/{$crossBranchCashier->id}/expense-delegation/grant");

        $response->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.code', 'CROSS_BRANCH_DELEGATION_PROHIBITED');
    }

    /** EXP-09: A cashier with active delegation can create and submit expenses funded by branch custody. */
    public function test_cashier_with_active_delegation_can_create_and_submit_expense(): void
    {
        // Grant active delegation
        $this->actingAs($this->bmA1, 'sanctum')
            ->postJson("/api/branch-manager/cashiers/{$this->cashier->id}/expense-delegation/grant")
            ->assertOk();

        // Cashier submits expense
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/branch-manager/expenses/quick-cash', [
                'expense_date' => now()->toDateString(),
                'expense_name' => 'مصروف مفوض للكاشير',
                'total_amount' => 120,
                'payment_method' => 'custody',
                'is_draft' => false,
                'items' => [['title' => 'شراء مستلزمات', 'amount' => 120]],
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $expenseId = $response->json('data.id');
        $this->assertNotNull($expenseId);

        $expense = Expense::withoutGlobalScopes()->find($expenseId);
        $this->assertNotNull($expense);
        $this->assertSame('pending', $expense->status);
        $this->assertSame((string) $this->bmA1->id, (string) $expense->branch_manager_id);

        // Branch custody was charged once for the delegated expense
        $rows = CustodyTransaction::withoutGlobalScopes()->where('related_expense_id', $expenseId)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('120.00', (string) $rows->first()->amount);
        $this->assertFalse((bool) $rows->first()->is_cash_in);
    }

    /** EXP-09: When delegation is revoked, cashier expense writes are blocked immediately. */
    public function test_cashier_with_revoked_delegation_is_blocked_immediately(): void
    {
        // Grant then revoke
        $this->actingAs($this->bmA1, 'sanctum')
            ->postJson("/api/branch-manager/cashiers/{$this->cashier->id}/expense-delegation/grant")
            ->assertOk();

        $this->actingAs($this->bmA1, 'sanctum')
            ->postJson("/api/branch-manager/cashiers/{$this->cashier->id}/expense-delegation/revoke")
            ->assertOk();

        // Subsequent submission blocked
        $this->assertDelegationRequired(
            $this->actingAs($this->cashier, 'sanctum')->postJson('/api/branch-manager/expenses/quick-cash', [
                'expense_date' => now()->toDateString(),
                'expense_name' => 'محاولة بعد الإلغاء',
                'total_amount' => 50,
                'payment_method' => 'custody',
                'is_draft' => false,
                'items' => [['title' => 'بند', 'amount' => 50]],
            ])
        );
    }
}

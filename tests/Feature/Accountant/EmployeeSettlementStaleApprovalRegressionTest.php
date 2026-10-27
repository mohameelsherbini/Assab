<?php

namespace Tests\Feature\Accountant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\EmployeeSettlementRequest;
use Modules\Branch\Models\Branch;
use Tests\Support\CreatesSettlementRequestsTable;
use Tests\TestCase;

/**
 * BE-09 P1/P2 regression coverage for the audit-confirmed defects
 * (REVIEWER_EXECUTED 2026-10-26, Mahmoud's scenario):
 *
 * P1 — approving a stale settlement request must fail safely without
 *      introducing a new financial movement or recreating debt.
 * P2 — same idempotency key + different payload must return the existing
 *      contract's 409 conflict; cross-employee replay must not leak.
 */
class EmployeeSettlementStaleApprovalRegressionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSettlementRequestsTable;

    private AsabCompany $company;
    private Branch $branch;
    private AsabUser $accountant;
    private AsabUser $headAccountant;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSettlementRequestsTable();

        $this->company = AsabCompany::create(['name' => 'Stale Reg Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['name' => 'فرع رئيسي', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = $this->createUser('acc@stale.test', 'accountant');
        $this->headAccountant = $this->createUser('head@stale.test', 'head');

        $this->employee = $this->createEmployee('1001', 'أحمد كاشير');
    }

    /** Mahmoud's scenario: -5000 balance, two pending 5000 requests. */
    public function test_stale_second_approval_is_rejected_without_new_movement(): void
    {
        $this->assertBalance(-5000);

        $first = $this->pendingRequest(5000);
        $second = $this->pendingRequest(5000);

        $res1 = $this->approve($first);
        $res1->assertOk();
        $this->assertSame(0, $res1->json('balanceHalalas'));

        $res2 = $this->approve($second);
        $res2->assertStatus(409);

        // Exactly one movement, in the debt-clearing direction (credit); no new debt.
        $this->assertSame(1, $this->settlementMovementCount());
        $this->assertSame(1, $this->movementDirectionSum());
        $this->assertBalance(0);
        $this->assertSame('approved', $first->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);
    }

    public function test_approval_rejected_when_outstanding_balance_shrank_below_amount(): void
    {
        $request = $this->pendingRequest(5000);

        // 3000 halalas credited back after the request was created → -2000 outstanding.
        // Category is deliberately NOT 'settlement' so the counters below only
        // track movements posted by the approval flow itself.
        $this->travel(1)->second();
        EmployeeMovement::create([
            'employee_id' => $this->employee->id,
            'movement_type' => 'credit',
            'amount' => 3000,
            'category' => 'advance',
            'description' => 'دفعات لاحقة',
            'movement_date' => now(),
            'created_by_id' => $this->accountant->id,
        ]);

        $this->approve($request)->assertStatus(409);
        $this->assertSame(0, $this->settlementMovementCount());
        $this->assertBalance(-2000);
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_approval_rejected_when_balance_direction_flipped(): void
    {
        $request = $this->pendingRequest(5000);

        // A large credit flips the balance to +7000: approval must not post a debit.
        $this->travel(1)->second();
        EmployeeMovement::create([
            'employee_id' => $this->employee->id,
            'movement_type' => 'credit',
            'amount' => 12000,
            'category' => 'advance',
            'description' => 'تسوية عكسية',
            'movement_date' => now(),
            'created_by_id' => $this->accountant->id,
        ]);

        $this->approve($request)->assertStatus(409);
        $this->assertSame(0, $this->settlementMovementCount());
        $this->assertBalance(7000);
    }

    public function test_duplicate_approval_returns_conflict_without_extra_movement(): void
    {
        $request = $this->pendingRequest(5000);

        $this->approve($request)->assertOk();
        $this->approve($request)->assertStatus(409);

        $this->assertSame(1, $this->settlementMovementCount());
        $this->assertBalance(0);
    }

    /** Halala precision: a 1-halala settlement moves the ledger by exactly 1. */
    public function test_one_halala_settlement_posts_exact_amount(): void
    {
        $request = $this->pendingRequest(1);

        $this->approve($request)->assertOk();
        $this->assertSame(1, $this->settlementMovementCount());
        $this->assertSame(1, $this->movementDirectionSum());
        $this->assertBalance(-4999);
    }

    public function test_body_idempotency_key_with_changed_payload_returns_conflict(): void
    {
        $key = (string) Str::uuid();

        $res1 = $this->settleViaBody($this->employee, ['idempotencyKey' => $key, 'amountHalalas' => 1000, 'reason' => 'سبب موحد']);
        $res1->assertStatus(201);

        $res2 = $this->settleViaBody($this->employee, ['idempotencyKey' => $key, 'amountHalalas' => 2000, 'reason' => 'سبب موحد']);
        $res2->assertStatus(409);

        $this->assertSame(1, EmployeeSettlementRequest::where('idempotency_key', $key)->count());
        $this->assertSame(0, $this->settlementMovementCount());
    }

    public function test_body_idempotency_key_same_payload_replays_existing_request(): void
    {
        $key = (string) Str::uuid();

        $res1 = $this->settleViaBody($this->employee, ['idempotencyKey' => $key, 'amountHalalas' => 1000, 'reason' => 'سبب موحد']);
        $res1->assertStatus(201);

        $res2 = $this->settleViaBody($this->employee, ['idempotencyKey' => $key, 'amountHalalas' => 1000, 'reason' => 'سبب موحد']);
        $res2->assertStatus(201);
        $this->assertSame($res1->json('id'), $res2->json('id'));
        $this->assertSame(1, EmployeeSettlementRequest::where('idempotency_key', $key)->count());
    }

    public function test_header_key_used_for_another_employee_does_not_leak_or_create(): void
    {
        $other = $this->createEmployee('2002', 'سعيد كاشير', -4000);
        $key = (string) Str::uuid();

        $resA = $this->settleViaBody($this->employee, ['amountHalalas' => 5000, 'reason' => 'تسوية أ'], $key);
        $resA->assertStatus(201);

        $resB = $this->settleViaBody($other, ['amountHalalas' => 4000, 'reason' => 'تسوية ب'], $key);
        $resB->assertStatus(409);

        // No leak of A's resource and no new request for B under A's key.
        $this->assertNotSame($resA->json('id'), $resB->json('id'));
        $this->assertSame(1, EmployeeSettlementRequest::where('idempotency_key', $key)->count());
        $this->assertSame(1, EmployeeSettlementRequest::where('employee_id', $this->employee->id)->count());
        $this->assertSame(0, EmployeeSettlementRequest::where('employee_id', $other->id)->count());
    }

    public function test_same_key_in_another_tenant_is_isolated(): void
    {
        $company2 = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand2 = AsabBrand::create(['company_id' => $company2->id, 'name' => 'براند ٢', 'status' => 'active']);
        $branch2 = Branch::factory()->create(['name' => 'فرع ٢', 'asab_brand_id' => $brand2->id, 'asab_company_id' => $company2->id]);
        $acc2 = $this->createUser('acc2@stale.test', 'accountant', $company2);
        $emp2 = Employee::create([
            'company_id' => $company2->id,
            'branch_id' => $branch2->id,
            'emp_number' => '3001',
            'name' => 'كاشير شركة أخرى',
            'role' => 'كاشير',
            'monthly_salary' => 90000,
            'status' => 'active',
        ]);
        EmployeeMovement::create([
            'employee_id' => $emp2->id,
            'movement_type' => 'debit',
            'amount' => 2500,
            'category' => 'advance',
            'description' => 'سلفة',
            'movement_date' => now(),
            'created_by_id' => $acc2->id,
        ]);

        $key = (string) Str::uuid();
        $res1 = $this->settleViaBody($this->employee, ['amountHalalas' => 5000, 'reason' => 'شركة أ'], $key);
        $res1->assertStatus(201);

        $res2 = $this->actingAs($acc2, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/company/me/employees/{$emp2->id}/settle-balance", [
                'amountHalalas' => 2500,
                'reason' => 'شركة ب',
            ]);

        // A Header key is bound to its first actor: reuse by another user (even
        // another tenant) must conflict without disclosing or creating anything.
        // Raw table queries: the model carries a tenant global scope that would
        // filter to the last acting tenant (company 2) inside this test.
        $res2->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', $res2->json('error.code'));
        $this->assertNotSame($res1->json('id'), $res2->json('id'));
        $this->assertSame(1, DB::table('asab_employee_settlement_requests')->where('idempotency_key', $key)->count());
        $this->assertSame(0, DB::table('asab_employee_settlement_requests')->where('employee_id', $emp2->id)->count());
    }

    // ------------------------------------------------------------------

    private function createEmployee(string $empNumber, string $name, ?int $debit = 5000): Employee
    {
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'emp_number' => $empNumber,
            'name' => $name,
            'role' => 'كاشير',
            'monthly_salary' => 100000,
            'status' => 'active',
        ]);

        if ($debit !== null) {
            EmployeeMovement::create([
                'employee_id' => $employee->id,
                'movement_type' => 'debit',
                'amount' => $debit,
                'category' => 'advance',
                'description' => 'سلفة مؤقتة',
                'movement_date' => now(),
                'created_by_id' => $this->accountant->id,
            ]);
        }

        return $employee;
    }

    private function createUser(string $email, string $role, ?AsabCompany $company = null): AsabUser
    {
        $company = $company ?? $this->company;
        $u = AsabUser::create([
            'company_id' => $company->id,
            'name' => $role,
            'email' => $email,
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $u->id, 'role_key' => $role, 'scope' => 'all']);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $u->id, 'role_key' => $role, 'status' => 'active']);

        return $u;
    }

    private function pendingRequest(int $amount): EmployeeSettlementRequest
    {
        return EmployeeSettlementRequest::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'amount_halalas' => $amount,
            'reason' => 'سداد نقدي',
            'status' => 'pending',
            'created_by_user_id' => $this->accountant->id,
        ]);
    }

    private function settleViaBody(Employee $employee, array $payload, ?string $headerKey = null)
    {
        $call = $this->actingAs($this->accountant, 'sanctum');
        if ($headerKey !== null) {
            $call = $call->withHeader('Idempotency-Key', $headerKey);
        }

        return $call->postJson("/api/v1/company/me/employees/{$employee->id}/settle-balance", $payload);
    }

    private function approve(EmployeeSettlementRequest $request)
    {
        return $this->actingAs($this->headAccountant, 'sanctum')
            ->postJson("/api/v1/company/me/employee-settlements/{$request->id}/approve");
    }

    private function settlementMovementCount(): int
    {
        return EmployeeMovement::where('employee_id', $this->employee->id)->where('category', 'settlement')->count();
    }

    /** +1 when the only settlement movement is a credit (debt clearing), -1 for debit. */
    private function movementDirectionSum(): int
    {
        $m = EmployeeMovement::where('employee_id', $this->employee->id)->where('category', 'settlement')->get();

        return (int) $m->sum(fn ($row) => $row->movement_type === 'credit' ? 1 : -1);
    }

    private function assertBalance(int $expected): void
    {
        $balance = (int) EmployeeMovement::where('employee_id', $this->employee->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN movement_type = 'credit' THEN amount ELSE -amount END), 0) as bal")
            ->value('bal');
        $this->assertSame($expected, $balance);
    }
}

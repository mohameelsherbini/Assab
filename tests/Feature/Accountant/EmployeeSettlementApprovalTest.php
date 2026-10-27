<?php

namespace Tests\Feature\Accountant;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * BE-09 / STMT-02: Mandatory settlement reason, pending status, and Head Accountant approval.
 * BE-14 / STMT-01: Statement status badge (under_review vs approved).
 * BE-15 / AUD-04: Idempotency check.
 */
class EmployeeSettlementApprovalTest extends TestCase
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

        $this->company = AsabCompany::create(['name' => 'Settlement Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['name' => 'فرع رئيسي', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = $this->createUser('acc@settle.test', 'accountant');
        $this->headAccountant = $this->createUser('head@settle.test', 'head');

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'emp_number' => '1001',
            'name' => 'أحمد كاشير',
            'role' => 'كاشير',
            'monthly_salary' => 100000,
            'status' => 'active',
        ]);

        // Create an initial deficit / debt for employee (-5000 halalas)
        EmployeeMovement::create([
            'employee_id' => $this->employee->id,
            'movement_type' => 'debit',
            'amount' => 5000,
            'category' => 'advance',
            'description' => 'سلفة مؤقتة',
            'movement_date' => now(),
            'created_by_id' => $this->accountant->id,
        ]);
    }

    private function createUser(string $email, string $role): AsabUser
    {
        $u = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => $role,
            'email' => $email,
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $u->id,
            'role_key' => $role,
            'scope' => 'all',
        ]);
        // BE-01 fail-closed: explicit active company membership per user.
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $u->id, 'role_key' => $role, 'status' => 'active']);

        return $u;
    }

    public function test_missing_or_empty_reason_is_rejected_with_422(): void
    {
        // 1. Missing reason field
        $res1 = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/employees/{$this->employee->id}/settle-balance", [
                'amountHalalas' => 5000,
            ]);
        $res1->assertStatus(422);

        // 2. Empty string reason
        $res2 = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/employees/{$this->employee->id}/settle-balance", [
                'amountHalalas' => 5000,
                'reason' => '   ',
            ]);
        $res2->assertStatus(422);

        // Ensure balance is untouched and no request or movement was recorded
        $this->assertSame(0, EmployeeSettlementRequest::count());
        $this->assertSame(0, EmployeeMovement::where('category', 'settlement')->count());
    }

    public function test_valid_settlement_creates_pending_request_without_moving_balance(): void
    {
        $res = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/employees/{$this->employee->id}/settle-balance", [
                'amountHalalas' => 5000,
                'reason' => 'سداد نقدي تم تسليمه في الفرع',
            ]);

        $res->assertStatus(201);
        $this->assertSame('pending', $res->json('status'));
        $this->assertSame(5000, $res->json('amountHalalas'));
        $this->assertSame('سداد نقدي تم تسليمه في الفرع', $res->json('reason'));

        // Standing balance MUST still be -5000 halalas (fail-closed pending approval)
        $st = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->employee->id}/movements")
            ->assertOk()->json();
        $this->assertSame(-5000, $st['balance']);

        // Check BE-14 status badge on statement
        $this->assertSame('under_review', $st['settlementStatus'] ?? null);

        // No actual settlement movement in ledger yet
        $this->assertSame(0, EmployeeMovement::where('category', 'settlement')->count());
    }

    public function test_unauthorized_accountant_cannot_approve_settlement(): void
    {
        $settlement = EmployeeSettlementRequest::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'amount_halalas' => 5000,
            'reason' => 'سداد نقدي',
            'status' => 'pending',
            'created_by_user_id' => $this->accountant->id,
        ]);

        $res = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/employee-settlements/{$settlement->id}/approve");

        $res->assertStatus(403);
        $this->assertSame('pending', $settlement->fresh()->status);
        $this->assertSame(0, EmployeeMovement::where('category', 'settlement')->count());
    }

    public function test_head_accountant_can_approve_settlement_and_post_movement(): void
    {
        $settlement = EmployeeSettlementRequest::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'amount_halalas' => 5000,
            'reason' => 'سداد نقدي معتمد بالفرع',
            'status' => 'pending',
            'created_by_user_id' => $this->accountant->id,
        ]);

        $res = $this->actingAs($this->headAccountant, 'sanctum')
            ->postJson("/api/v1/company/me/employee-settlements/{$settlement->id}/approve");

        $res->assertOk();
        $this->assertSame('approved', $res->json('status'));
        $this->assertSame(0, $res->json('balanceHalalas'));

        // DB state: status updated, reviewer tracked, movement posted
        $fresh = $settlement->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($this->headAccountant->id, $fresh->reviewed_by_user_id);
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertNotNull($fresh->movement_id);

        $movement = EmployeeMovement::findOrFail($fresh->movement_id);
        $this->assertSame('credit', $movement->movement_type);
        $this->assertSame(5000, $movement->amount);
        $this->assertSame('settlement', $movement->category);

        // Statement now shows balance cleared and status approved
        $st = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->employee->id}/movements")
            ->assertOk()->json();
        $this->assertSame(0, $st['balance']);
        $this->assertSame('approved', $st['settlementStatus'] ?? null);
    }

    public function test_head_accountant_can_reject_settlement_without_posting_movement(): void
    {
        $settlement = EmployeeSettlementRequest::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'amount_halalas' => 5000,
            'reason' => 'سداد بدون إيصال',
            'status' => 'pending',
            'created_by_user_id' => $this->accountant->id,
        ]);

        $res = $this->actingAs($this->headAccountant, 'sanctum')
            ->postJson("/api/v1/company/me/employee-settlements/{$settlement->id}/reject", [
                'rejectionReason' => 'الإيصال غير مرفق',
            ]);

        $res->assertOk();
        $this->assertSame('rejected', $res->json('status'));

        $fresh = $settlement->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('الإيصال غير مرفق', $fresh->rejection_reason);
        $this->assertSame($this->headAccountant->id, $fresh->reviewed_by_user_id);

        // Balance remains unchanged and zero settlement movements posted
        $this->assertSame(0, EmployeeMovement::where('category', 'settlement')->count());
    }

    public function test_idempotent_settlement_submission_returns_cached_response(): void
    {
        $key = (string) Str::uuid();

        $res1 = $this->actingAs($this->accountant, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/company/me/employees/{$this->employee->id}/settle-balance", [
                'amountHalalas' => 5000,
                'reason' => 'تسوية مكررة بالتجربة',
            ]);
        $res1->assertStatus(201);
        $id1 = $res1->json('id');

        // Second submission with same Idempotency-Key
        $res2 = $this->actingAs($this->accountant, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/company/me/employees/{$this->employee->id}/settle-balance", [
                'amountHalalas' => 5000,
                'reason' => 'تسوية مكررة بالتجربة',
            ]);
        $res2->assertStatus(201);
        $this->assertSame($id1, $res2->json('id'));

        // Exactly one settlement request created
        $this->assertSame(1, EmployeeSettlementRequest::where('idempotency_key', $key)->count());
    }
}

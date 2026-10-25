<?php

namespace Tests\Feature\Accountant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CashCustody;
use Modules\Admin\Models\CashTransaction;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * BE-10 / FUND-04 / FUND-06:
 * Interim policy: Accountant and Head Accountant must be prohibited (403 Forbidden)
 * from manually writing, approving, or settling cash custody transactions.
 * Custody balances must remain untouched. Read (GET) operations must remain functional.
 */
class CashCustodyRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;
    private Branch $branch;
    private AsabUser $accountant;
    private AsabUser $head;
    private CashCustody $custody;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Custody Guard Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Brand Test',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branch = Branch::factory()->create([
            'name' => 'Branch Test',
            'asab_brand_id' => $brand->id,
            'asab_company_id' => $this->company->id,
        ]);

        $this->accountant = $this->createUser('acc@guard.test', 'accountant');
        $this->head = $this->createUser('head@guard.test', 'head');

        $this->custody = CashCustody::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'custodian_name' => 'Custodian A',
            'amount' => 500000,
            'used' => 100000,
            'warning_threshold' => 200000,
            'critical_threshold' => 100000,
            'status' => 'normal',
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
            'branch_ids' => [],
        ]);

        return $u;
    }

    public function test_accountant_and_head_cannot_manually_add_transaction(): void
    {
        $initialAmount = $this->custody->fresh()->amount;
        $initialUsed = $this->custody->fresh()->used;

        // Accountant attempting credit transaction on company surface
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custody->id}/transactions", [
                'txnType' => 'credit',
                'amount' => 100000,
                'source' => 'treasury',
            ])
            ->assertStatus(403);

        // Accountant attempting debit transaction on accountant prefix surface
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/cash-custody/{$this->custody->id}/transactions", [
                'txnType' => 'debit',
                'amount' => 50000,
            ])
            ->assertStatus(403);

        // Head accountant attempting credit transaction
        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custody->id}/transactions", [
                'txnType' => 'credit',
                'amount' => 100000,
                'source' => 'treasury',
            ])
            ->assertStatus(403);

        // Verify balance remained unmutated
        $fresh = $this->custody->fresh();
        $this->assertSame($initialAmount, $fresh->amount);
        $this->assertSame($initialUsed, $fresh->used);
        $this->assertSame(0, CashTransaction::where('custody_id', $this->custody->id)->count());
    }

    public function test_accountant_and_head_cannot_approve_transactions_or_settle(): void
    {
        $txn = CashTransaction::create([
            'custody_id' => $this->custody->id,
            'txn_type' => 'debit',
            'amount' => 50000,
            'description' => 'Pre-existing pending',
            'txn_date' => now(),
            'status' => 'pending',
            'source' => 'manual',
            'created_by_id' => $this->accountant->id,
        ]);

        $initialAmount = $this->custody->fresh()->amount;
        $initialUsed = $this->custody->fresh()->used;

        // Accountant attempting approve
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custody->id}/transactions/{$txn->id}/approve")
            ->assertStatus(403);

        // Head accountant attempting approve
        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custody->id}/transactions/{$txn->id}/approve")
            ->assertStatus(403);

        // Accountant attempting settle
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custody->id}/settle", [
                'newDepositHalalas' => 200000,
            ])
            ->assertStatus(403);

        // Head accountant attempting settle
        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custody->id}/settle", [])
            ->assertStatus(403);

        // Balances and transaction status remain unmutated
        $fresh = $this->custody->fresh();
        $this->assertSame($initialAmount, $fresh->amount);
        $this->assertSame($initialUsed, $fresh->used);
        $this->assertSame('pending', $txn->fresh()->status);
    }

    public function test_read_operations_remain_functional(): void
    {
        // GET custody list returns 200
        $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/company/me/cash-custody')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->custody->id);

        // GET custody transactions returns 200
        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/cash-custody/{$this->custody->id}/transactions")
            ->assertOk();

        // GET custody export returns 200
        $this->actingAs($this->accountant, 'sanctum')
            ->get('/api/v1/company/me/cash-custody/export?format=csv')
            ->assertOk();
    }
}

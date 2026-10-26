<?php

namespace Tests\Feature\Company;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRole;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * Universal Role Archival, Token Revocation & Security Notifications (BE-18, MEM-01..06)
 *
 * Covers:
 * - Accountant, Branch Manager, Procurement Manager, Head Accountant.
 * - Archiving (soft retention with status='archived') instead of hard row deletion.
 * - Instant revocation of active Sanctum personal access tokens upon suspension/archival.
 * - Login & authentication blocking for inactive/archived memberships.
 * - Safeguard against archiving/suspending the last company administrator.
 */
class UniversalRoleArchivalTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabUser $adminUser;

    private AsabBrand $brand;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Universal Test Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $this->adminUser = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Company Admin',
            'email' => 'admin@universal.test',
            'password' => Hash::make('secret123'),
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $this->adminUser->id,
            'role_key' => 'company-admin',
            'scope' => 'all',
        ]);

        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->adminUser->id,
            'role_key' => 'company-admin',
            'status' => 'active',
        ]);

        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Brand Alfa',
            'abbr' => 'BA',
            'status' => 'active',
            'sub_status' => 'active',
            'plan' => 'ذهبي',
            'modules' => ['sales', 'inventory', 'expenses'],
        ]);

        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'name' => 'Branch Alfa 1',
        ]);
    }

    private function createMember(string $roleKey, string $email): array
    {
        $user = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => ucfirst($roleKey).' User',
            'email' => $email,
            'password' => Hash::make('password123'),
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => $roleKey,
            'scope' => 'brand',
            'brand_ids' => [$this->brand->id],
            'branch_ids' => [$this->branch->id],
        ]);

        $cu = CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'role_key' => $roleKey,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        return [$user, $cu, $token];
    }

    /**
     * MEM-01..06: Archiving a member (destroy) updates status to archived, retains the record, and revokes tokens.
     * Tests across operational roles: accountant, branch, procurement.
     *
     * @return array<string, array{0: string}>
     */
    public static function operationalRoles(): array
    {
        return [
            'Accountant role' => ['accountant'],
            'Branch Manager role' => ['branch'],
            'Procurement Manager role' => ['procurement'],
            'Head Accountant role' => ['head'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('operationalRoles')]
    public function test_archiving_member_preserves_record_and_revokes_tokens(string $role): void
    {
        [$user, $cu, $token] = $this->createMember($role, "{$role}@universal.test");

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);

        // Company admin calls DELETE to archive member
        $res = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/company/me/users/{$cu->id}");

        $res->assertNoContent();

        // 1. Row is NOT hard-deleted from database
        $this->assertDatabaseHas('asab_company_users', [
            'id' => $cu->id,
            'status' => 'archived',
        ]);

        // 2. Personal access tokens are completely revoked
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);

        // 3. User cannot log in anymore
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $loginRes->assertForbidden()
            ->assertJsonPath('error.code', 'USER_INACTIVE');
    }

    /** Suspending a member (toggleStatus) revokes tokens and prevents subsequent login until reactivated. */
    public function test_suspending_member_revokes_tokens_and_reactivation_restores_login(): void
    {
        [$user, $cu, $token] = $this->createMember('accountant', 'acc.toggle@universal.test');

        // Suspend member
        $res = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/company/me/users/{$cu->id}/toggle-status");

        $res->assertOk()->assertJsonPath('status', 'inactive');

        // Token revoked
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);

        // Login denied
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertForbidden()->assertJsonPath('error.code', 'USER_INACTIVE');

        // Reactivate member
        $reactivateRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/company/me/users/{$cu->id}/toggle-status");

        $reactivateRes->assertOk()->assertJsonPath('status', 'active');

        // Login succeeds again
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();
    }

    /** Last active company admin cannot be disabled or archived. */
    public function test_last_active_company_admin_is_protected(): void
    {
        $adminCu = CompanyUser::where('user_id', $this->adminUser->id)->first();

        // Attempt to archive last admin
        $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/company/me/users/{$adminCu->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LAST_ACTIVE_ADMIN');

        // Attempt to suspend last admin
        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/company/me/users/{$adminCu->id}/toggle-status")
            ->assertStatus(409);
    }
}

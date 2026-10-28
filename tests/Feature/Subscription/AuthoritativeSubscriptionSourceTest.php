<?php

namespace Tests\Feature\Subscription;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSubscription;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\BillingInvoice;
use Modules\Admin\Models\CompanySubscription;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Plan;
use Tests\TestCase;

/**
 * BE-11 / SUB-01: Single authoritative subscription store and discontinuation of auto-billing invoices.
 *
 * Requirements:
 * 1. Both monthly and annual company provisioning create an active subscription so GET /company/me/subscription returns 200.
 * 2. No BillingInvoice is created upon provisioning (annual or monthly).
 * 3. Self-service upgrade does not generate proration BillingInvoice.
 * 4. Company provisioning rolls back completely on missing/invalid plan.
 */
class AuthoritativeSubscriptionSourceTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $platformAdmin;
    private Plan $basicPlan;
    private Plan $proPlan;
    private Plan $enterprisePlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = AsabUser::create([
            'name' => 'Platform Admin',
            'email' => 'admin@platform.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->platformAdmin->id,
            'role_key' => 'admin',
            'scope' => 'all',
        ]);

        $this->basicPlan = Plan::create([
            'code' => 'basic',
            'name_ar' => 'باقة أساسية',
            'name_en' => 'Basic Plan',
            'price_monthly' => 29900,
            'price_annual' => 299000,
            'max_branches' => 2,
            'max_users' => 5,
            'modules_included' => [],
            'status' => 'active',
            'sort_order' => 1,
        ]);

        $this->proPlan = Plan::create([
            'code' => 'professional',
            'name_ar' => 'باقة متقدمة',
            'name_en' => 'Professional Plan',
            'price_monthly' => 59900,
            'price_annual' => 599000,
            'max_branches' => 10,
            'max_users' => 25,
            'modules_included' => [],
            'status' => 'active',
            'sort_order' => 2,
        ]);

        $this->enterprisePlan = Plan::create([
            'code' => 'enterprise',
            'name_ar' => 'باقة مؤسسات',
            'name_en' => 'Enterprise Plan',
            'price_monthly' => 129900,
            'price_annual' => 1299000,
            'max_branches' => 100,
            'max_users' => 500,
            'modules_included' => [],
            'status' => 'active',
            'sort_order' => 3,
        ]);
    }

    public function test_monthly_company_provisioning_creates_active_subscription_and_zero_invoices(): void
    {
        $payload = [
            'name' => 'Monthly Gourmet Co',
            'plan' => 'Basic',
            'billingCycle' => 'monthly',
            'contactEmail' => 'admin@monthly-gourmet.test',
            'contactName' => 'Owner Monthly',
        ];

        $res = $this->actingAs($this->platformAdmin, 'sanctum')
            ->postJson('/api/v1/admin/companies', $payload);

        $res->assertCreated();
        $companyId = $res->json('id');
        $this->assertNotNull($companyId);

        // Rule: No billing invoice on creation
        $this->assertSame(0, BillingInvoice::where('company_id', $companyId)->count());

        // Rule: Company admin can immediately query GET /company/me/subscription and get 200
        $adminUser = AsabUser::where('email', 'admin@monthly-gourmet.test')->firstOrFail();
        $subRes = $this->actingAs($adminUser, 'sanctum')
            ->getJson('/api/v1/company/me/subscription');

        $subRes->assertOk();
        $this->assertSame('active', $subRes->json('subscription.status'));
        $this->assertSame('basic', $subRes->json('plan.code'));
        $this->assertSame('monthly', $subRes->json('subscription.billingCycle'));
    }

    public function test_annual_company_provisioning_creates_active_subscription_with_zero_billing_invoices(): void
    {
        $payload = [
            'name' => 'Annual Gourmet Co',
            'plan' => 'Professional',
            'billingCycle' => 'annual',
            'contactEmail' => 'admin@annual-gourmet.test',
            'contactName' => 'Owner Annual',
        ];

        $res = $this->actingAs($this->platformAdmin, 'sanctum')
            ->postJson('/api/v1/admin/companies', $payload);

        $res->assertCreated();
        $companyId = $res->json('id');
        $this->assertNotNull($companyId);

        // Rule: No billing invoice on annual creation (discontinue auto-invoicing)
        $this->assertSame(0, BillingInvoice::where('company_id', $companyId)->count());

        // Rule: Subscription exists and has annual cycle
        $adminUser = AsabUser::where('email', 'admin@annual-gourmet.test')->firstOrFail();
        $subRes = $this->actingAs($adminUser, 'sanctum')
            ->getJson('/api/v1/company/me/subscription');

        $subRes->assertOk();
        $this->assertSame('active', $subRes->json('subscription.status'));
        $this->assertSame('professional', $subRes->json('plan.code'));
        $this->assertSame('annual', $subRes->json('subscription.billingCycle'));
    }

    public function test_subscription_upgrade_does_not_create_proration_billing_invoice(): void
    {
        $company = AsabCompany::create([
            'name' => 'Upgrade Test Co',
            'plan' => 'Basic',
            'status' => 'active',
        ]);
        $sub = CompanySubscription::create([
            'company_id' => $company->id,
            'plan_id' => $this->basicPlan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
            'start_date' => now()->subDays(10),
            'auto_renew' => true,
        ]);
        $adminUser = AsabUser::create([
            'company_id' => $company->id,
            'name' => 'Upgrade Admin',
            'email' => 'admin@upgrade-test.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $adminUser->id,
            'role_key' => 'company-admin',
            'scope' => 'all',
        ]);
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $adminUser->id,
            'role_key' => 'company-admin',
            'status' => 'active',
        ]);

        $res = $this->actingAs($adminUser, 'sanctum')->postJson('/api/v1/company/me/subscription/upgrade', [
            'targetPlanCode' => 'professional',
            'billingCycle' => 'annual',
            'applyImmediately' => true,
        ]);

        $res->assertOk();
        $this->assertSame($this->proPlan->id, $res->json('subscription.planId'));
        $this->assertNull($res->json('prorationInvoice'));

        // Rule: Invoices table must remain empty
        $this->assertSame(0, BillingInvoice::where('company_id', $company->id)->count());
    }

    public function test_provisioning_with_missing_catalog_plan_rolls_back_entire_transaction(): void
    {
        // Delete enterprise plan so it does not exist in catalog
        $this->enterprisePlan->delete();

        $payload = [
            'name' => 'Doomed Enterprise Co',
            'plan' => 'Enterprise',
            'billingCycle' => 'annual',
            'contactEmail' => 'admin@doomed-enterprise.test',
        ];

        // Should fail due to missing plan in catalog
        $res = $this->actingAs($this->platformAdmin, 'sanctum')
            ->postJson('/api/v1/admin/companies', $payload);

        $res->assertStatus(422);

        // Entire transaction must be rolled back: company and user must NOT exist
        $this->assertDatabaseMissing('asab_companies', ['name' => 'Doomed Enterprise Co']);
        $this->assertDatabaseMissing('asab_users', ['email' => 'admin@doomed-enterprise.test']);
    }

    public function test_current_fails_closed_when_legacy_plan_cannot_be_resolved_in_catalog(): void
    {
        $company = AsabCompany::create([
            'name' => 'Legacy Unresolvable Plan Co',
            'plan' => 'UnknownLegacyTier',
            'status' => 'active',
        ]);

        AsabSubscription::create([
            'company_id' => $company->id,
            'plan' => 'UnknownLegacyTier',
            'status' => 'active',
            'expires_at' => now()->addMonths(3),
            'days_left' => 90,
            'monthly_price' => 50000,
            'reminder_enabled' => true,
        ]);

        $adminUser = AsabUser::create([
            'company_id' => $company->id,
            'name' => 'Legacy Admin',
            'email' => 'admin@unresolvable-legacy.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $adminUser->id,
            'role_key' => 'company-admin',
            'scope' => 'all',
        ]);
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $adminUser->id,
            'role_key' => 'company-admin',
            'status' => 'active',
        ]);

        // When legacy plan cannot be mapped to any catalog plan, fail closed: do NOT assign first active plan arbitrarily
        $res = $this->actingAs($adminUser, 'sanctum')->getJson('/api/v1/company/me/subscription');

        $res->assertStatus(404);
        $this->assertSame(0, CompanySubscription::where('company_id', $company->id)->count());
    }

    public function test_deferred_subscription_change_preserves_legacy_plan_until_effective(): void
    {
        $company = AsabCompany::create([
            'name' => 'Deferred Upgrade Co',
            'plan' => 'Basic',
            'status' => 'active',
        ]);
        $sub = CompanySubscription::create([
            'company_id' => $company->id,
            'plan_id' => $this->basicPlan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
            'start_date' => now()->subDays(10),
            'auto_renew' => true,
        ]);
        $asabSub = AsabSubscription::create([
            'company_id' => $company->id,
            'plan' => $this->basicPlan->name_ar,
            'status' => 'active',
            'expires_at' => now()->addDays(20),
            'days_left' => 20,
            'monthly_price' => $this->basicPlan->price_monthly,
            'reminder_enabled' => true,
        ]);
        $adminUser = AsabUser::create([
            'company_id' => $company->id,
            'name' => 'Deferred Admin',
            'email' => 'admin@deferred-upgrade.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $adminUser->id,
            'role_key' => 'company-admin',
            'scope' => 'all',
        ]);
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $adminUser->id,
            'role_key' => 'company-admin',
            'status' => 'active',
        ]);

        // Case A: Deferred change (applyImmediately = false) must preserve the current plan in both stores
        $resDeferred = $this->actingAs($adminUser, 'sanctum')->postJson('/api/v1/company/me/subscription/upgrade', [
            'targetPlanCode' => 'professional',
            'billingCycle' => 'annual',
            'applyImmediately' => false,
        ]);

        $resDeferred->assertOk();
        $this->assertSame($this->basicPlan->id, $sub->fresh()->plan_id);
        $this->assertSame($this->basicPlan->name_ar, $asabSub->fresh()->plan);

        // Case B: Immediate change (applyImmediately = true) updates both stores
        $resImmediate = $this->actingAs($adminUser, 'sanctum')->postJson('/api/v1/company/me/subscription/upgrade', [
            'targetPlanCode' => 'professional',
            'billingCycle' => 'annual',
            'applyImmediately' => true,
        ]);

        $resImmediate->assertOk();
        $this->assertSame($this->proPlan->id, $sub->fresh()->plan_id);
        $this->assertSame($this->proPlan->name_ar, $asabSub->fresh()->plan);
    }

    public function test_platform_admin_subscription_modification_is_reflected_in_company_api_and_legacy_projection(): void
    {
        // 1. Provision company with Basic plan via Platform Admin
        $createRes = $this->actingAs($this->platformAdmin, 'sanctum')->postJson('/api/v1/admin/companies', [
            'name' => 'Admin Upgrade Gourmet Co',
            'plan' => 'Basic',
            'billingCycle' => 'monthly',
            'contactEmail' => 'owner@admin-upgrade.test',
            'contactName' => 'Owner Gourmet',
        ]);
        $createRes->assertCreated();
        $companyId = $createRes->json('id');
        $this->assertNotNull($companyId);

        $adminUser = AsabUser::where('email', 'owner@admin-upgrade.test')->firstOrFail();

        // Verify initial state from Company API
        $subRes1 = $this->actingAs($adminUser, 'sanctum')->getJson('/api/v1/company/me/subscription');
        $subRes1->assertOk();
        $this->assertSame('basic', $subRes1->json('plan.code'));
        $this->assertSame('active', $subRes1->json('subscription.status'));

        // Verify legacy projection exists
        $legacySub = AsabSubscription::where('company_id', $companyId)->first();
        $this->assertNotNull($legacySub);
        $this->assertSame('باقة أساسية', $legacySub->plan);

        // 2. Platform Admin upgrades the company to Professional
        $upgradeRes = $this->actingAs($this->platformAdmin, 'sanctum')
            ->postJson("/api/v1/admin/companies/{$companyId}/upgrade", [
                'plan' => 'Professional',
            ]);
        $upgradeRes->assertOk();

        // 3. Verify single authoritative store and zero auto-invoices
        $this->assertSame(0, BillingInvoice::where('company_id', $companyId)->count());
        $authSub = CompanySubscription::where('company_id', $companyId)->first();
        $this->assertNotNull($authSub);
        $this->assertSame($this->proPlan->id, $authSub->plan_id);

        // 4. Verify legacy projection was synchronized and is not stale
        $legacySubFresh = $legacySub->fresh();
        $this->assertSame('باقة متقدمة', $legacySubFresh->plan);

        // 5. CRITICAL: Company API must immediately reflect the Platform Admin's modification (not hidden!)
        $subRes2 = $this->actingAs($adminUser, 'sanctum')->getJson('/api/v1/company/me/subscription');
        $subRes2->assertOk();
        $this->assertSame('professional', $subRes2->json('plan.code'));

        // 6. Platform Admin modifies plan via PATCH /api/v1/admin/companies/{id} to Enterprise
        $patchRes = $this->actingAs($this->platformAdmin, 'sanctum')
            ->patchJson("/api/v1/admin/companies/{$companyId}", [
                'plan' => 'Enterprise',
            ]);
        $patchRes->assertOk();

        // 7. Verify Company API reflects Enterprise and zero invoices
        $this->assertSame(0, BillingInvoice::where('company_id', $companyId)->count());
        $subRes3 = $this->actingAs($adminUser, 'sanctum')->getJson('/api/v1/company/me/subscription');
        $subRes3->assertOk();
        $this->assertSame('enterprise', $subRes3->json('plan.code'));

        // Verify legacy projection reflects Enterprise
        $this->assertSame('باقة مؤسسات', $legacySub->fresh()->plan);
    }

    public function test_upgrade_fails_closed_with_422_and_rolls_back_when_plan_missing_from_catalog(): void
    {
        $createRes = $this->actingAs($this->platformAdmin, 'sanctum')->postJson('/api/v1/admin/companies', [
            'name' => 'Rollback Upgrade Co',
            'plan' => 'Basic',
            'billingCycle' => 'monthly',
            'contactEmail' => 'owner@rollback-upgrade.test',
        ]);
        $createRes->assertCreated();
        $companyId = $createRes->json('id');

        // Delete Enterprise plan from catalog
        $this->enterprisePlan->delete();

        // Attempt upgrade to missing Enterprise plan
        $res = $this->actingAs($this->platformAdmin, 'sanctum')
            ->postJson("/api/v1/admin/companies/{$companyId}/upgrade", [
                'plan' => 'Enterprise',
            ]);

        $res->assertStatus(422);
        $this->assertSame('VALIDATION_ERROR', $res->json('error.code'));
        $this->assertArrayHasKey('plan', $res->json('error.details'));

        // Entire transaction must be rolled back: company plan and subscription planId remain Basic
        $this->assertDatabaseHas('asab_companies', [
            'id' => $companyId,
            'plan' => 'Basic',
        ]);
        $authSub = CompanySubscription::where('company_id', $companyId)->first();
        $this->assertSame($this->basicPlan->id, $authSub->plan_id);
    }

    public function test_patch_company_fails_closed_with_422_and_rolls_back_when_plan_missing_from_catalog(): void
    {
        $createRes = $this->actingAs($this->platformAdmin, 'sanctum')->postJson('/api/v1/admin/companies', [
            'name' => 'Rollback Patch Co',
            'plan' => 'Basic',
            'billingCycle' => 'monthly',
            'contactEmail' => 'owner@rollback-patch.test',
        ]);
        $createRes->assertCreated();
        $companyId = $createRes->json('id');

        // Delete Enterprise plan from catalog
        $this->enterprisePlan->delete();

        // Attempt PATCH with missing Enterprise plan
        $res = $this->actingAs($this->platformAdmin, 'sanctum')
            ->patchJson("/api/v1/admin/companies/{$companyId}", [
                'plan' => 'Enterprise',
            ]);

        $res->assertStatus(422);
        $this->assertSame('VALIDATION_ERROR', $res->json('error.code'));
        $this->assertArrayHasKey('plan', $res->json('error.details'));

        // Entire transaction must be rolled back
        $this->assertDatabaseHas('asab_companies', [
            'id' => $companyId,
            'plan' => 'Basic',
        ]);
        $authSub = CompanySubscription::where('company_id', $companyId)->first();
        $this->assertSame($this->basicPlan->id, $authSub->plan_id);
    }

    public function test_annual_subscription_plan_modification_preserves_billing_cycle_and_lifecycle_without_invoices(): void
    {
        // 1. Provision annual company with Basic plan
        $createRes = $this->actingAs($this->platformAdmin, 'sanctum')->postJson('/api/v1/admin/companies', [
            'name' => 'Annual Lifecycle Gourmet Co',
            'plan' => 'Basic',
            'billingCycle' => 'annual',
            'contactEmail' => 'admin@annual-lifecycle.test',
            'contactName' => 'Owner Annual Lifecycle',
        ]);
        $createRes->assertCreated();
        $companyId = $createRes->json('id');

        $adminUser = AsabUser::where('email', 'admin@annual-lifecycle.test')->firstOrFail();

        $authSub = CompanySubscription::where('company_id', $companyId)->firstOrFail();
        $legacySub = AsabSubscription::where('company_id', $companyId)->firstOrFail();

        $originalPeriodEnd = $authSub->current_period_end->toIso8601String();
        $originalExpiresAt = $legacySub->expires_at->toIso8601String();
        $originalAutoRenew = $authSub->auto_renew;

        $this->assertSame('annual', $authSub->billing_cycle);
        $this->assertSame(365, $legacySub->days_left);

        // 2. Platform Admin upgrades to Professional
        $upgradeRes = $this->actingAs($this->platformAdmin, 'sanctum')
            ->postJson("/api/v1/admin/companies/{$companyId}/upgrade", [
                'plan' => 'Professional',
            ]);
        $upgradeRes->assertOk();

        // 3. Assert lifecycle fields are strictly preserved
        $freshAuth = $authSub->fresh();
        $freshLegacy = $legacySub->fresh();

        $this->assertSame($this->proPlan->id, $freshAuth->plan_id);
        $this->assertSame('annual', $freshAuth->billing_cycle); // PRESERVED!
        $this->assertSame($originalPeriodEnd, $freshAuth->current_period_end->toIso8601String()); // PRESERVED!
        $this->assertSame($originalAutoRenew, $freshAuth->auto_renew); // PRESERVED!

        $this->assertSame('باقة متقدمة', $freshLegacy->plan);
        $this->assertSame(59900, $freshLegacy->monthly_price);
        $this->assertSame($originalExpiresAt, $freshLegacy->expires_at->toIso8601String()); // PRESERVED!
        $this->assertSame(365, $freshLegacy->days_left); // PRESERVED!

        // 4. Zero billing invoices created
        $this->assertSame(0, BillingInvoice::where('company_id', $companyId)->count());

        // 5. Company API confirms annual cycle and new plan
        $companyRes = $this->actingAs($adminUser, 'sanctum')->getJson('/api/v1/company/me/subscription');
        $companyRes->assertOk();
        $this->assertSame('professional', $companyRes->json('plan.code'));
        $this->assertSame('annual', $companyRes->json('subscription.billingCycle'));
        $this->assertSame($originalPeriodEnd, $companyRes->json('subscription.currentPeriodEnd'));
    }

    public function test_legacy_only_company_fails_closed_without_fabricating_subscription_lifecycle(): void
    {
        // Company exists with legacy AsabSubscription ONLY (no CompanySubscription)
        $company = AsabCompany::create([
            'name' => 'Legacy Only Annual Co',
            'plan' => 'Basic',
            'status' => 'active',
        ]);

        // Annual subscription nearing end: < 35 days remaining, auto_renew=false, reminder_enabled=true
        $legacySub = AsabSubscription::create([
            'company_id' => $company->id,
            'plan' => 'باقة أساسية',
            'status' => 'active',
            'expires_at' => now()->addDays(20),
            'days_left' => 20,
            'monthly_price' => 29900,
            'auto_renew' => false,
            'reminder_enabled' => true,
        ]);

        $this->assertSame(0, CompanySubscription::where('company_id', $company->id)->count());

        // Platform Admin attempts to upgrade
        $res = $this->actingAs($this->platformAdmin, 'sanctum')
            ->postJson("/api/v1/admin/companies/{$company->id}/upgrade", [
                'plan' => 'Professional',
            ]);

        // Must fail closed with 422: cannot modify absent authoritative subscription from incomplete legacy data
        $res->assertStatus(422);
        $this->assertSame('VALIDATION_ERROR', $res->json('error.code'));
        $this->assertArrayHasKey('plan', $res->json('error.details'));

        // Zero mutation: company plan unchanged, no fake CompanySubscription fabricated
        $this->assertSame('Basic', $company->fresh()->plan);
        $this->assertSame(0, CompanySubscription::where('company_id', $company->id)->count());

        // Legacy subscription remains completely untouched
        $freshLegacy = $legacySub->fresh();
        $this->assertSame('باقة أساسية', $freshLegacy->plan);
        $this->assertFalse($freshLegacy->auto_renew);
        $this->assertTrue($freshLegacy->reminder_enabled);
        $this->assertSame(20, $freshLegacy->days_left);
    }

    public function test_plan_modification_rejects_inactive_catalog_plan_without_mutation(): void
    {
        $createRes = $this->actingAs($this->platformAdmin, 'sanctum')->postJson('/api/v1/admin/companies', [
            'name' => 'Inactive Plan Test Co',
            'plan' => 'Basic',
            'billingCycle' => 'monthly',
            'contactEmail' => 'admin@inactive-plan.test',
        ]);
        $createRes->assertCreated();
        $companyId = $createRes->json('id');

        // Deactivate Professional plan in catalog
        $this->proPlan->update(['status' => 'inactive']);

        // Attempt upgrade to inactive plan
        $res = $this->actingAs($this->platformAdmin, 'sanctum')
            ->postJson("/api/v1/admin/companies/{$companyId}/upgrade", [
                'plan' => 'Professional',
            ]);

        $res->assertStatus(422);
        $this->assertSame('VALIDATION_ERROR', $res->json('error.code'));
        $this->assertArrayHasKey('plan', $res->json('error.details'));

        // Zero mutation
        $this->assertDatabaseHas('asab_companies', [
            'id' => $companyId,
            'plan' => 'Basic',
        ]);
        $authSub = CompanySubscription::where('company_id', $companyId)->first();
        $this->assertSame($this->basicPlan->id, $authSub->plan_id);
    }
}
